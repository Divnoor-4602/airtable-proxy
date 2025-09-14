<?php
/**
 * Helper functions for Airtable Proxy Plugin
 */

if (!defined('ABSPATH')) exit;

/**
 * Get the defined Airtable fields list
 */
function ap_get_airtable_fields_list(): array {
  return (defined('AIRTABLE_FIELDS') && is_array(AIRTABLE_FIELDS)) ? AIRTABLE_FIELDS : [];
}

/**
 * Make a request to Airtable API
 * 
 * @param array $params Query parameters
 * @return array|WP_Error Airtable response or error
 */
function ap_airtable_request(array $params) {
  // Build query parameters, filtering out null values
  $q = array_filter([
    'pageSize'         => $params['pageSize'] ?? null,
    'offset'           => $params['offset'] ?? null,
    'filterByFormula'  => $params['filterByFormula'] ?? null,
  ]);

  // Only add sort parameters if both field and direction are provided
  if (!empty($params['sort_field']) && !empty($params['sort_dir'])) {
    $q['sort[0][field]'] = $params['sort_field'];
    $q['sort[0][direction]'] = $params['sort_dir'];
  }

  // Add field ID preference if defined
  if (defined('AIRTABLE_RETURN_FIELDS_BY_ID') && AIRTABLE_RETURN_FIELDS_BY_ID) {
    $q['returnFieldsByFieldId'] = 'true';
  }

  // Build the URL with base parameters
  $url = 'https://api.airtable.com/v0/' . AIRTABLE_BASE . '/' . rawurlencode(AIRTABLE_TABLE) . '?' . http_build_query($q);
  
  // Add specific fields if defined
  $fields = ap_get_airtable_fields_list();
  if (!empty($fields)) {
    // Add each field as a separate fields[] parameter
    foreach ($fields as $field) {
      $url .= '&fields[]=' . urlencode($field);
    }
  }
  
  // Make the request
  $res = wp_remote_get($url, [
    'headers' => ['Authorization' => 'Bearer ' . AIRTABLE_TOKEN],
    'timeout' => 15,
  ]);
  
  if (is_wp_error($res)) {
    return $res;
  }
  
  $code = wp_remote_retrieve_response_code($res);
  $body = json_decode(wp_remote_retrieve_body($res), true);
  $headers = wp_remote_retrieve_headers($res);
  
  if ($code >= 400) {
    return new WP_Error('airtable_http', 'Airtable request failed', ['status' => $code, 'body' => $body]);
  }
  
  return $body;
}

/**
 * Map Airtable field IDs to readable keys
 * 
 * @param array $fields Raw field data from Airtable
 * @return array Mapped fields with readable keys
 */
function ap_map_fields(array $fields): array {
  $map = defined('AIRTABLE_FIELD_MAP') ? AIRTABLE_FIELD_MAP : [];
  $out = [];
  
  foreach ($map as $fid => $key) {
    $out[$key] = $fields[$fid] ?? null;
  }
  
  return $out;
}

/**
 * Normalize attachments to consistent format
 * 
 * @param mixed $val Attachment data (single object or array)
 * @return array Normalized attachments
 */
function ap_norm_attachments($val): array {
  if (!is_array($val)) {
    return [];
  }
  
  // Handle single attachment (associative array) vs multiple attachments (indexed array)
  $items = ap_is_assoc($val) ? [$val] : $val;
  $out = [];
  
  foreach ($items as $attachment) {
    if (!is_array($attachment) || empty($attachment['url'])) {
      continue;
    }
    
    $out[] = [
      'url'      => $attachment['url'],
      'filename' => $attachment['filename'] ?? null,
      'type'     => $attachment['type'] ?? null,
      'size'     => $attachment['size'] ?? null,
    ];
  }
  
  return $out;
}

/**
 * Check if array is associative (has non-numeric keys)
 * 
 * @param array $arr Array to check
 * @return bool True if associative, false if indexed
 */
function ap_is_assoc(array $arr): bool {
  if (empty($arr)) {
    return false;
  }
  
  return array_keys($arr) !== range(0, count($arr) - 1);
}

/**
 * Detect field type based on field name and value
 * 
 * @param string $field_name Field name
 * @param mixed $value Field value
 * @return string Field type
 */
function ap_detect_field_type($field_name, $value) {
  // Check for image fields
  if (strpos(strtolower($field_name), 'image') !== false || 
      strpos(strtolower($field_name), 'photo') !== false ||
      strpos(strtolower($field_name), 'picture') !== false) {
    return 'image';
  }
  
  // Check for audio fields
  if (strpos(strtolower($field_name), 'audio') !== false || 
      strpos(strtolower($field_name), 'sound') !== false ||
      strpos(strtolower($field_name), 'mp3') !== false) {
    return 'audio';
  }
  
  // Check if value is attachment array
  if (is_array($value) && !empty($value)) {
    $first_item = is_array($value) && !ap_is_assoc($value) ? $value[0] : $value;
    if (is_array($first_item) && isset($first_item['url'])) {
      // Check if it's audio or image based on type
      if (isset($first_item['type'])) {
        if (strpos($first_item['type'], 'audio') !== false) {
          return 'audio';
        } elseif (strpos($first_item['type'], 'image') !== false) {
          return 'image';
        }
      }
      return 'attachment';
    }
  }
  
  // Check for date fields
  if (strpos(strtolower($field_name), 'date') !== false || 
      strpos(strtolower($field_name), 'time') !== false ||
      strpos(strtolower($field_name), 'modified') !== false ||
      strpos(strtolower($field_name), 'created') !== false) {
    return 'date';
  }
  
  // Default to text
  return 'text';
}

/**
 * Format field value based on field type
 * 
 * @param string $field_name Field name
 * @param mixed $value Field value
 * @param string $format Output format ('html', 'text', 'url')
 * @return string Formatted value
 */
function ap_format_field_value($field_name, $value, $format = 'html') {
  if (empty($value)) {
    return '';
  }
  
  $field_type = ap_detect_field_type($field_name, $value);
  
  switch ($field_type) {
    case 'image':
      if (is_array($value)) {
        $attachments = ap_norm_attachments($value);
        if (empty($attachments)) {
          return '';
        }
        
        if ($format === 'url') {
          return $attachments[0]['url'];
        }
        
        $img = $attachments[0];
        $alt = $field_name;
        $style = 'max-width: 400px; width: 100%; height: auto; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);';
        return '<img src="' . esc_url($img['url']) . '" alt="' . esc_attr($alt) . '" class="plant-image" style="' . $style . '">';
      }
      return '';
      
    case 'audio':
      if (is_array($value)) {
        $attachments = ap_norm_attachments($value);
        if (empty($attachments)) {
          return '';
        }
        
        if ($format === 'url') {
          return $attachments[0]['url'];
        }
        
        $audio = $attachments[0];
        return '<audio controls class="plant-audio"><source src="' . esc_url($audio['url']) . '" type="audio/mpeg">Your browser does not support the audio element.</audio>';
      }
      return '';
      
    case 'date':
      if (is_string($value)) {
        $timestamp = strtotime($value);
        if ($timestamp !== false) {
          if ($format === 'text') {
            return date('F j, Y', $timestamp);
          }
          return '<time datetime="' . esc_attr($value) . '" class="plant-date">' . esc_html(date('F j, Y', $timestamp)) . '</time>';
        }
      }
      return esc_html($value);
      
    case 'text':
    default:
      if (is_array($value)) {
        return esc_html(implode(', ', $value));
      }
      return esc_html($value);
  }
}

/**
 * Get all images from a field (handles both single and multiple images)
 * 
 * @param mixed $value Field value
 * @return array Array of normalized attachments
 */
function ap_get_field_images($value) {
  if (empty($value)) {
    return [];
  }
  
  if (is_array($value)) {
    return ap_norm_attachments($value);
  }
  
  return [];
}

/**
 * Get all audio files from a field
 * 
 * @param mixed $value Field value
 * @return array Array of audio attachments
 */
function ap_get_field_audio($value) {
  if (empty($value)) {
    return [];
  }
  
  $attachments = ap_norm_attachments($value);
  $audio_files = [];
  
  foreach ($attachments as $attachment) {
    if (isset($attachment['type']) && strpos($attachment['type'], 'audio') !== false) {
      $audio_files[] = $attachment;
    }
  }
  
  return $audio_files;
}
