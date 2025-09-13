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
