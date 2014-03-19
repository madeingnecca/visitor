<?php

function show_help() {}

function http_request($url, $options = array()) {
  if (!extension_loaded('curl')) {
    die('CURL library must be installed to download files.');
  }

  $options += array('auth' => FALSE);

  $ch = curl_init();
  curl_setopt($ch, CURLOPT_HEADER, 0);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);

  if ($options['auth']) {
    curl_setopt($ch, CURLOPT_USERPWD, $options['auth']);
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
  }

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

        $match_assembled = $match_parsed['scheme'] . '://' . $match_parsed['host'] . $match_parsed['path'];
        if (isset($match_parsed['query'])) {
          $match_assembled .= '?' . $match_parsed['query'];
        }

        $result[] = array('url' => $match_assembled, 'collect' => ($options['stay_in_domain'] && $match_domain == $domain));
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
$auth = FALSE;

if (isset($options['f']) && $options['f']) {
  $format = $options['f'];
}

if (isset($options['u']) && $options['u']) {
  $auth = $options['u'];
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
  $url_data += array('parents' => array(), 'collect' => TRUE);
  $url = $url_data['url'];

  if (isset($visited[$url])) {
    continue;
  }

  $response = http_request($url, array('auth' => $auth));

  $visit = array();
  $visit['url'] = $url;
  $visit['code'] = $response['code'];
  $visit['parents'] = join(' --> ', $url_data['parents']);

  $visited[$url] = $visit;

  print format_url($format, $url, $visit);
  print PHP_EOL;

  if ($url_data['collect'] && $response['code'] == 200) {
    $urls = collect_urls($response['data'], $url);

    $new_parents = array_merge($url_data['parents'], array($url));
    foreach ($urls as $collected) {
      $collected += array('parents' => $new_parents);
      $queue[] = $collected;
    }
  }
}
