<?php

function visitor_show_usage($extra_error = NULL) {
  global $argv;

  if (isset($extra_error)) {
    print "Fatal error: $extra_error\n";
    print "\n";
  }

  print "Usage: \n";
  print $argv[0] . " [-f -u -p --accept-cookies] <url>\n";
  print "  -f: String to output whenever a new url is collected. \n";
  print "    Available variables: %url, %code, %content_type, %parent, %headers:<header_name_lowercase>\n";
  print "  -u: Authentication credentials, <user>:<pass>\n";
  print "  -p: Presets to load. Choose between: " . (join(', ', array_keys(visitor_preset_list()))) . "\n";
  print "    Multiple presets can be specified using a plus (+) separator. Presets will be merged together.\n";
  print "  --accept-cookies: Names of the cookies to accept. Use '*' to accept all cookies.\n";
  print "\n";
}

function visitor_get_error($error_key, $error_arg = NULL) {
  $message = $error_key;
  switch ($error_key) {
    case 'no_url':
      $message = 'No url given';
      break;

    case 'invalid_presets':
      $message = sprintf('Invalid presets %s', join(',', $error_arg));
      break;
  }

  return $message;
}

function visitor_requirements() {
  if (php_sapi_name() != 'cli') {
    die('PHP must work in cli mode.');
  }

  $min_php_version = '5.3.0';
  if (version_compare(PHP_VERSION, $min_php_version) < 0) {
    die("Minimum PHP version must be: $min_php_version.");
  }

  if (!extension_loaded('curl')) {
    die('This script needs CURL extension to make HTTP requests.');
  }
}

function visitor_http_request($url, $options = array()) {
  $options += array(
    'auth' => FALSE,
    'user_agent' => 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.13) Gecko/20080311 Firefox/2.0.0.13',
    'max_redirects' => 15,
    'method' => 'GET',
    'follow_redirects' => TRUE,
    'cookies' => array(),
  );

  if (!$options['follow_redirects']) {
    $options['max_redirects'] = 0;
  }

  $redirects_count = 0;
  $redirects = array();
  $location = $url;
  $location_info = parse_url($url);
  $location_info += array('scheme' => 'http', 'path' => '');
  while (($response = visitor_curl_http_request($location, $options)) && $response['is_redirect'] && ($redirects_count < $options['max_redirects'])) {
    $redirects[] = $response;
    $redirects_count++;
    $location_header = $response['headers']['location'][0];
    $location_info = visitor_parse_relative_url($location_header, $location_info);
    $location = visitor_assemble_url($location_info);
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

function visitor_curl_http_request($url, $options = array()) {
  $url_info = parse_url($url);

  // If path is not already encoded, encode it now.
  if (isset($url_info['path']) && $url == rawurldecode($url)) {
    $url_info['path'] = str_replace('%2F', '/', rawurlencode($url_info['path']));
    $url = visitor_assemble_url($url_info);
  }

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

  if (!empty($options['cookies'])) {
    $_cookies = array();
    foreach ($options['cookies'] as $cookie_name => $cookie_data) {
      $cookie_value = !is_array($cookie_data) ? $cookie_data : $cookie_data['value'];
      $_cookies[] = "$cookie_name=$cookie_value";
    }

    $cookies_string = join('; ', $_cookies);
    curl_setopt($ch, CURLOPT_COOKIE, $cookies_string);
  }

  $data = curl_exec($ch);
  $headers_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
  curl_close($ch);

  $headers_string = substr($data, 0, $headers_size);
  $data = substr($data, $headers_size);
  $headers = visitor_http_request_parse_headers($headers_string);
  $is_redirect = (in_array($code, array(301, 302)));

  $cookies = array();
  if (isset($headers['set-cookie'])) {
    foreach ($headers['set-cookie'] as $cookie_data) {
      $cookie = visitor_parse_cookie($cookie_data);
      $cookie += array('domain' => $url_info['host']);
      $cookies[$cookie['name']] = $cookie;
    }
  }

  $result = array();
  $result['error'] = '';
  $result['data'] = $data;
  $result['code'] = $code;
  $result['content_type'] = $content_type;
  $result['headers'] = $headers;
  $result['is_redirect'] = $is_redirect;
  $result['url'] = $url;
  $result['cookies'] = $cookies;

  return $result;
}

function visitor_http_request_parse_headers($headers_string) {
  $default_headers = array('location' => array());
  $headers = $default_headers;
  $lines = preg_split('/\r\n/', trim($headers_string));
  $first = array_shift($lines);
  foreach ($lines as $line) {
    if (preg_match('/^(.*?): (.*)/', $line, $matches)) {
      $header_name = strtolower($matches[1]);
      $header_val = $matches[2];
      if (!isset($headers[$header_name])) {
        $headers[$header_name] = array();
      }

      $headers[$header_name][] = $header_val;
    }
  }

  if (!isset($headers['status'])) {
    $headers['status'] = array($first);
  }

  return $headers;
}

function visitor_parse_cookie($cookie_data) {
  $cookie = array(
    'path' => '/',
    'secure' => FALSE,
    'httponly' => FALSE,
    'session' => TRUE,
  );

  $exploded = explode('; ', $cookie_data);
  $parts = array();
  foreach ($exploded as $part) {
    list($name, $value) = explode('=', $part) + array('', TRUE);
    $parts[] = array($name, $value);
  }

  $first = array_shift($parts);
  $cookie['name'] = $first[0];
  $cookie['value'] = $first[1];

  foreach ($parts as $part) {
    $part_name = strtolower($part[0]);
    $cookie[$part_name] = $part[1];
  }

  if (isset($cookie['expires'])) {
    $cookie['expires_time'] = strtotime($cookie['expires']);
    $cookie['session'] = FALSE;
  }

  return $cookie;
}

function visitor_cookie_matches($cookie, $query) {
  if (isset($cookie['expires_time']) && $cookie['expires_time'] < $query['now']) {
    return FALSE;
  }

  if ($cookie['secure'] && $query['scheme'] != 'https') {
    return FALSE;
  }

  if (!preg_match('@^' . $cookie['path'] . '@', $query['path'])) {
    return FALSE;
  }

  if (($query['domain'] == $cookie['domain']) || ('.' . $query['domain'] == $cookie['domain'])) {
    return TRUE;
  }

  if ($cookie['domain'][0] == '.') {
    $cookie_domain_regex = '@^.*?' . str_replace('.', '\.', $cookie['domain']) . '@';
    return preg_match($cookie_domain_regex, $query['domain']);
  }

  return FALSE;
}

function visitor_collect_urls($page_html, $page_url, $options = array()) {
  if (strlen($page_html) == 0) {
    return array();
  }

  $options += array(
    'allow_external' => TRUE,
    'tags' => array(),
    'xpath' => array(),
    'css' => array(),
    'protocols' => array('http', 'https'),
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

  // If unable to parse the html document, skip.
  $dom_loaded = $dom->loadHTML($page_html);
  if ($dom_loaded === FALSE) {
    return array();
  }

  $xpath = new DOMXpath($dom);

  // Traverse the document via xpath.
  foreach ($options['tags'] as $tag => $attrs) {
    $xpath_expr = ('//' . $tag);
    $options['xpath'][$xpath_expr] = $attrs;
  }

  foreach ($options['css'] as $css => $attrs) {
    $xpath_expr = visitor_css_to_xpath($css);
    $options['xpath'][$xpath_expr] = $attrs;
  }

  foreach ($options['xpath'] as $xpath_expr => $attrs) {
    $nodes = $xpath->query($xpath_expr);

    if ($nodes) {
      foreach ($nodes as $node) {
        foreach ($attrs as $attr) {
          $found[] = $node->getAttribute($attr);
        }
      }
    }
  }

  foreach ($found as $value) {
    $orig_value = $value;

    if (empty($value) || $value[0] == '#') {
      continue;
    }

    $value_info = visitor_parse_relative_url($value, $url_info);

    if ($value_info === FALSE) {
      continue;
    }

    if (!in_array($value_info['scheme'], $options['protocols'])) {
      continue;
    }

    $value_assembled = visitor_assemble_url($value_info);

    $collect = $options['allow_external'] || ($value_info['host'] == $url_info['host']);

    $result[] = array('url' => $value_assembled, 'collect' => $collect, 'url_info' => $url_info);
  }

  // Prevents DOMDocument memory leaks caused by internal logs.
  // http://stackoverflow.com/questions/8379829/domdocument-php-memory-leak
  unset($dom);
  libxml_use_internal_errors(FALSE);

  return $result;
}

function visitor_parse_relative_url($url, $from_info) {
  $from_path = $from_info['path'];
  if (substr($from_path, 1) == '/') {
    $from_base = $from_path;
  }
  else if (strpos($from_path, '.') === FALSE) {
    $from_base = $from_path;
  }
  else {
    $from_base = dirname($from_path);
  }

  $from_root = $from_info['scheme'] . '://' . $from_info['host'];

  // Handle protocol-relative urls.
  if (substr($url, 0, 2) == '//') {
    $url = $from_info['scheme'] . ':' . $url;
  }
  else if ($url[0] == '/') {
    // Handle root-relative.
    $url = $from_root . $url;
  }
  else if ($url[0] == '?') {
    // Handle urls made of get parameters only.
    $url = $from_base . $url;
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
    $url_info['path'] = visitor_resolve_relative_path($from_base, $url_info['path']);
  }

  return $url_info;
}

function visitor_resolve_relative_path($base_path, $rel_path) {
  $prefix = '';
  if (isset($base_path[0]) && $base_path[0] === '/') {
    $prefix = '/';
  }

  $base_is_dir = (substr($base_path, -1) === '/');

  $base_path = rtrim($base_path, '/');
  $rel_path = ltrim($rel_path, '/');

  $base_path_parts = array_filter(explode('/', $base_path));
  $rel_path_parts = array_filter(explode('/', $rel_path));

  // If base path is not a directory (thus, a file)
  // the last part of the path must not be considered.
  if (!$base_is_dir) {
    array_pop($base_path_parts);
  }

  $count_rel = array_count_values($rel_path_parts);
  $count_rel += array('..' => 0);

  if ($count_rel['..'] > count($base_path_parts)) {
    return FALSE;
  }

  foreach ($rel_path_parts as $rel_part) {
    if ($rel_part == '.') {
      array_shift($rel_path_parts);
    }
    else if ($rel_part == '..') {
      array_pop($base_path_parts);
      array_shift($rel_path_parts);
    }
  }

  return $prefix . join('/', array_merge($base_path_parts, $rel_path_parts));
}

function visitor_assemble_url($parsed) {
  $assembled = $parsed['scheme'] . '://' . rtrim($parsed['host'], '/\\') . '/' . ltrim($parsed['path'], '/\\');
  if (isset($parsed['query'])) {
    $assembled .= '?' . str_replace('&amp;', '&', $parsed['query']);
  }

  return $assembled;
}


function visitor_format_url($format, $data) {
  $headers = isset($data['headers']) ? $data['headers'] : array();
  $data['headers'] = array();
  foreach ($headers as $key => $values) {
    $data['headers'][$key] = join(', ', $values);
  }

  return visitor_format_string($format, $data);
}

function visitor_format_string($format, $data) {
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

function visitor_preset_list() {
  $presets = array();
  $presets['health'] = array(
    'http' => array(),
    'collect' => array(
      'tags' => array(
        '*' => array('href', 'src'),
      ),
    ),
  );

  $presets['links'] = array(
    'http' => array(),
    'collect' => array(
      'tags' => array(
        'a' => array('href'),
      ),
    ),
  );

  $presets['media'] = array(
    'http' => array(),
    'collect' => array(
      'tags' => array(
        'a' => array('href'),
        'img' => array('src'),
        'video' => array('src'),
        'audio' => array('src'),
        'source' => array('src'),
        'object' => array('src'),
      ),
    ),
  );

  $presets['liferay'] = array(
    'exclude' => 'p_p_auth',
  );

  return $presets;
}

function visitor_css_to_xpath($css) {
  static $cache;
  if (!isset($cache[$css])) {
    $url = "http://css2xpath.appspot.com/?css=$css";
    $response = visitor_http_request($url);
    if ($response['code'] == 200) {
      $cache[$css] = $response['data'];
    }
  }

  return $cache[$css];
}

function visitor_array_merge_deep() {
  $args = func_get_args();
  return visitor_array_merge_deep_array($args);
}

function visitor_array_merge_deep_array($arrays) {
  $result = array();
  foreach ($arrays as $array) {
    foreach ($array as $key => $value) {
      // Renumber integer keys as array_merge_recursive() does. Note that PHP
      // automatically converts array keys that are integer strings (e.g., '1')
      // to integers.
      if (is_integer($key)) {
        $result[] = $value;
      }
      // Recurse when both values are arrays.
      elseif (isset($result[$key]) && is_array($result[$key]) && is_array($value)) {
        $result[$key] = visitor_array_merge_deep_array(array($result[$key], $value));
      }
      // Otherwise, use the latter value, overriding any previous value.
      else {
        $result[$key] = $value;
      }
    }
  }
  return $result;
}

function visitor_default_options() {
  return array(
    'http' => array(),
    'collect' => array(
      'allow_external' => FALSE,
    ),
    'accept-cookies' => FALSE,
    'format' => '%url %code',
    'exclude' => FALSE,
    'print' => TRUE,
  );
}

/**
 * Read argument from a list of parsed command line options.
 */
function visitor_read_arguments($cli_args) {
  $args = $cli_args;

  // Remove script name.
  array_shift($args);

  $default_options = visitor_default_options();
  $preset_list = visitor_preset_list();
  $presets_chosen = array(key($preset_list));

  $input = array();
  $input['error'] = FALSE;
  $input['options'] = array();
  $input['presets'] = array();

  while ((($arg = array_shift($args)) !== NULL) && !$input['error']) {
    switch ($arg) {
      case '-f':
        $input['options']['format'] = trim(array_shift($args));
        break;

      case '-u':
        $input['options']['auth'] = trim(array_shift($args));
        break;

      case '-p':
        $_presets = explode('+', array_shift($args));

        if ($_unknown_presets = array_diff($_presets, array_keys($preset_list))) {
          $input['error'] = visitor_get_error('invalid_presets', $_unknown_presets);
        }
        else {
          $input['presets'] = $_presets;
        }

        break;

      case '--accept-cookies':
        $input['options']['accept-cookies'] = trim(array_shift($args));
        break;

      default:
        $start_url = trim($arg);
        break;
    }
  }

  if (!$input['error'] && !isset($start_url)) {
    $input['error']  = visitor_get_error('no_url');
  }

  $result = array();
  $result['error'] = $input['error'];

  if (!$input['error']) {
    $result['start_url'] = $start_url;

    if (!empty($input['presets'])) {
      $presets_chosen = $input['presets'];
    }

    $options = $default_options;
    foreach ($presets_chosen as $preset_name) {
      $options = visitor_array_merge_deep($options, $preset_list[$preset_name]);
    }

    $options = visitor_array_merge_deep($options, $input['options']);

    $result['options'] = $options;
  }

  return $result;
}

function visitor_init($start_url, $options = array()) {
  $visitor = array();
  visitor_reset($visitor);
  $visitor['start_url'] = $start_url;
  $visitor['options'] = $options;
  return $visitor;
}

function visitor_reset(&$visitor) {
  $visitor['cookies'] = array();
  $visitor['queue'] = array();
  $visitor['visited'] = array();
  $visitor['print'] = array();
}

function visitor_run(&$visitor) {
  $queue = array();
  $cookies = array();
  $options = $visitor['options'];

  // Start url is always the last parameter.
  $start_url = $visitor['start_url'];

  $start_info = parse_url($start_url);
  $start_info += array('scheme' => 'http', 'path' => '');

  $visited = array();
  $queue[] = array('url' => $start_url, 'url_info' => $start_info);

  $print_visit = function($data) use ($visitor, $options) {
    if ($visitor['options']['print']) {
      print visitor_format_url($options['format'], $data);
      print "\n";
    }
    else {
      $visitor['print'][] = $data;
    }
  };

  // Ensure queue can be dispatched successfully without raising timelimit errors.
  set_time_limit(0);

  while (!empty($queue)) {
    $url_data = array_pop($queue);
    $url_data += array('parents' => array(), 'collect' => TRUE, 'parent' => '');
    $url = $url_data['url'];
    $host = $url_data['url_info']['host'];

    // Skip already visited urls.
    if (isset($visited[$url])) {
      continue;
    }

    // Skip urls we want to exclude via regular expressions.
    if ($options['exclude'] !== FALSE && preg_match('@' . $options['exclude'] . '@', $url)) {
      continue;
    }

    $visit = array();
    $visit['parents'] = join(' --> ', $url_data['parents']);
    $visit['parent'] = end($url_data['parents']);

    // Find cookies we can send with this request.
    $request_cookies = array();
    $cookie_query = array(
      'now' => time(),
      'domain' => $host,
      'path' => $url_data['url_info']['path'],
      'scheme' => $url_data['url_info']['scheme'],
    );

    // Send cookies available for this domain/path/conditions.
    foreach ($cookies as $domain => $cookies_list) {
      foreach ($cookies_list as $cookie) {
        if (visitor_cookie_matches($cookie, $cookie_query)) {
          $request_cookies[$cookie['name']] = $cookie;
        }
      }
    }

    // Try to fetch with HEAD first. In this way if the file is not a web page we avoid
    // the download of unnecessary data.
    $fetch = TRUE;
    $response_head = visitor_http_request($url, array_merge($options['http'], array(
      'method' => 'HEAD',
      'follow_redirects' => FALSE,
      'cookies' => $request_cookies,
    )));

    if ($response_head['code'] == 200) {
      $fetch = (strpos($response_head['content_type'], 'text/html') === 0);
    }

    if (!$fetch) {
      $visit += $response_head;

      $print_visit($visit);
    }
    else {
      $response = visitor_http_request($url, array_merge($options['http'], array(
        'cookies' => $request_cookies,
      )));

      // If the response contains cookies, accept only those specified by arguments.
      if (!empty($response['cookies'])) {
        foreach ($response['cookies'] as $response_cookie) {
          if ($options['accept-cookies'] !== FALSE) {
            if ($options['accept-cookies'] == '*' || in_array($response_cookie['name'], $options['accept-cookies'])) {
              $cookie_domain = $response_cookie['domain'];
              if (!isset($cookies[$cookie_domain])) {
                $cookies[$cookie_domain] = array();
              }

              $cookies[$cookie_domain][$response_cookie['name']] = $response_cookie;
            }
          }
        }
      }

      $collect = $url_data['collect'];
      if ($response['redirects_count'] == 0) {
        $visit += $response;
        $visited[$url] = $visit;
      }
      else {
        foreach ($response['redirects'] as $redirect_response) {
          $redirect_data = $visit + $redirect_response;
          $redirect_data['url'] = $redirect_response['url'];

          $print_visit($redirect_data);
        }

        $visit += $response;

        $last_redirect_info = visitor_parse_relative_url($response['url'], $start_info);
        $last_redirect_url = visitor_assemble_url($last_redirect_info);

        if (isset($visited[$last_redirect_url])) {
          $collect = FALSE;
        }
        else {
          $visited[$last_redirect_url] = $visit;

          $collect_redirect = $options['collect']['allow_external'] || ($last_redirect_info['host'] == $start_info['host']);
          $collect = $collect && $collect_redirect;
        }
      }

      $print_visit($visit);

      $is_web_page = (strpos($response['content_type'], 'text/html') === 0);

      // Collect urls only if it was a successful response, a page containing html
      // and collection was requested.
      if ($response['code'] == 200 && $is_web_page && $collect) {
        $urls = visitor_collect_urls($response['data'], $url, $options['collect']);

        $new_parents = array_merge($url_data['parents'], array($url));
        foreach ($urls as $collected) {
          $collected += array('parents' => $new_parents);
          $queue[] = $collected;
        }
      }
    }

    $visited[$url] = $visit;
  }

  $visitor['queue'] = $queue;
  $visitor['visited'] = $visited;
  $visitor['cookies'] = $cookies;
}

// Script begins.

// Call the visitor routine only if we are in the *MAIN* script.
if (count(debug_backtrace()) > 0) {
  return;
}

ini_set('display_errors', 1);

// Avoid annoying php warnings saying default tz was not set.
date_default_timezone_set('UTC');

// Check for requirements first.
visitor_requirements();

// Read arguments passed to this script.

$visitor_args = visitor_read_arguments($argv);

if ($visitor_args['error']) {
  visitor_show_usage($visitor_args['error']);
  exit(1);
}

$visitor = visitor_init($visitor_args['start_url'], $visitor_args['options']);

// Run, run, run, as fast as you can.
visitor_run($visitor);
