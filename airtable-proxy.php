<?php
/**
 * Plugin Name: Airtable Proxy Shortcodes
 * Description: WordPress shortcodes for displaying Airtable plant data with archive grid and individual plant details.
 * Version: 1.0.0
 * Author: Custom Development
 * 
 * Features:
 * - Plant archive grid with pagination and filtering
 * - Individual plant detail shortcodes
 * - Automatic field type detection (text, images, audio, dates)
 * - Two-level caching (request + WordPress object cache)
 * - Responsive design with hover effects
 * - SEO-friendly URLs with separate detail pages
 * 
 * Available Shortcodes:
 * 
 * ARCHIVE:
 * - [plants_archive] - Display grid of plant cards with pagination and filtering
 * 
 * GENERAL FIELD SHORTCODES:
 * - [plant_field field="Plant Name (English)"] - Display any field with auto type detection
 * - [plant_image field="feature image"] - Display single image as <img> tag
 * - [plant_audio field="field_name"] - Display audio as <audio> tag
 * - [plant_images field="additional images"] - Display multiple images
 * - [plant_gallery field="additional images" columns="3"] - Display images in gallery grid
 * 
 * SPECIFIC FIELD SHORTCODES (convenience):
 * - [plant_name_en] - Plant English name
 * - [plant_name_latin] - Plant Latin name  
 * - [plant_name_halq] - Plant Halq'eméylem name and meaning
 * - [plant_origin] - Indigenous or Introduced/Niche or Zone
 * - [plant_uses] - Uses (Food, medicine, other uses)
 * - [plant_ecology] - Niche/Zone and Ecology
 * - [plant_feature_image] - Feature image
 * - [plant_additional_images] - Additional images
 * - [plant_last_modified] - Last Modified date
 * 
 * All shortcodes get plant ID from URL parameter 'id' if not specified.
 * Plant cards in the archive are clickable and navigate to same page with ?id=RECORD_ID
 */
if (!defined('ABSPATH')) exit;

// Load helper functions
require_once plugin_dir_path(__FILE__) . 'helpers.php';

// Register all shortcodes with WordPress
add_action('init', function () {
  // Main archive shortcode
  add_shortcode('plants_archive', 'ap_plants_archive_shortcode');
  
  // General field shortcodes (work with any field)
  add_shortcode('plant_field', 'ap_plant_field_shortcode');
  add_shortcode('plant_image', 'ap_plant_image_shortcode');
  add_shortcode('plant_audio', 'ap_plant_audio_shortcode');
  add_shortcode('plant_images', 'ap_plant_images_shortcode');
  add_shortcode('plant_gallery', 'ap_plant_gallery_shortcode');
  
  // Convenience shortcodes for specific fields
  add_shortcode('plant_name_en', 'ap_plant_name_en_shortcode');
  add_shortcode('plant_name_latin', 'ap_plant_name_latin_shortcode');
  add_shortcode('plant_name_halq', 'ap_plant_name_halq_shortcode');
  add_shortcode('plant_origin', 'ap_plant_origin_shortcode');
  add_shortcode('plant_uses', 'ap_plant_uses_shortcode');
  add_shortcode('plant_ecology', 'ap_plant_ecology_shortcode');
  add_shortcode('plant_feature_image', 'ap_plant_feature_image_shortcode');
  add_shortcode('plant_additional_images', 'ap_plant_additional_images_shortcode');
  add_shortcode('plant_last_modified', 'ap_plant_last_modified_shortcode');
});



/**
 * Fetch plants data for cards display
 * 
 * @param array $args Query arguments
 * @return array|WP_Error Plants data or error
 */
function ap_fetch_plants_cards($args = []) {
  // Default arguments
  $defaults = [
    'page_size' => 12,
    'cursor' => '',
    'search' => null,
    'uses' => [],
    'origin' => [],
    'niche' => [],
    'sort' => 'name_asc'
  ];
  
  $args = wp_parse_args($args, $defaults);
  
  // Validate and sanitize pageSize (1-100)
  $pageSize = max(1, min(100, $args['page_size']));
  
  // Get cursor for pagination
  $offset = $args['cursor'];
  
  // Build search query if provided
  $search = $args['search'];
  if ($search) {
    $search = trim(substr($search, 0, 100));
    $search = strtolower($search);
  }
  
  // Process filter parameters (already arrays from shortcode)
  $usesTerms = !empty($args['uses']) ? array_filter(array_map('trim', array_map('strtolower', (array)$args['uses']))) : [];
  $originTerms = !empty($args['origin']) ? array_filter(array_map('trim', array_map('strtolower', (array)$args['origin']))) : [];
  $nicheTerms = !empty($args['niche']) ? array_filter(array_map('trim', array_map('strtolower', (array)$args['niche']))) : [];
  
  // Parse sort parameter
  $sort = $args['sort'];
  $sortMappings = [
    'name_asc' => ['field' => 'Plant Name (English)', 'direction' => 'asc'],
    'name_desc' => ['field' => 'Plant Name (English)', 'direction' => 'desc'],
    'latin_asc' => ['field' => 'Plant Name (Latin)', 'direction' => 'asc'],
    'latin_desc' => ['field' => 'Plant Name (Latin)', 'direction' => 'desc'],
    'halq_asc' => ['field' => 'Plant Name (Halq\'eméylem) and Meaning', 'direction' => 'asc'],
    'halq_desc' => ['field' => 'Plant Name (Halq\'eméylem) and Meaning', 'direction' => 'desc'],
    'updated_asc' => ['field' => 'Updated At', 'direction' => 'asc'],
    'updated_desc' => ['field' => 'Updated At', 'direction' => 'desc'],
  ];
  
  $sortConfig = $sortMappings[$sort] ?? $sortMappings['name_asc'];
  
  // Build Airtable filter formula
  $formulaParts = [];
  
  // Add search filter (OR across name fields)
  if ($search) {
    $search = str_replace("'", "\\'", $search); // escape quotes
    $searchParts = [
      "SEARCH('{$search}', LOWER({Plant Name (English)}))",
      "SEARCH('{$search}', LOWER({Plant Name (Halq'eméylem) and Meaning}))", 
      "SEARCH('{$search}', LOWER({Plant Name (Latin)}))"
    ];
    $formulaParts[] = "OR(" . implode(',', $searchParts) . ")";
  }
  
  // Add filter terms (OR logic within each filter type)
  if (!empty($usesTerms)) {
    $usesParts = array_map(function($term) {
      $t = str_replace("'", "\\'", $term); // escape quotes
      return "SEARCH('{$t}', LOWER({Uses (Food, medicine, other uses)}))";
    }, $usesTerms);
    $formulaParts[] = "OR(" . implode(',', $usesParts) . ")";
  }
  
  if (!empty($originTerms)) {
    $originParts = array_map(function($term) {
      $t = str_replace("'", "\\'", $term); // escape quotes
      return "SEARCH('{$t}', LOWER({Indigenous or Introduced/Niche or Zone}))";
    }, $originTerms);
    $formulaParts[] = "OR(" . implode(',', $originParts) . ")";
  }
  
  if (!empty($nicheTerms)) {
    $nicheParts = array_map(function($term) {
      $t = str_replace("'", "\\'", $term); // escape quotes
      return "SEARCH('{$t}', LOWER({Niche/Zone and Ecology}))";
    }, $nicheTerms);
    $formulaParts[] = "OR(" . implode(',', $nicheParts) . ")";
  }
  
  // Combine all filters with AND logic
  $filterFormula = !empty($formulaParts) ? "AND(" . implode(',', $formulaParts) . ")" : null;
  
  // Build request parameters
  $params = [
    'pageSize' => $pageSize,
    'offset' => $offset,
    'sort_field' => $sortConfig['field'],
    'sort_dir' => $sortConfig['direction'],
  ];
  
  if ($filterFormula) {
    $params['filterByFormula'] = $filterFormula;
  }
  
  // Make the Airtable request
  $airtable_response = ap_airtable_request($params);
  
  // Handle errors
  if (is_wp_error($airtable_response)) {
    return $airtable_response;
  }
  
  // Process Airtable response for archive grid display
  $archive_plants = [];
  
  if (isset($airtable_response['records']) && is_array($airtable_response['records'])) {
    foreach ($airtable_response['records'] as $record) {
      if (!isset($record['fields'])) {
        continue;
      }
      
      // Map Airtable field IDs to readable field names
      $mapped_fields = ap_map_fields($record['fields']);
      
      // Process feature image (get first image URL for card display)
      $feature_image = null;
      if (isset($mapped_fields['feature image'])) {
        $feature_images = ap_norm_attachments($mapped_fields['feature image']);
        $feature_image = !empty($feature_images) ? $feature_images[0]['url'] : null;
      }
      
      // Process soundbite audio (get first audio URL for card display)
      $soundbite = null;
      if (isset($mapped_fields['soundbite_halq'])) {
        $soundbites = ap_norm_attachments($mapped_fields['soundbite_halq']);
        $soundbite = !empty($soundbites) ? $soundbites[0]['url'] : null;
      }
      
      // Build optimized plant object for archive cards
      $plant = [
        'id' => $record['id'],
        'name_en' => $mapped_fields['Plant Name (English)'] ?? null,
        'name_latin' => $mapped_fields['Plant Name (Latin)'] ?? null,
        'name_halq' => $mapped_fields['Plant Name (Halq\'eméylem) and Meaning'] ?? null,
        'feature_image' => $feature_image,
        'soundbite' => $soundbite,
      ];
      
      $archive_plants[] = $plant;
    }
  }
  
  // Build response
  $response = [
    'success' => true,
    'plants' => $archive_plants,
    'next_cursor' => $airtable_response['offset'] ?? null,
    'has_more' => !empty($airtable_response['offset']),
    'count' => count($archive_plants)
  ];
  
  return $response;
}

/**
 * Plants archive shortcode
 * 
 * @param array $atts Shortcode attributes
 * @return string HTML output
 */
function ap_plants_archive_shortcode($atts) {
  // Parse shortcode attributes
  $atts = shortcode_atts([
    'page_size' => 12,
    'search' => null,
    'uses' => null,
    'origin' => null,
    'niche' => null,
    'sort' => 'name_asc'
  ], $atts);
  
  // Get filters from URL parameters (overrides shortcode attributes)
  $search = $_GET['search'] ?? $atts['search'];
  $uses = $_GET['uses'] ?? $atts['uses'];
  $origin = $_GET['origin'] ?? $atts['origin'];
  $niche = $_GET['niche'] ?? $atts['niche'];
  $sort = $_GET['sort'] ?? $atts['sort'];
  
  // Handle trail-based pagination
  $trail = isset($_GET['trail']) ? explode(',', sanitize_text_field($_GET['trail'])) : [''];
  // page 1 uses empty string as the "cursor before page 1"
  $trail = array_values(array_filter($trail, fn($v) => $v !== '')) ?: [''];
  
  // the cursor you pass to fetch current page is the *last* item in the trail
  $currentCursor = end($trail) ?: '';
  
  // Build query arguments
  $queryArgs = [
    'page_size' => (int)$atts['page_size'],
    'cursor' => $currentCursor,
    'search' => $search,
    'uses' => is_array($uses) ? $uses : (empty($uses) ? [] : [$uses]),
    'origin' => is_array($origin) ? $origin : (empty($origin) ? [] : [$origin]),
    'niche' => is_array($niche) ? $niche : (empty($niche) ? [] : [$niche]),
    'sort' => $sort
  ];
  
  // Fetch plants data
  $result = ap_fetch_plants_cards($queryArgs);
  
  // Handle errors
  if (is_wp_error($result)) {
    return '<div class="plants-error">Error loading plants: ' . esc_html($result->get_error_message()) . '</div>';
  }
  
  // Start output buffering
  ob_start();
  
  // Render plant cards grid
  if (!empty($result['plants'])) {
    // Add CSS for interactive hover effects
    echo '<style>
      .plant-card-link:hover .plant-card {
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        transform: translateY(-2px);
      }
      .plant-card-link {
        display: block;
      }
    </style>';
    
    // Create responsive grid container
    echo '<div class="plants-archive" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 20px; margin: 20px 0;">';
    
    foreach ($result['plants'] as $plant) {
      // Generate URL for plant detail page
      $detail_page_slug = 'plant-details'; // Configure this to match your detail page slug
      $detail_page = get_page_by_path($detail_page_slug);
      
      if ($detail_page) {
        // Link to separate detail page
        $plant_url = add_query_arg(['id' => $plant['id']], get_permalink($detail_page->ID));
      } else {
        // Fallback to same page if detail page not found
        $plant_url = add_query_arg(['id' => $plant['id']], get_permalink());
      }
      
      echo '<a href="' . esc_url($plant_url) . '" class="plant-card-link" style="text-decoration: none; color: inherit;">';
      echo '<div class="plant-card" style="border: 1px solid #ddd; border-radius: 8px; padding: 15px; background: #fff; cursor: pointer; transition: box-shadow 0.2s ease;">';
      
      // Feature image
      if ($plant['feature_image']) {
        echo '<div class="plant-image" style="margin-bottom: 10px;">';
        echo '<img src="' . esc_url($plant['feature_image']) . '" alt="' . esc_attr($plant['name_en']) . '" style="width: 100%; height: 150px; object-fit: cover; border-radius: 4px;">';
        echo '</div>';
      } else {
        echo '<div class="plant-image-placeholder" style="width: 100%; height: 150px; background: #f0f0f0; border-radius: 4px; display: flex; align-items: center; justify-content: center; color: #999; margin-bottom: 10px;">No Image</div>';
      }
      
      // Plant names
      echo '<div class="plant-names">';
      if ($plant['name_en']) {
        echo '<h3 style="margin: 0 0 5px 0; font-size: 16px; font-weight: bold;">' . esc_html($plant['name_en']) . '</h3>';
      }
      if ($plant['name_latin']) {
        echo '<p style="margin: 0 0 5px 0; font-style: italic; color: #666; font-size: 14px;">' . esc_html($plant['name_latin']) . '</p>';
      }
      if ($plant['name_halq']) {
        echo '<p style="margin: 0; color: #888; font-size: 13px;">' . esc_html($plant['name_halq']) . '</p>';
      }
      echo '</div>';
      
      // Soundbite
      if ($plant['soundbite']) {
        echo '<div class="plant-soundbite" style="margin-top: 10px;">';
        echo '<audio controls style="width: 100%;">';
        echo '<source src="' . esc_url($plant['soundbite']) . '" type="audio/mpeg">';
        echo 'Your browser does not support the audio element.';
        echo '</audio>';
        echo '</div>';
      }
      
      // Hidden record ID for debugging/development
      echo '<div style="display: none;" class="plant-record-id">' . esc_html($plant['id']) . '</div>';
      
      echo '</div>';
      echo '</a>';
    }
    
    echo '</div>';
    
    // Pagination
    echo '<div class="plants-pagination" style="margin: 20px 0; text-align: center;">';
    
    // Preserve existing filters in pagination links
    $base = $_GET;
    unset($base['cursor'], $base['trail']); // don't carry old pagination tokens
    
    // build PREV: drop last cursor (if more than the initial '')
    if (count($trail) > 1) {
      $prevTrail = implode(',', array_slice($trail, 0, -1));
      $prevUrl = esc_url(add_query_arg(array_merge($base, ['trail' => $prevTrail]), get_permalink()));
      echo '<a href="' . $prevUrl . '" style="margin-right: 10px; padding: 8px 16px; background: #007cba; color: white; text-decoration: none; border-radius: 4px;">← Previous</a>';
    }
    
    // build NEXT: append next_cursor
    if (!empty($result['next_cursor'])) {
      $nextTrail = implode(',', array_merge($trail, [$result['next_cursor']]));
      $nextUrl = esc_url(add_query_arg(array_merge($base, ['trail' => $nextTrail]), get_permalink()));
      echo '<a href="' . $nextUrl . '" style="padding: 8px 16px; background: #007cba; color: white; text-decoration: none; border-radius: 4px;">Next →</a>';
    }
    
    echo '</div>';
    
  } else {
    echo '<div class="plants-no-results" style="text-align: center; padding: 40px; color: #666;">No plants found matching your criteria.</div>';
  }
  
  return ob_get_clean();
}


/**
 * Fetch individual plant record by ID
 * 
 * @param string $id Plant record ID
 * @param string $attachments How to handle attachments ('url' for URL strings, 'object' for full objects)
 * @return array|WP_Error Plant data or error
 */
function ap_fetch_plant_by_id($id, $attachments = 'url') {
  if (empty($id)) {
    return new WP_Error('invalid_id', 'Plant ID is required');
  }
  
  // Build Airtable API request parameters
  $params = [
    'pageSize' => 1,
    'offset' => '',
    'filterByFormula' => "RECORD_ID() = '{$id}'"
  ];
  
  // Make the Airtable API request
  $airtable_response = ap_airtable_request($params);
  
  // Handle API errors
  if (is_wp_error($airtable_response)) {
    return $airtable_response;
  }
  
  // Check if record found
  if (empty($airtable_response['records']) || !is_array($airtable_response['records'])) {
    return new WP_Error('plant_not_found', 'Plant record not found');
  }
  
  $record = $airtable_response['records'][0];
  if (!isset($record['fields'])) {
    return new WP_Error('invalid_record', 'Invalid plant record');
  }
  
  // Map Airtable field IDs to readable field names
  $mapped_fields = ap_map_fields($record['fields']);
  
  // Process attachments based on requested format
  if ($attachments === 'object') {
    // Return full attachment objects for advanced usage
    if (isset($mapped_fields['feature image'])) {
      $mapped_fields['feature image'] = ap_norm_attachments($mapped_fields['feature image']);
    }
    if (isset($mapped_fields['soundbite_halq'])) {
      $mapped_fields['soundbite_halq'] = ap_norm_attachments($mapped_fields['soundbite_halq']);
    }
  } else {
    // Return URL strings (default for shortcodes)
    if (isset($mapped_fields['feature image'])) {
      $feature_images = ap_norm_attachments($mapped_fields['feature image']);
      $mapped_fields['feature image'] = !empty($feature_images) ? $feature_images[0]['url'] : null;
    }
    if (isset($mapped_fields['soundbite_halq'])) {
      $soundbites = ap_norm_attachments($mapped_fields['soundbite_halq']);
      $mapped_fields['soundbite_halq'] = !empty($soundbites) ? $soundbites[0]['url'] : null;
    }
  }
  
  // Add record ID for reference
  $mapped_fields['id'] = $record['id'];
  
  return $mapped_fields;
}

/**
 * Fetch plant record with caching (once per request + optional WP cache)
 * 
 * @param string $id Plant record ID
 * @param string $attachments How to handle attachments
 * @return array|WP_Error Plant data or error
 */
function ap_get_plant_cached($id, $attachments = 'url') {
  static $mem = []; // Request-level memory cache
  $key = $id . '|' . $attachments;

  // Check request-level cache first (fastest)
  if (isset($mem[$key])) {
    return $mem[$key];
  }

  // Check WordPress object cache (60s TTL)
  $cached = wp_cache_get($key, 'ap_plants');
  if ($cached !== false) {
    $mem[$key] = $cached;
    return $cached;
  }

  // Fetch from Airtable if not cached
  $plant = ap_fetch_plant_by_id($id, $attachments);
  if (!is_wp_error($plant)) {
    $mem[$key] = $plant;
    wp_cache_set($key, $plant, 'ap_plants', 60); // 60s TTL
  }
  
  return $plant;
}

/**
 * Plant field shortcode - displays any field with automatic type detection
 * 
 * Usage: [plant_field field="Plant Name (English)" id="rec123"] or [plant_field field="Plant Name (English)"] (gets id from URL)
 * 
 * @param array $atts Shortcode attributes
 * @return string Field value or empty string
 */
function ap_plant_field_shortcode($atts) {
  $a = shortcode_atts([
    'id' => '',
    'field' => '',
    'default' => '',
    'format' => 'html', // html, text, url
    'attachments' => 'url'
  ], $atts);
  
  // Get plant ID from shortcode attribute or URL parameter
  $id = $a['id'] ?: sanitize_text_field($_GET['id'] ?? '');
  
  if (!$id || !$a['field']) {
    return esc_html($a['default']);
  }

  // Fetch plant data with caching
  $plant = ap_get_plant_cached($id, $a['attachments']);
  if (is_wp_error($plant)) {
    return esc_html($a['default']);
  }
  
  // Get field value or return default
  $val = $plant[$a['field']] ?? $a['default'];
  if (empty($val)) {
    return esc_html($a['default']);
  }

  // Format and return the field value
  return ap_format_field_value($a['field'], $val, $a['format']);
}

/**
 * Plant image shortcode - displays image fields as <img> tags
 * 
 * Usage: [plant_image field="feature_image" id="rec123"] or [plant_image field="feature_image"] (gets id from URL)
 * 
 * @param array $atts Shortcode attributes
 * @return string Image HTML or empty string
 */
function ap_plant_image_shortcode($atts) {
  $a = shortcode_atts([
    'id' => '',
    'field' => 'feature_image',
    'class' => '',
    'alt' => '',
    'attachments' => 'url'
  ], $atts);
  
  $id = $a['id'] ?: sanitize_text_field($_GET['id'] ?? '');
  if (!$id) {
    return '';
  }

  $plant = ap_get_plant_cached($id, $a['attachments']);
  if (is_wp_error($plant)) {
    return '';
  }

  $val = $plant[$a['field']] ?? '';
  $url = is_array($val) ? ($val['url'] ?? '') : $val;
  if (!$url) {
    return '';
  }

  $alt = $a['alt'] ?: ($plant['name_en'] ?? '');
  return '<img src="' . esc_url($url) . '" alt="' . esc_attr($alt) . '" class="' . esc_attr($a['class']) . '">';
}

/**
 * Plant audio shortcode - displays audio fields as <audio> tags
 * 
 * Usage: [plant_audio field="soundbite_halq" id="rec123"] or [plant_audio field="soundbite_halq"] (gets id from URL)
 * 
 * @param array $atts Shortcode attributes
 * @return string Audio HTML or empty string
 */
function ap_plant_audio_shortcode($atts) {
  $a = shortcode_atts([
    'id' => '',
    'field' => 'soundbite_halq',
    'class' => '',
    'controls' => 'true',
    'attachments' => 'url'
  ], $atts);
  
  $id = $a['id'] ?: sanitize_text_field($_GET['id'] ?? '');
  if (!$id) {
    return '';
  }

  $plant = ap_get_plant_cached($id, $a['attachments']);
  if (is_wp_error($plant)) {
    return '';
  }

  $val = $plant[$a['field']] ?? '';
  $url = is_array($val) ? ($val['url'] ?? '') : $val;
  if (!$url) {
    return '';
  }

  $controls = $a['controls'] === 'true' ? ' controls' : '';
  $class = !empty($a['class']) ? ' class="' . esc_attr($a['class']) . '"' : '';
  
  return '<audio' . $class . $controls . '><source src="' . esc_url($url) . '" type="audio/mpeg">Your browser does not support the audio element.</audio>';
}

/**
 * Plant images shortcode - displays multiple images
 * 
 * Usage: [plant_images field="additional images" id="rec123"]
 * 
 * @param array $atts Shortcode attributes
 * @return string Images HTML or empty string
 */
function ap_plant_images_shortcode($atts) {
  $a = shortcode_atts([
    'id' => '',
    'field' => 'additional images',
    'class' => '',
    'size' => 'large', // small, large, full
    'attachments' => 'url'
  ], $atts);
  
  $id = $a['id'] ?: sanitize_text_field($_GET['id'] ?? '');
  if (!$id) {
    return '';
  }

  $plant = ap_get_plant_cached($id, $a['attachments']);
  if (is_wp_error($plant)) {
    return '';
  }

  $val = $plant[$a['field']] ?? '';
  $images = ap_get_field_images($val);
  if (empty($images)) {
    return '';
  }

  $output = '<div class="plant-images ' . esc_attr($a['class']) . '">';
  foreach ($images as $image) {
    $url = $image['url'];
    if ($a['size'] !== 'original' && isset($image['thumbnails'][$a['size']])) {
      $url = $image['thumbnails'][$a['size']]['url'];
    }
    
    $alt = $image['filename'] ?? $a['field'];
    $output .= '<img src="' . esc_url($url) . '" alt="' . esc_attr($alt) . '" class="plant-image">';
  }
  $output .= '</div>';
  
  return $output;
}

/**
 * Plant gallery shortcode - displays images in a gallery format
 * 
 * Usage: [plant_gallery field="additional images" id="rec123"]
 * 
 * @param array $atts Shortcode attributes
 * @return string Gallery HTML or empty string
 */
function ap_plant_gallery_shortcode($atts) {
  $a = shortcode_atts([
    'id' => '',
    'field' => 'additional images',
    'class' => '',
    'columns' => 3,
    'size' => 'large',
    'attachments' => 'url'
  ], $atts);
  
  $id = $a['id'] ?: sanitize_text_field($_GET['id'] ?? '');
  if (!$id) {
    return '';
  }

  $plant = ap_get_plant_cached($id, $a['attachments']);
  if (is_wp_error($plant)) {
    return '';
  }

  $val = $plant[$a['field']] ?? '';
  $images = ap_get_field_images($val);
  if (empty($images)) {
    return '';
  }

  $columns = max(1, min(6, (int)$a['columns']));
  $output = '<div class="plant-gallery ' . esc_attr($a['class']) . '" style="display: grid; grid-template-columns: repeat(' . $columns . ', 1fr); gap: 10px;">';
  
  foreach ($images as $image) {
    $url = $image['url'];
    if ($a['size'] !== 'original' && isset($image['thumbnails'][$a['size']])) {
      $url = $image['thumbnails'][$a['size']]['url'];
    }
    
    $alt = $image['filename'] ?? $a['field'];
    $output .= '<div class="gallery-item">';
    $output .= '<img src="' . esc_url($url) . '" alt="' . esc_attr($alt) . '" class="plant-gallery-image" style="width: 100%; height: auto; border-radius: 4px;">';
    $output .= '</div>';
  }
  $output .= '</div>';
  
  return $output;
}

// Specific field shortcodes for convenience

/**
 * Plant English name shortcode
 */
function ap_plant_name_en_shortcode($atts) {
  $atts['field'] = 'Plant Name (English)';
  return ap_plant_field_shortcode($atts);
}

/**
 * Plant Latin name shortcode
 */
function ap_plant_name_latin_shortcode($atts) {
  $atts['field'] = 'Plant Name (Latin)';
  return ap_plant_field_shortcode($atts);
}

/**
 * Plant Halq'eméylem name shortcode
 */
function ap_plant_name_halq_shortcode($atts) {
  $atts['field'] = 'Plant Name (Halq\'eméylem) and Meaning';
  return ap_plant_field_shortcode($atts);
}

/**
 * Plant origin shortcode
 */
function ap_plant_origin_shortcode($atts) {
  $atts['field'] = 'Indigenous or Introduced/Niche or Zone';
  return ap_plant_field_shortcode($atts);
}

/**
 * Plant uses shortcode
 */
function ap_plant_uses_shortcode($atts) {
  $atts['field'] = 'Uses (Food, medicine, other uses)';
  return ap_plant_field_shortcode($atts);
}

/**
 * Plant ecology shortcode
 */
function ap_plant_ecology_shortcode($atts) {
  $atts['field'] = 'Niche/Zone and Ecology';
  return ap_plant_field_shortcode($atts);
}

/**
 * Plant feature image shortcode
 */
function ap_plant_feature_image_shortcode($atts) {
  $atts['field'] = 'feature image';
  return ap_plant_image_shortcode($atts);
}

/**
 * Plant additional images shortcode
 */
function ap_plant_additional_images_shortcode($atts) {
  $atts['field'] = 'additional images';
  return ap_plant_images_shortcode($atts);
}

/**
 * Plant last modified shortcode
 */
function ap_plant_last_modified_shortcode($atts) {
  $atts['field'] = 'Last Modified';
  return ap_plant_field_shortcode($atts);
}