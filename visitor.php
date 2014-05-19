<?php

function show_usage() {
  global $argv;
  print "Usage: \n";
  print $argv[0] . " [-f -u] <url>\n";
  print "  -f: String to output whenever a new url is collected. \n";
  print "    Available variables: %code, %content_type, %headers:XYZ\n";
  print "  -u: Authentication credentials, <user>:<pass>\n";
}

function http_request($url, $options = array()) {
  if (!extension_loaded('curl')) {
    die('CURL library must be installed to download files.');
  }

  $options += array(
    'auth' => FALSE,
    'user-agent' => 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.13) Gecko/20080311 Firefox/2.0.0.13',
    'max-redirects' => 15,
    'method' => 'GET',
  );

  $method = strtoupper($options['method']);

  $ch = curl_init();
  curl_setopt($ch, CURLOPT_HEADER, TRUE);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
  curl_setopt($ch, CURLOPT_MAXREDIRS, $options['max-redirects']);
  curl_setopt($ch, CURLOPT_USERAGENT, $options['user-agent']);

  if ($options['auth']) {
    curl_setopt($ch, CURLOPT_USERPWD, $options['auth']);
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
  }

  if ($method != 'GET' && $method != 'POST') {
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
  }

  $data = curl_exec($ch);
  $headers_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
  $redirect_count = curl_getinfo($ch, CURLINFO_REDIRECT_COUNT);
  curl_close($ch);

  $headers_string = substr($data, 0, $headers_size);
  $data = substr($data, $headers_size);
  $headers = http_request_parse_headers($headers_string);

  $result = array();
  $result['error'] = '';
  $result['data'] = $data;
  $result['code'] = $code;
  $result['content_type'] = $content_type;
  $result['headers'] = $headers;

  if ($redirect_count == $options['max-redirects']) {
    $result['error'] = 'infinite-loop';
  }

  return $result;
}

function http_request_parse_headers($headers_string) {
  $default_headers = array('location' => '');
  $headers = $default_headers;
  $lines = preg_split('/\r\n/', trim($headers_string));
  $first = array_shift($lines);
  foreach ($lines as $line) {
    if (preg_match('/^(.*?): (.*)/', $line, $matches)) {
      $header_name = strtolower($matches[1]);
      $header_val = $matches[2];
      $headers[$header_name] = $header_val;
    }
  }

  if (!isset($headers['status'])) {
    $headers['status'] = $first;
  }

  return $headers;
}

function collect_urls($page_html, $page_url, $options = array()) {
  $options += array(
    'stay_in_domain' => TRUE,
    'tags' => array('a' => array('href')),
    'protocols' => array('http'),
  );

  $url_parsed = parse_url($page_url);
  $url_parsed += array('scheme' => 'http', 'path' => '');
  $url_base = dirname($url_parsed['path']);

  $domain = $url_parsed['scheme'] . '://' . $url_parsed['host'];

  // List of collected urls.
  $result = array();

  // Parse the html document.
  $dom = new DOMDocument();
  libxml_use_internal_errors(TRUE);

  $dom_loaded = $dom->loadHTML($page_html);

  if ($dom_loaded === FALSE) {
    return array();
  }

  $xpath = new DOMXpath($dom);

  // Traverse the document via xpath.
  foreach ($options['tags'] as $tag => $attrs) {
    $nodes = $xpath->query('//' . $tag);

    foreach ($nodes as $node) {
      foreach ($attrs as $attr) {
        $value = $node->getAttribute($attr);
        $orig_value = $value;

        if (empty($value)) {
          continue;
        }

        if ($value[0] == '#') {
          continue;
        }

        // Handle protocol-relative urls.
        if (substr($value, 0, 2) == '//') {
          $value = $url_parsed['scheme'] . ':' . $value;
        }

        // Handle root-relative.
        if ($value[0] == '/') {
          $value = $domain . $value;
        }

        $value_parsed = parse_url($value);
        $value_parsed += array('path' => '');

        // Other kind of relative urls.
        if (!isset($value_parsed['scheme']) && !isset($value_parsed['host'])) {
          $value_parsed['scheme'] = $url_parsed['scheme'];
          $value_parsed['host'] = $url_parsed['host'];
          $value_parsed['path'] = resolve_relative_url($url_base, $value_parsed['path']);
        }

        if (!in_array($value_parsed['scheme'], $options['protocols'])) {
          continue;
        }

        $value_domain = $value_parsed['scheme'] . '://' . $value_parsed['host'];

        $value_assembled = assemble_url($value_parsed);

        $result[] = array('url' => $value_assembled, 'collect' => ($options['stay_in_domain'] && $value_domain == $domain));
      }
    }
  }

  // Prevent DOMDocument log memory leak.
  // http://stackoverflow.com/questions/8379829/domdocument-php-memory-leak
  unset($dom);
  libxml_use_internal_errors(FALSE);

  return $result;
}

function resolve_relative_url($base_path, $rel_path) {
  $base_path_parts = explode('/', $base_path);
  $rel_path_parts = explode('/', $rel_path);
  foreach ($rel_path_parts as $rel_part) {
    if ($rel_part == '.') {
      array_shift($rel_path_parts);
    }
    else if ($rel_part == '..') {
      array_pop($base_path_parts);
      array_shift($rel_path_parts);
    }
  }

  return join('/', array_merge($base_path_parts, $rel_path_parts));
}

function assemble_url($parsed) {
  $assembled = $parsed['scheme'] . '://' . rtrim($parsed['host'], '/') . '/' . ltrim($parsed['path'], '/');
  if (isset($parsed['query'])) {
    $assembled .= '?' . str_replace('&amp;', '&', $parsed['query']);
  }

  return $assembled;
}

function format_url($format, $url, $data) {
  $result = $format;
  foreach ($data as $key => $value) {
    if (is_array($value)) {
      foreach ($value as $k => $v) {
        $result = str_replace('%' . $key . ':' . $k, $v, $result);
      }
    }
    else {
      $result = str_replace('%' . $key, $value, $result);
    }
  }

  return $result;
}

function preset_list() {
  $presets = array();
  $presets['health'] = array(
    'http' => array(),
    'collect' => array(
      'tags' => array(
        '*' => array('href', 'src'),
      ),
    ),
    'format' => '%url %code',
  );

  return $presets;
}

if (php_sapi_name() != 'cli') {
  die('No CLI, no party.');
}

$pcount = $argc - 1;
$options = getopt('u:f:p');
$params = array();

$presets = preset_list();
$preset_name = 'health';

foreach ($options as $opt => $value) {
  $pcount --;

  if ($value !== FALSE) {
    $pcount --;

    switch ($opt) {
      case 'f': $params['format'] = $value; break;
      case 'u': $params['auth'] = $value; break;
      case 'p': $preset_name = $value; break;
    }
  }
}

if ($pcount != 1) {
  show_usage();
  exit;
}

if (!isset($presets[$preset_name])) {
  print "Preset '$preset_name' was not found. Choose between: " . join(', ', array_keys($presets));
  print "\n";
  exit;
}

$params = array_merge($presets[$preset_name], $params);

// Start url is always the last parameter.
$start = $argv[$argc - 1];

$start_parsed = parse_url($start);
$start_parsed += array('scheme' => 'http', 'path' => '');

$root = $start_parsed['scheme'] . '://' . $start_parsed['host'];

$visited = array();
$queue = array();
$queue[] = array('url' => $start);

while (!empty($queue)) {
  $url_data = array_pop($queue);
  $url_data += array('parents' => array(), 'collect' => TRUE, 'referrer' => '');
  $url = $url_data['url'];

  if (isset($visited[$url])) {
    continue;
  }

  $response = http_request($url, array_merge($params['http'], array('auth' => $params['format'])));

  $visit = array();
  $visit['url'] = $url;
  $visit['parents'] = join(' --> ', $url_data['parents']);
  $visit['referrer'] = end($url_data['parents']);
  $visit += $response;

  $visited[$url] = $visit;

  print format_url($params['format'], $url, $visit);
  print "\n";

  $is_web_page = (strpos($response['content_type'], 'text/html') === 0);

  // Collect urls only if it was a successful response, a page containing html
  // and collection was requested.
  if ($response['code'] == 200 && $is_web_page && $url_data['collect']) {
    $urls = collect_urls($response['data'], $url, $params['collect']);

    $new_parents = array_merge($url_data['parents'], array($url));
    foreach ($urls as $collected) {
      $collected += array('parents' => $new_parents);
      $queue[] = $collected;
    }
  }
}
