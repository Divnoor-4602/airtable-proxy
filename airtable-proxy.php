<?php
/**
 * Plugin Name: Airtable Proxy API
 * Description: shortcodes for Elementor, backed by Airtable.
 * Version: 0.1.0
 */
if (!defined('ABSPATH')) exit;

// Load helper functions
require_once plugin_dir_path(__FILE__) . 'helpers.php';

add_action('init', function () {
  add_shortcode('plants_archive', 'ap_plants_archive_shortcode');
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
  
  // Process the response for archive grid
  $archive_plants = [];
  
  if (isset($airtable_response['records']) && is_array($airtable_response['records'])) {
    foreach ($airtable_response['records'] as $record) {
      if (!isset($record['fields'])) {
        continue;
      }
      
      $mapped_fields = ap_map_fields($record['fields']);
      
      // Process feature image (first object only)
      $feature_image = null;
      if (isset($mapped_fields['feature_image'])) {
        $feature_images = ap_norm_attachments($mapped_fields['feature_image']);
        $feature_image = !empty($feature_images) ? $feature_images[0]['url'] : null;
      }
      
      // Process soundbite (first object only) 
      $soundbite = null;
      if (isset($mapped_fields['soundbite_halq'])) {
        $soundbites = ap_norm_attachments($mapped_fields['soundbite_halq']);
        $soundbite = !empty($soundbites) ? $soundbites[0]['url'] : null;
      }
      
      // Build archive plant object with only required fields
      $plant = [
        'id' => $record['id'],
        'name_en' => $mapped_fields['name_en'] ?? null,
        'name_latin' => $mapped_fields['name_latin'] ?? null,
        'name_halq' => $mapped_fields['name_halq'] ?? null,
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
  
  // Render plant cards
  if (!empty($result['plants'])) {
    echo '<div class="plants-archive" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 20px; margin: 20px 0;">';
    
    foreach ($result['plants'] as $plant) {
      echo '<div class="plant-card" style="border: 1px solid #ddd; border-radius: 8px; padding: 15px; background: #fff;">';
      
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
      
      echo '</div>';
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
