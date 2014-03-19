<?php

function show_help() {}

function http_request($url, $options = array()) {
  if (!extension_loaded('curl')) {
    die('CURL library must be installed to download files.');
  }

  $ch = curl_init();
  curl_setopt($ch, CURLOPT_HEADER, 0);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_URL, $url);
  $data = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  $result = array();
  $result['data'] = $data;
  $result['code'] = $code;

  return $result;
}

function collect_urls($page_html, $page_url, $options = array()) {
  $options += array(
    'stay_in_domain' => TRUE,
    'tags' => array('a' => array('href')),
    'protocols' => array('http'),
  );

  $url_parsed = parse_url($page_url);
  $url_parsed += array('scheme' => 'http', 'path' => '');
  $domain = $url_parsed['scheme'] . '://' . $url_parsed['host'];

  $result = array();
  foreach ($options['tags'] as $tag => $attrs) {
    if (preg_match_all('$<' . $tag . '[^>]+(?:' . join('|', $attrs) . ')="([^#].*?)"$msi', $page_html, $matches)) {
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

        if (!isset($match_parsed['scheme']) && !isset($match_parsed['host'])) {
          $match_parsed['scheme'] = $url_parsed['scheme'];
          $match_parsed['host'] = $url_parsed['host'];
          $match_parsed['path'] = '/' . $match_parsed['path'];
        }

        if (!in_array($match_parsed['scheme'], $options['protocols'])) {
          continue;
        }

        $match_domain = $match_parsed['scheme'] . '://' . $match_parsed['host'];

        if ($options['stay_in_domain'] && $match_domain != $domain) {
          continue;
        }

        $match_assembled = $match_parsed['scheme'] . '://' . $match_parsed['host'] . $match_parsed['path'];
        if (isset($match_parsed['query'])) {
          $match_assembled .= '?' . $match_parsed['query'];
        }

        $result[] = $match_assembled;
      }
    }
  }

  return $result;
}

function format_url($format, $url, $data) {
  $result = $format;
  foreach ($data as $key => $value) {
    $result = str_replace('%' . $key, $value, $result);
  }

  return $result;
}

if (php_sapi_name() != 'cli') {
  die('No CLI no party.');
}

if ($argc == 1) {
  show_help();
  die();
}

$options = getopt('u:f:');
$format = '%url';
if (isset($options['f']) && $options['f']) {
  $format = $options['f'];
}

$start = $argv[$argc - 1];

$start_parsed = parse_url($start);
$start_parsed += array('scheme' => 'http', 'path' => '');

$start = $start_parsed['scheme'] . '://' . $start_parsed['host'] . $start_parsed['path'];
$root = $start_parsed['scheme'] . '://' . $start_parsed['host'];

$visited = array();
$queue = array();
$queue[] = array('url' => $start);

while (!empty($queue)) {
  $url_data = array_pop($queue);
  $url_data += array('parents' => array());
  $url = $url_data['url'];

  if (isset($visited[$url])) {
    continue;
  }

  $response = http_request($url);

  $visit = array();
  $visit['url'] = $url;
  $visit['code'] = $response['code'];
  $visit['parents'] = join(' --> ', $url_data['parents']);

  $visited[$url] = $visit;

  print format_url($format, $url, $visit);
  print PHP_EOL;

  if ($response['code'] == 200) {
    $urls = collect_urls($response['data'], $url);

    foreach ($urls as $collected) {
      $queue[] = array('url' => $collected, 'parents' => array_merge($url_data['parents'], array($url)));
    }
  }
}
