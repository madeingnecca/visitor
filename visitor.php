<?php

function show_help() {
  print "One day this will be a very helpful help.\n";
}

function http_request($url, $options = array()) {
  if (!extension_loaded('curl')) {
    die('CURL library must be installed to download files.');
  }

  $options += array('auth' => FALSE);

  $ch = curl_init();
  curl_setopt($ch, CURLOPT_HEADER, TRUE);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);

  if ($options['auth']) {
    curl_setopt($ch, CURLOPT_USERPWD, $options['auth']);
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
  }

  $data = curl_exec($ch);
  $headers_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
  curl_close($ch);

  $headers_string = substr($data, 0, $headers_size);
  $data = substr($data, $headers_size);
  $headers = http_request_parse_headers($headers_string);

  $result = array();
  $result['data'] = $data;
  $result['code'] = $code;
  $result['content_type'] = $content_type;
  $result['headers'] = $headers;

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

  $result = array();
  foreach ($options['tags'] as $tag => $attrs) {
    if (preg_match_all('%<' . $tag . '[^>]+(?:' . join('|', $attrs) . ')="([^#].*?)"%', $page_html, $matches)) {
      foreach ($matches[1] as $match) {
        // Handle protocol-relative urls.
        if (substr($match, 0, 2) == '//') {
          $match = $url_parsed['scheme'] . ':' . $match;
        }

        // Handle root-relative.
        if ($match[0] == '/') {
          $match = $domain . $match;
        }

        $match_parsed = parse_url($match);
        $match_parsed += array('path' => '');

        // Other kind of relative urls.
        // @TODO: implement directory traversal.
        if (!isset($match_parsed['scheme']) && !isset($match_parsed['host'])) {
          $match_parsed['scheme'] = $url_parsed['scheme'];
          $match_parsed['host'] = $url_parsed['host'];
          $match_parsed['path'] = $url_base . '/' . $match_parsed['path'];
        }

        if (!in_array($match_parsed['scheme'], $options['protocols'])) {
          continue;
        }

        $match_domain = $match_parsed['scheme'] . '://' . $match_parsed['host'];
        $match_assembled = assemble_url($match_parsed);

        $result[] = array('url' => $match_assembled, 'collect' => ($options['stay_in_domain'] && $match_domain == $domain));
      }
    }
  }

  return $result;
}

function assemble_url($parsed) {
  $assembled = $parsed['scheme'] . '://' . $parsed['host'] . $parsed['path'];
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

if (php_sapi_name() != 'cli') {
  die('No CLI, no party.');
}

$pcount = $argc - 1;
$options = getopt('u:f:');
$format = '%url';
$auth = FALSE;

foreach ($options as $opt => $value) {
  if ($value) {
    $pcount -= 2;

    switch ($opt) {
      case 'f': $format = $value; break;
      case 'u': $auth = $value; break;
    }
  }
}

if ($pcount < 1) {
  show_help();
  die();
}

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
  $url_data += array('parents' => array(), 'collect' => TRUE);
  $url = $url_data['url'];

  if (isset($visited[$url])) {
    continue;
  }

  $response = http_request($url, array('auth' => $auth));

  $visit = array();
  $visit['url'] = $url;
  $visit['parents'] = join(' --> ', $url_data['parents']);
  $visit += $response;

  $visited[$url] = $visit;

  print format_url($format, $url, $visit);
  print "\n";

  $is_web_page = (strpos($response['content_type'], 'text/html') === 0);

  // Collect urls only if it was a successful response, a page containing html
  // and collection was requested.
  if ($response['code'] == 200 && $is_web_page && $url_data['collect']) {
    $urls = collect_urls($response['data'], $url);

    $new_parents = array_merge($url_data['parents'], array($url));
    foreach ($urls as $collected) {
      $collected += array('parents' => $new_parents);
      $queue[] = $collected;
    }
  }
}
