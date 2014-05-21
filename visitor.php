<?php

function show_usage() {
  global $argv;
  print "Usage: \n";
  print $argv[0] . " [-f -u] <url>\n";
  print "  -f: String to output whenever a new url is collected. \n";
  print "    Available variables: %code, %content_type, %headers:XYZ\n";
  print "  -u: Authentication credentials, <user>:<pass>\n";
  print "  -p: Preset name. Choose between: " . (join(', ', array_keys(preset_list()))) . "\n";
}

function http_request($url, $options = array()) {
  $options += array(
    'auth' => FALSE,
    'user_agent' => 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.13) Gecko/20080311 Firefox/2.0.0.13',
    'max_redirects' => 15,
    'method' => 'GET',
    'follow_redirects' => TRUE,
  );

  if (!$options['follow_redirects']) {
    $options['max_redirects'] = 0;
  }

  $redirects_count = 0;
  $redirects = array();
  $location = $url;
  while (($response = curl_http_request($location, $options)) && $response['is_redirect'] && ($redirects_count < $options['max_redirects'])) {
    $redirects[] = $response;
    $redirects_count++;
    $location = $response['headers']['location'];
  }

  $result = $response;
  $result['redirects'] = $redirects;
  $result['redirects_count'] = $redirects_count;
  $result['last_redirect'] = ($redirects_count > 0 ? $location : FALSE);

  if ($redirects_count > 0 && $redirects_count >= $options['max_redirects']) {
    $result['error'] = 'infinite-loop';
  }

  return $result;
}

function curl_http_request($url, $options = array()) {
  $method = strtoupper($options['method']);

  $ch = curl_init();
  curl_setopt($ch, CURLOPT_HEADER, TRUE);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);

  if ($method == 'HEAD') {
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_NOBODY, TRUE);
  }

  curl_setopt($ch, CURLOPT_USERAGENT, $options['user_agent']);

  if ($options['auth']) {
    curl_setopt($ch, CURLOPT_USERPWD, $options['auth']);
  }

  $data = curl_exec($ch);
  $headers_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
  curl_close($ch);

  $headers_string = substr($data, 0, $headers_size);
  $data = substr($data, $headers_size);
  $headers = http_request_parse_headers($headers_string);
  $is_redirect = (in_array($code, array(301, 302)));

  $result = array();
  $result['error'] = '';
  $result['data'] = $data;
  $result['code'] = $code;
  $result['content_type'] = $content_type;
  $result['headers'] = $headers;
  $result['is_redirect'] = $is_redirect;
  $result['url'] = $url;

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
  if (strlen($page_html) == 0) {
    return array();
  }

  $options += array(
    'allow_external' => TRUE,
    'tags' => array('a' => array('href')),
    'protocols' => array('http'),
  );

  $url_info = parse_url($page_url);
  $url_info += array('scheme' => 'http', 'path' => '');
  $url_root = $url_info['scheme'] . '://' . $url_info['host'];

  // List of collected urls.
  $result = array();
  $found = array();

  // Parse the html document.
  $dom = new DOMDocument();
  libxml_use_internal_errors(TRUE);

  // var_dump(strlen($page_html));

  // If unable to parse the html document, skip.
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
        $found[] = $node->getAttribute($attr);
      }
    }
  }

  foreach ($found as $value) {
    $orig_value = $value;

    if (empty($value) || $value[0] == '#') {
      continue;
    }

    $value_info = parse_relative_url($value, $url_info);

    if ($value_info === FALSE) {
      continue;
    }

    if (!in_array($value_info['scheme'], $options['protocols'])) {
      continue;
    }

    $value_assembled = assemble_url($value_info);

    $collect = $options['allow_external'] || ($value_info['host'] == $url_info['host']);

    $result[] = array('url' => $value_assembled, 'collect' => $collect);
  }

  // Prevents DOMDocument memory leaks caused by internal logs.
  // http://stackoverflow.com/questions/8379829/domdocument-php-memory-leak
  unset($dom);
  libxml_use_internal_errors(FALSE);

  return $result;
}

function parse_relative_url($url, $from_info) {
  $from_base = dirname($from_info['path']);
  $from_root = $from_info['scheme'] . '://' . $from_info['host'];

  // Handle protocol-relative urls.
  if (substr($url, 0, 2) == '//') {
    $url = $from_info['scheme'] . ':' . $url;
  }
  else if ($url[0] == '/') {
    // Handle root-relative.
    $url = $from_root . $url;
  }

  $url_info = parse_url($url);
  if ($url_info === FALSE) {
    return FALSE;
  }

  $url_info += array('path' => '');

  // Other kind of relative urls.
  if (!isset($url_info['scheme']) && !isset($url_info['host'])) {
    $url_info['scheme'] = $from_info['scheme'];
    $url_info['host'] = $from_info['host'];
    $url_info['path'] = resolve_relative_url($from_base, $url_info['path']);
  }

  return $url_info;
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
  $assembled = $parsed['scheme'] . '://' . rtrim($parsed['host'], '/\\') . '/' . ltrim($parsed['path'], '/\\');
  if (isset($parsed['query'])) {
    $assembled .= '?' . str_replace('&amp;', '&', $parsed['query']);
  }

  return $assembled;
}

function format_url($format, $data) {
  $result = $format;
  $replacements = array();
  if (preg_match_all('/%([^\s]+)/', $format, $matches)) {
    foreach ($matches[1] as $key) {
      if (isset($data[$key])) {
        $replacements[$key] = $data[$key];
      }
      else {
        $cur = $data;
        $target = $key;

        while (preg_match('/^(.+?):(.+?)$/', $target, $sub_matches)) {
          $new_key = $sub_matches[1];
          $target = $sub_matches[2];

          if (!isset($cur[$new_key])) {
            $cur[$new_key] = '';
          }

          $cur = $cur[$new_key];
        }

        if ($target != $key) {
          $cur = isset($cur[$target]) ? $cur[$target] : '';
          $replacements[$key] = $cur;
        }
      }
    }
  }

  foreach ($replacements as $key => $value) {
    $result = str_replace('%' . $key, $value, $result);
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
$options = getopt('u:f:p:');
$params = array();

$presets = preset_list();
$preset_name = 'health';

foreach ($options as $opt => $value) {
  $pcount --;

  if ($value !== FALSE) {
    $pcount --;

    switch ($opt) {
      case 'f': $params['format'] = $value; break;
      case 'u': $params['auth'] = trim($value); break;
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
$params['collect'] += array('allow_external' => FALSE);
if (isset($params['auth'])) {
  $params['http']['auth'] = $params['auth'];
}

// Start url is always the last parameter.
$start = $argv[$argc - 1];

$start_info = parse_url($start);
$start_info += array('scheme' => 'http', 'path' => '');

$visited = array();
$queue = array();
$queue[] = array('url' => $start);

// No time limit.
set_time_limit(0);

while (!empty($queue)) {
  $url_data = array_pop($queue);
  $url_data += array('parents' => array(), 'collect' => TRUE, 'referrer' => '');
  $url = $url_data['url'];

  if (isset($visited[$url])) {
    continue;
  }

  $visit = array();
  $visit['parents'] = join(' --> ', $url_data['parents']);
  $visit['referrer'] = end($url_data['parents']);

  // Try to fetch with HEAD first. In this way if the file is not a web page we avoid
  // the download of unnecessary data.
  $fetch = TRUE;
  $response_head = http_request($url, array_merge($params['http'], array('method' => 'HEAD', 'follow_redirects' => FALSE)));

  if ($response_head['code'] == 200) {
    $fetch = (strpos($response_head['content_type'], 'text/html') === 0);
  }

  if (!$fetch) {
    $visit += $response_head;

    print format_url($params['format'], $visit);
    print "\n";
  }
  else {
    $response = http_request($url, $params['http']);

    $collect = $url_data['collect'];
    if ($response['redirects_count'] == 0) {
      $visit += $response;
      $visited[$url] = $visit;
    }
    else {
      foreach ($response['redirects'] as $redirect_response) {
        $redirect_data = $visit + $redirect_response;
        $redirect_data['url'] = $redirect_response['url'];

        print format_url($params['format'], $redirect_data);
        print "\n";
      }

      $visit += $response;
      $last_redirect_info = parse_relative_url($response['url'], $start_info);
      $last_redirect_url = assemble_url($last_redirect_info);

      if (isset($visited[$last_redirect_url])) {
        $collect = FALSE;
      }
      else {
        $visited[$last_redirect_url] = $visit;

        $collect_redirect = $params['collect']['allow_external'] || ($last_redirect_info['host'] == $start_info['host']);
        $collect = $collect && $collect_redirect;
      }
    }

    print format_url($params['format'], $visit);
    print "\n";

    $is_web_page = (strpos($response['content_type'], 'text/html') === 0);

    // Collect urls only if it was a successful response, a page containing html
    // and collection was requested.
    if ($response['code'] == 200 && $is_web_page && $collect) {
      $urls = collect_urls($response['data'], $url, $params['collect']);

      $new_parents = array_merge($url_data['parents'], array($url));
      foreach ($urls as $collected) {
        $collected += array('parents' => $new_parents);
        $queue[] = $collected;
      }
    }
  }

  $visited[$url] = $visit;
}
