<?php

function visitor_show_usage($extra_error = NULL) {
  global $argv;

  if (isset($extra_error)) {
    print "Fatal error: $extra_error\n";
    print "\n";
  }

  print "Usage: \n";
  print $argv[0] . " [-f -u -p --no-cookies] <url>\n";
  print "  -f: String to output whenever a new url is collected. \n";
  print "    Available variables: %url, %code, %content_type, %parent, %headers:<header_name_lowercase>\n";
  print "  -u: Authentication credentials, <user>:<pass>\n";
  print "  --no-cookies: Prevent Visitor to store and send cookies.\n";
  print "\n";
}

function visitor_get_error($error_key, $error_arg = NULL) {
  $message = $error_key;
  switch ($error_key) {
    case 'no_url':
      $message = 'No url given';
      break;

    case 'time_limit_reached':
      $message = sprintf('Time limit was reached (%s)', $error_arg);
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
    die('This script needs CURL extension to perform HTTP requests.');
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
    'connection_timeout' => 10,
  );

  if (!$options['follow_redirects']) {
    $options['max_redirects'] = 0;
  }

  $time_start = time();
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

  $time_end = time();

  $result = $response;
  $result['redirects'] = $redirects;
  $result['redirects_count'] = $redirects_count;
  $result['last_redirect'] = ($redirects_count > 0 ? $location : FALSE);
  $result['time_start'] = $time_start;
  $result['time_end'] = $time_end;
  $result['time_elapsed'] = ($time_end - $time_start);

  if ($redirects_count > 0 && $redirects_count >= $options['max_redirects']) {
    $result['error'] = 'infinite-loop';
  }

  return $result;
}

function visitor_curl_http_request($url, $options = array()) {
  $result = array(
    'code' => -1,
    'is_redirect' => FALSE,
  );

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

  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $options['connection_timeout']);

  $data = curl_exec($ch);
  $curl_errno = curl_errno($ch);

  if ($curl_errno == 0) {
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

    $result['error'] = '';
    $result['data'] = $data;
    $result['code'] = $code;
    $result['content_type'] = $content_type;
    $result['headers'] = $headers;
    $result['is_redirect'] = $is_redirect;
    $result['url'] = $url;
    $result['cookies'] = $cookies;
  }
  else {
    switch ($curl_errno) {
      case CURLE_OPERATION_TIMEDOUT:
        $result['error'] = 'connection_timedout';
        break;
    }
  }

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

function visitor_parse_cookie($cookie_data, $context = array()) {
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

function visitor_cookie_can_be_set($cookie, $domain) {
  // Unlike real browsers, Visitor will accept all cookies.
  return TRUE;
}

/**
 * This implementation is based on the great piece of information
 * that can be found at http://stackoverflow.com/questions/1062963/how-do-browser-cookie-domains-work
 */
function visitor_cookie_matches($cookie, $query) {
  if (isset($cookie['expires_time']) && $cookie['expires_time'] < $query['now']) {
    return FALSE;
  }

  if ($cookie['secure'] && $query['scheme'] != 'https') {
    return FALSE;
  }

  if (!visitor_cookie_domain_matches($cookie, $query['domain'])) {
    return FALSE;
  }

  if (!visitor_cookie_path_matches($cookie, $query['path'])) {
    return FALSE;
  }

  return TRUE;
}

/**
 * @See http://tools.ietf.org/html/rfc6265#section-5.1.4
 */
function visitor_cookie_path_matches($cookie, $path) {
  $cookie_path = rtrim($cookie['path'], '/');

  // Cookie path must be a *prefix* of the target path.
  return preg_match('@^' . $cookie_path . '@', $path) ? TRUE : FALSE;
}

/**
 * @See http://tools.ietf.org/html/rfc6265#section-5.1.3
 */
function visitor_cookie_domain_matches($cookie, $domain) {
  // RFC 2109 states that cookies should always start with a leading dot.
  if ($domain[0] !== '.') {
    $domain = '.' . $domain;
  }

  if ($cookie['domain'] === '.' . $domain) {
    return TRUE;
  }

  // Cookie domain must be a *suffix* of the target domain.
  $cookie_domain_regex = '@' . str_replace('.', '\.', $cookie['domain']) . '$@';
  return preg_match($cookie_domain_regex, $domain) ? TRUE : FALSE;
}

function visitor_collect_urls($page_html, $page_url, $options = array()) {
  if (strlen($page_html) == 0) {
    return array();
  }

  $options += array(
    'tags' => array(),
    'xpath' => array(),
    'css' => array(),
    'protocols' => array('http', 'https'),
    'exclude' => array(),
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

    if (in_array($value_assembled, $options['exclude'])) {
      continue;
    }

    $result[] = array('url' => $value_assembled, 'url_info' => $url_info);
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

function visitor_default_options() {
  return array(
    'allow_external' => FALSE,
    'time_limit' => FALSE,
    'http' => array(),
    'collect' => array(
      'tags' => array(
        '*' => array('src', 'href')
      ),
    ),
    'accept_cookies' => TRUE,
    'format' => 'code:%code url:%url parent:%parent',
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

  $input = array();
  $input['error'] = FALSE;
  $input['options'] = visitor_default_options();

  while ((($arg = array_shift($args)) !== NULL) && !$input['error']) {
    switch ($arg) {
      case '-f':
        $input['options']['format'] = trim(array_shift($args));
        break;

      case '-u':
        $input['options']['http']['auth'] = trim(array_shift($args));
        break;

      case '--no-cookies':
        $input['options']['accept_cookies'] = FALSE;
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
    $result['options'] = $input['options'];
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
  $visitor['log'] = array();
}

function visitor_log(&$visitor, $data) {
  $data += array('timestamp' => time());

  if ($visitor['options']['print']) {
    switch ($data['type']) {
      case 'visit':
        print visitor_format_url($visitor['options']['format'], $data['visit']);
        print "\n";
        break;

      default:
        print strtoupper($data['type']) . ": " . $data['message'];
        print "\n";
        break;
    }
  }
  else {
    $visitor['log'][] = $data;
  }
}

function visitor_log_visit(&$visitor, $visit) {
  visitor_log($visitor, array('type' => 'visit', 'visit' => $visit));
}

function visitor_run(&$visitor) {
  $queue = array();
  $cookies = array();
  $options = $visitor['options'];

  $start_url = $visitor['start_url'];

  $start_info = parse_url($start_url);
  $start_info += array('scheme' => 'http', 'path' => '');

  $visited = array();
  $queue[] = array('url' => $start_url, 'url_info' => $start_info);

  if (isset($visitor['queue'])) {
    $queue = array_merge($queue, $visitor['queue']);
  }

  // Ensure queue can be dispatched successfully without raising timelimit errors.
  set_time_limit(0);

  $time_start = time();

  while (!empty($queue)) {
    $url_data = array_shift($queue);
    $url_data += array('parents' => array(), 'parent' => '');
    $url = $url_data['url'];
    $host = $url_data['url_info']['host'];

    // Skip already visited urls.
    if (isset($visited[$url])) {
      continue;
    }

    $visit = array();
    $visit['parents'] = join(' --> ', $url_data['parents']);
    $visit['parent'] = end($url_data['parents']);

    // Find cookies we can send with this request.
    $request_cookies = array();
    $cookie_query = array(
      'now' => time(),
      'domain' => '.' . $host,
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

      visitor_log_visit($visitor, $visit);
    }
    else {
      $response = visitor_http_request($url, array_merge($options['http'], array(
        'cookies' => $request_cookies,
      )));

      // If the response contains cookies, accept only those specified by arguments.
      if (!empty($response['cookies'])) {
        foreach ($response['cookies'] as $response_cookie) {
          if ($options['accept_cookies']) {
            if (visitor_cookie_can_be_set($response_cookie, $host)) {
              if (!isset($cookies[$host])) {
                $cookies[$host] = array();
              }

              $cookies[$host][$response_cookie['name']] = $response_cookie;
            }
          }
        }
      }

      $collect = ($options['allow_external'] || ($host == $start_info['host']));

      if ($response['redirects_count'] == 0) {
        $visit += $response;
        $visited[$url] = $visit;
      }
      else {
        foreach ($response['redirects'] as $redirect_response) {
          $redirect_data = $visit + $redirect_response;
          $redirect_data['url'] = $redirect_response['url'];

          visitor_log_visit($visitor, $redirect_data);
        }

        $visit += $response;

        $last_redirect_info = visitor_parse_relative_url($response['url'], $start_info);
        $last_redirect_url = visitor_assemble_url($last_redirect_info);

        if (isset($visited[$last_redirect_url])) {
          $collect = FALSE;
        }
        else {
          $visited[$last_redirect_url] = $visit;

          $collect_redirect = $options['allow_external'] || ($last_redirect_info['host'] == $start_info['host']);
          $collect = $collect && $collect_redirect;
        }
      }

      visitor_log_visit($visitor, $visit);

      // Collect urls only if it was a successful response, a page containing html
      // and collection was requested.
      if ($response['code'] == 200) {
        $is_web_page = (strpos($response['content_type'], 'text/html') === 0);

        if ($collect && $is_web_page) {
          $urls = visitor_collect_urls($response['data'], $url, $options['collect']);

          $new_parents = array_merge($url_data['parents'], array($url));
          foreach ($urls as $collected) {
            $collected += array('parents' => $new_parents);
            $queue[] = $collected;
          }
        }
      }
    }

    $visited[$url] = $visit;

    // The url has been visited, so we don't want to collect it anymore.
    $options['collect']['exclude'][] = $visit;

    if ($options['time_limit'] !== FALSE && ((time() - $time_start) > $options['time_limit'])) {
      visitor_log($visitor, array(
        'type' => 'error',
        'error' => 'time_limit_reached',
        'message' => visitor_get_error('time_limit_reached', $options['time_limit'])
      ));
      break;
    }
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
