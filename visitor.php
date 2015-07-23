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

    case 'project_file_not_readable':
      $message = sprintf('Unable to read project file "%s"', $error_arg);
      break;

    case 'project_file_parse_error':
      $message = sprintf('Unable to parse project file "%s"', $error_arg);
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
    'method' => 'GET',
    'auth' => FALSE,
    'user_agent' => 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.13) Gecko/20080311 Firefox/2.0.0.13',
    'max_redirects' => 15,
    'follow_redirects' => TRUE,
    'connection_timeout' => 10,
    'cookies' => array(),
  );

  $result = array(
    'data' => FALSE,
    'is_redirect' => FALSE,
    'redirect_url' => FALSE,
  );

  $url_info = parse_url($url);

  // If path is not already encoded, encode it now.
  if (isset($url_info['path']) && $url == rawurldecode($url)) {
    $url_info['path'] = str_replace('%2F', '/', rawurlencode($url_info['path']));
    $url = visitor_assemble_url($url_info);
  }

  $options['method'] = strtoupper($options['method']);
  $method = $options['method'];

  $ch = curl_init();
  curl_setopt($ch, CURLOPT_HEADER, TRUE);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);

  if ($options['follow_redirects']) {
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_MAXREDIRS, $options['max_redirects']);
  }
  else {
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
  }

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

  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

  $headers_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
  $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
  curl_close($ch);

  $headers_string = substr($data, 0, $headers_size);
  $data = substr($data, $headers_size);
  $headers = visitor_http_request_parse_headers($headers_string);
  $is_redirect = (in_array($code, array(301, 302, 303, 307)));

  $cookies = array();
  if (isset($headers['set-cookie'])) {
    foreach ($headers['set-cookie'] as $cookie_data) {
      $cookie = visitor_parse_cookie($cookie_data);
      $cookie += array('domain' => '.' . $url_info['host']);
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

  if ($is_redirect) {
    $result['redirect_url'] = $headers['location'][0];
  }

  switch ($curl_errno) {
    case CURLE_TOO_MANY_REDIRECTS:
      $result['code'] = $code;
      $result['error'] = 'too_many_redirects';
      break;

    case CURLE_OPERATION_TIMEDOUT:
      $result['code'] = -1;
      $result['error'] = 'connection_timedout';
      break;
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

function visitor_cookie_find_to_send($url, $cookiejar) {
  $url_data = parse_url($url);

  $request_cookies = array();
  $cookie_query = array(
    'now' => time(),
    'domain' => '.' . $host,
    'path' => $url_data['url_info']['path'],
    'scheme' => $url_data['url_info']['scheme'],
  );

  // Send cookies available for this domain/path/conditions.
  foreach ($cookiejar as $domain => $cookies_list) {
    if (visitor_cookie_matches($cookie, $cookie_query)) {
      $request_cookies[$cookie['name']] = $cookie;
    }
  }

  return $request_cookies;
}

function visitor_cookie_import_to_cookiejar($cookiejar, $response_cookies) {
  // foreach ($response_cookies as $response_cookie) {
  //   if (visitor_cookie_can_be_set($response_cookie, $host)) {
  //     if (!isset($cookies[$host])) {
  //       $cookies[$host] = array();
  //     }

  //     $cookies[$host][$response_cookie['name']] = $response_cookie;
  //   }
  // }
  return $cookiejar;
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

    $result[] = array('url' => $value_assembled, 'url_info' => $value_info);
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
    'time_limit' => 30 * 60,
    'request_max_redirects' => 15,
    'http' => array(),
    'collect' => array(
      'tags' => array(
        '*' => array('src', 'href')
      ),
    ),
    'accept_cookies' => TRUE,
    'format' => 'method:%request:method code:%code url:%url parent:%parent',
    'print' => TRUE,
  );
}

/**
 * Read argument from a list of parsed command line options.
 */
function visitor_console($cli_args) {
  $args = $cli_args;

  // Remove script name.
  array_shift($args);

  $input = array();
  $input['error'] = FALSE;
  $input['options'] = visitor_default_options();

  while ((($arg = array_shift($args)) !== NULL) && !$input['error']) {
    switch ($arg) {
      case '--project':
        $project_file = getcwd() . '/visitor.json';
        break;

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
    $input['error'] = visitor_get_error('no_url');
  }

  if (!$input['error'] && isset($project_file) && !file_exists($project_file)) {
    $input['error'] = visitor_get_error('no_project_file');
  }

  $console = array();
  $console['error'] = $input['error'];
  $console['visitor'] = array();

  if (!$input['error']) {
    if (isset($project_file)) {
      $project = visitor_load_project($project_file);

      if ($project['error']) {
        $console['error'] = $project['error'];
      }
      else {
        $console['visitor'] = $project['error'];
        $console['visitor']['options'] = array_merge($console['visitor']['options'], $input['options']);
      }
    }
    else {
      $console['visitor']['start_url'] = $start_url;
      $console['visitor']['options'] = $input['options'];
    }
  }

  return $console;
}

function visitor_load_project($project_file) {
  $project = array();
  $project['error'] = FALSE;

  $content = file_get_contents($project_file);
  if ($content === FALSE) {
    $project['error'] = visitor_get_error('project_file_not_readable', $project_file);
    return $project;
  }

  $json = json_decode($content, TRUE);
  if ($json === NULL) {
    $project['error'] = visitor_get_error('project_file_parse_error', $project_file);
    return $project;
  }

  $project['visitor'] = $json;
  return $project;
}

function visitor_init($start_url, $options = array()) {
  $visitor = array();
  visitor_reset($visitor);
  $visitor['start_url'] = $start_url;
  $visitor['options'] = $options;
  return $visitor;
}

function visitor_reset(&$visitor) {
  $visitor['cookiejar'] = array();
  $visitor['queue'] = array();
  $visitor['visited'] = array();
  $visitor['log'] = array();
  $visitor['timers'] = array();
}

function visitor_log(&$visitor, $data) {
  $data += array('timestamp' => time());

  if ($visitor['options']['print']) {
    switch ($data['type']) {
      case 'visit':
      case 'redirect':
        print visitor_format_url($visitor['options']['format'], $data['data']);
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
  visitor_log($visitor, array('type' => 'visit', 'data' => $visit));
}

function visitor_timer_init(&$visitor, $timer_name, $data = array()) {
  $timer = $data;
  $timer += array(
    'start' => time(),
  );

  $timer['current'] = $timer['start'];
  $timer['expired'] = FALSE;

  if ($timer['max_age']) {
    $timer['expires'] = $timer['start'] + $timer['max_age'];
  }

  $visitor['timers'][$timer_name] = $timer;
  return $timer;
}

function visitor_timer_tick(&$visitor, $timer_name) {
  if (!isset($visitor['timers'][$timer_name])) {
    return FALSE;
  }

  $visitor['current'] = time();
  $visitor['expired'] = ($visitor['current'] > $visitor['expires']);

  return TRUE;
}

function visitor_timer_expired(&$visitor, $timer_name) {
  if (!isset($visitor['timers'][$timer_name])) {
    return FALSE;
  }

  return $visitor['expired'];
}

function visitor_timer_destroy(&$visitor, $timer_name) {
  if (!isset($visitor['timers'][$timer_name])) {
    return FALSE;
  }

  unset($visitor['timers'][$timer_name]);
  return TRUE;
}

function visitor_run(&$visitor) {
  $options = $visitor['options'];

  $start_url = $visitor['start_url'];
  $start_info = parse_url($start_url);
  $start_info += array('scheme' => 'http', 'path' => '');

  $visitor['queue'] = array();
  $visitor['queue'][] = array('url' => $start_url, 'url_info' => $start_info);

  $visitor['error'] = FALSE;

  // Ensure queue can be dispatched successfully without raising timelimit errors.
  set_time_limit(0);

  visitor_timer_init($visitor, 'queue', array(
    'max_age' => $options['time_limit'],
  ));

  while (!empty($visitor['queue']) && !$visitor['error']) {
    $queue_item = array_shift($visitor['queue']);
    $queue_item += array('parents' => array(), 'parent' => '');
    $url = $queue_item['url'];
    $host = $queue_item['url_info']['host'];

    // Skip already visited urls.
    if (isset($visited[$url])) {
      continue;
    }

    $visit = array();
    $visit['parents'] = join(' --> ', $queue_item['parents']);
    $visit['parent'] = end($queue_item['parents']);

    // Try to fetch with HEAD first. In this way if the file is not a web page we avoid
    // the download of unnecessary data.
    $response_target = FALSE;
    $response_head = visitor_http_request($url, array_merge($options['http'], array(
      'method' => 'HEAD',
      'follow_redirects' => FALSE,
      'cookiejar' => $visitor['cookiejar'],
    )));

    // @todo: find cookies to accept and merge them with our cookiejar.

    if ($response_head['error']) {
      visitor_log($visitor, array(
        'type' => 'error', 
        'error' => $response_head['error'],
        'message' => visitor_get_error($response_head['error'], $url),
      ));
    }
    else if ($response_head['is_redirect']) {
      $response_redirect = $response_head;
      $redirects_count = 1;

      do {
        $response_redirect = visitor_http_request($response_redirect['redirect_url'], array_merge($options['http'], array(
          'method' => 'HEAD',
          'follow_redirects' => FALSE,
          'cookiejar' => $visitor['cookiejar'],
        )));

        // @todo: find cookies to accept and merge them with our cookiejar.
        if ($redirects_count > $options['request_max_redirects']) {
          visitor_log($visitor, array(
            'type' => 'error', 
            'error' => 'too_many_redirects',
            'message' => visitor_get_error('too_many_redirects', $url),
          ));

          // Exit loop.
          break;
        }
        else if ($response_redirect['is_redirect']) {
          $redirects_count++;

          visitor_log($visitor, array(
            'type' => 'redirect',
            'data' => $response_redirect,
          ));

          visitor_timer_tick($visitor, 'queue');

          if (visitor_timer_expired($visitor, 'queue')) {
            visitor_log($visitor, array(
              'type' => 'error',
              'error' => 'time_limit_reached',
              'message' => visitor_get_error('time_limit_reached', $options['time_limit'])
            ));

            $visitor['error'] = 'time_limit_reached';
            break;
          }
          else {
            // Keep following redirects.
            continue;
          }
        }
        else if ($response_redirect['error']) {
          visitor_log($visitor, array(
            'type' => 'error', 
            'error' => $response_redirect['error'],
            'message' => visitor_get_error($response_redirect['error'], $url),
          ));

          // Exit loop.
          break;
        }
        else {
          // All other cases: 200, 404, 500, but not redirect status, nor internal errors.
          $response_target = $response_redirect;
          break;
        }
      }
      while (1);
    }
    else {
      $response_target = $response_head;
      $visit += $response_target;
    }

    if ($response_target !== FALSE) {
      if (in_array($response_target['code'], array(200, 404))) {
        $do_fetch_body = (strpos($response_target['content_type'], 'text/html') === 0);
        
        if ($do_fetch_body) {
          $response_get = visitor_http_request($response_target['url'], array_merge($options['http'], array(
            'method' => 'GET',
            'follow_redirects' => FALSE,
            'cookies' => visitor_cookie_find_to_send($response_target['url'], $visitor['cookiejar']),
          )));

          $urls = visitor_collect_urls($response_get['data'], $response_get['url'], $options['collect']);

          $new_parents = array_merge($queue_item['parents'], array($response_get['url']));
          foreach ($urls as $collected) {
            $collected += array('parents' => $new_parents);
            $visitor['queue'][] = $collected;
          }
        }
      }

      $visited[$url] = $visit;
    }

    // The url has been visited, so we don't want to collect it anymore.
    $options['collect']['exclude'][] = $url;

    visitor_timer_tick($visitor, 'queue');

    if ($visitor['error'] != 'time_limit_reached' && visitor_timer_expired($visitor, 'queue')) {
      visitor_log($visitor, array(
        'type' => 'error',
        'error' => 'time_limit_reached',
        'message' => visitor_get_error('time_limit_reached', $options['time_limit'])
      ));
      break;
    }
  }

  visitor_timer_destroy($visitor, 'queue');
}

// Call the visitor routine only if we are in the *MAIN* script.
// Otherwise we are including visitor as a library.
if (count(debug_backtrace()) > 0) {
  return;
}

ini_set('display_errors', 1);

// Avoid annoying php warnings saying default tz was not set.
date_default_timezone_set('UTC');

// Check for requirements first.
visitor_requirements();

// Read arguments and create the resulting visitor object.
$console = visitor_console($argv);

if ($console['error']) {
  visitor_show_usage($console['error']);
  exit(1);
}

$visitor = $console['visitor'];

// Run, run, run, as fast as you can.
visitor_run($visitor);
