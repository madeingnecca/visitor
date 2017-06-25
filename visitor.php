<?php

define('VISITOR_HTTP_CODE_CONNECTION_TIMEDOUT', -1);

function visitor_show_usage($extra_error = NULL) {
  if (isset($extra_error)) {
    print "Fatal error:\n";
    print "  $extra_error\n";
    print "\n";
  }

  print "Usage: \n";
  print "visitor [--help -f -t -u --project --no-cookies --cookiejar] <url>\n";
  print "  --help: Print this message.\n";
  print "  -f: String to output whenever Visitor \"visits\" a new url. \n";
  print "    Available variables: %url, %code, %content_type, %parent, %headers:<header_name_lowercase>\n";
  print "  -t: Sets time limit, in seconds.\n";
  print "  -u: Authentication credentials, <user>:<pass>\n";
  print "  --no-cookies: Tell Visitor not to store nor send cookies.\n";
  print "  --cookiejar: Path of the json file where all cookies found will be serialized to. This option will not work if \"--no-cookies\" flag is on.\n";
  print "  --project: Read url and options from <CWD>/visitor.json file.\n";
  print "\n";
}

function visitor_get_error($error_key) {
  $message = $error_key;

  switch ($error_key) {
    case 'no_url':
      $message = 'No url given';
      break;

    case 'time_limit_reached':
      $message = sprintf('Time limit of %s seconds was reached. Last visited page was: "%s"', func_get_arg(1), func_get_arg(2));
      break;

    case 'invalid_project_file':
      $message = sprintf('Project file does not exist: "%s"', func_get_arg(1));
      break;

    case 'project_file_not_readable':
      $message = sprintf('Unable to read project file "%s"', func_get_arg(1));
      break;

    case 'project_file_parse_error':
      $message = sprintf('Unable to parse project file "%s"', func_get_arg(1));
      break;

    case 'cookiejar_write_error':
      $message = sprintf('Unable to write cookiejar to "%s"', func_get_arg(1));
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

function visitor_parse_url($url) {
  static $cache = array();

  if (!isset($cache[$url])) {
    $url_info = parse_url($url);

    if ($url_info !== FALSE) {
      $url_info += array('scheme' => NULL, 'path' => NULL, 'host' => NULL, 'port' => NULL);
    }

    $cache[$url] = $url_info;
  }

  return $cache[$url];
}

/**
 * Performs a http request.
 */
function visitor_http_request($request) {
  if (is_string($request) && func_num_args() === 2) {
    $request = array(
      'url' => func_get_arg(0),
    );

    $request += func_get_arg(1);
  }

  $request += array(
    'method' => 'GET',
    'auth' => FALSE,
    'user_agent' => 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.13) Gecko/20080311 Firefox/2.0.0.13',
    'max_redirects' => 15,
    'follow_redirects' => TRUE,
    'connection_timeout' => 10,
    'cookies' => array(),
    'cookiejar' => NULL,
  );

  $response = array(
    'data' => FALSE,
    'is_redirect' => FALSE,
    'redirect_url' => FALSE,
  );

  $url = $request['url'];

  $url_info = visitor_parse_url($url);

  // If path is not already encoded, encode it now.
  if (isset($url_info['path']) && $url == rawurldecode($url)) {
    $url_info['path'] = str_replace('%2F', '/', rawurlencode($url_info['path']));
    $url = visitor_assemble_url($url_info);
  }

  // If a cookiejar was provided, get all cookies that can be sent with this request.
  if (isset($request['cookiejar'])) {
    $request['cookies'] = visitor_cookiejar_send_cookies($request['cookiejar'], $request['url']);
  }

  $request['method'] = strtoupper($request['method']);
  $method = $request['method'];

  $ch = curl_init();
  curl_setopt($ch, CURLOPT_HEADER, TRUE);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);

  if ($request['follow_redirects']) {
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_MAXREDIRS, $request['max_redirects']);
  }
  else {
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
  }

  if ($method == 'HEAD') {
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_NOBODY, TRUE);
  }

  curl_setopt($ch, CURLOPT_USERAGENT, $request['user_agent']);

  if ($request['auth']) {
    curl_setopt($ch, CURLOPT_USERPWD, $request['auth']);
  }

  if (!empty($request['cookies'])) {
    $_cookies = array();
    foreach ($request['cookies'] as $cookie_name => $cookie_data) {
      $_cookies[] = visitor_cookie_serialize($cookie_data);
    }

    $cookies_string = join('; ', $_cookies);
    curl_setopt($ch, CURLOPT_COOKIE, $cookies_string);
  }

  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $request['connection_timeout']);

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
  $cookie_context = array(
    'source' => $url,
  );

  if (isset($headers['set-cookie'])) {
    foreach ($headers['set-cookie'] as $cookie_data) {
      $cookie = visitor_cookie_parse($cookie_data, $cookie_context);
      $cookies[$cookie['name']] = $cookie;
    }
  }

  $response['error'] = FALSE;
  $response['data'] = $data;
  $response['code'] = $code;
  $response['content_type'] = $content_type;
  $response['headers'] = $headers;
  $response['is_redirect'] = $is_redirect;
  $response['cookies'] = $cookies;
  $response['url'] = $url;
  $response['url_info'] = visitor_parse_url($response['url']);

  // If a cookiejar was provided and we have cookies within the response,
  // import them inside the jar and then return the resulting jar as a property of the final response.
  if (isset($request['cookiejar']) && !empty($response['cookies'])) {
    $merged_cookiejar = $request['cookiejar'];
    visitor_cookiejar_import_response_cookies($merged_cookiejar, $response['cookies'], $request['url']);
    $response['cookiejar'] = $merged_cookiejar;
  }

  switch ($curl_errno) {
    case 0:
      if ($is_redirect) {
        $redirect_info = visitor_parse_relative_url($headers['location'][0], $url_info);
        $response['redirect_url'] = visitor_assemble_url($redirect_info);
      }
      break;

    case CURLE_TOO_MANY_REDIRECTS:
      $response['code'] = $code;
      $response['error'] = 'too_many_redirects';
      break;

    case CURLE_OPERATION_TIMEDOUT:
      $response['code'] = VISITOR_HTTP_CODE_CONNECTION_TIMEDOUT;
      $response['error'] = 'connection_timedout';
      break;
  }

  return $response;
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

function visitor_cookie_parse($cookie_data, $context = array()) {
  $cookie = array(
    'path' => '/',
    'domain' => FALSE,
    'secure' => FALSE,
    'httponly' => FALSE,
    'session' => TRUE,
    'raw' => $cookie_data,
    'context' => $context,
  );

  $exploded = explode('; ', $cookie_data);
  $parts = array();
  foreach ($exploded as $part) {
    list($name, $value) = explode('=', $part) + array('', TRUE);
    $parts[] = array(rawurldecode($name), rawurldecode($value));
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

  if ($cookie['domain'] !== FALSE) {
    // @see RFC 6265.
    $cookie['domain'] = strtolower($cookie['domain']);

    // RFC 2109 states that cookies should always start with a leading dot.
    if ($cookie['domain'][0] !== '.') {
      $cookie['domain'] = '.' . $cookie['domain'];
    }
  }

  return $cookie;
}

function visitor_cookie_serialize($cookie) {
  $cookie_name = $cookie['name'];
  $cookie_value = $cookie['value'];
  return rawurlencode($cookie_name) . '=' . rawurlencode($cookie_value);
}

function visitor_cookie_can_be_accepted($cookie, $response_url) {
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

  if (!visitor_cookie_matches_domain($cookie, $query['domain'])) {
    return FALSE;
  }

  if (!visitor_cookie_matches_path($cookie, $query['path'])) {
    return FALSE;
  }

  return TRUE;
}

/**
 * @See http://tools.ietf.org/html/rfc6265#section-5.1.4
 */
function visitor_cookie_matches_path($cookie, $path) {
  $cookie_path = rtrim($cookie['path'], '/');

  // Cookie path must be a *prefix* of the target path.
  return preg_match('@^' . $cookie_path . '@', $path) ? TRUE : FALSE;
}

/**
 * @See http://tools.ietf.org/html/rfc6265#section-5.1.3
 */
function visitor_cookie_matches_domain($cookie, $domain) {
  if ($cookie['domain'] === FALSE) {
    $source = $cookie['context']['source'];
    $source_info = visitor_parse_url($source);
    return $source_info['host'] === $domain;
  }
  else {
    if ($cookie['domain'] === '.' . $domain) {
      return TRUE;
    }

    // Cookie domain must be a *suffix* of the target domain.
    $cookie_domain_regex = '@' . str_replace('.', '\.', $cookie['domain']) . '$@';
    return preg_match($cookie_domain_regex, $domain) ? TRUE : FALSE;
  }
}

function visitor_cookiejar_create() {
  $cookiejar = array();
  $cookiejar['cookies'] = array();
  return $cookiejar;
}

function visitor_cookiejar_send_cookies($cookiejar, $url) {
  $url_info = visitor_parse_url($url);

  $request_cookies = array();
  $cookie_query = array(
    'now' => time(),
    'domain' => $url_info['host'],
    'path' => $url_info['path'],
    'scheme' => $url_info['scheme'],
  );

  foreach ($cookiejar['cookies'] as $cookie) {
    if (visitor_cookie_matches($cookie, $cookie_query)) {
      $request_cookies[$cookie['name']] = $cookie;
    }
  }

  return $request_cookies;
}

function visitor_cookiejar_import_response_cookies(&$cookiejar, $response_cookies, $url) {
  $now = time();

  foreach ($response_cookies as $cookie_name => $cookie) {
    if (visitor_cookie_can_be_accepted($cookie, $url)) {
      $cookie_key = visitor_cookiejar_cookie_key($cookie);
      if (isset($cookiejar['cookies'][$cookie_key])) {
        // Instead of updating the "expires" property, just delete the cookie.
        if (isset($cookie['expires_time']) && $cookie['expires_time'] < $now) {
          unset($cookiejar['cookies'][$cookie_key]);
        }
        else {
          // Otherwise just overwrite the data we have for this cookie.
          $cookiejar['cookies'][$cookie_key] = $cookie;
        }
      }
      else {
        // Add the new cookie.
        $cookiejar['cookies'][$cookie_key] = $cookie;
      }
    }
  }
}

function visitor_cookiejar_cookie_key($cookie) {
  // Generate a key using properties that identify a cookie.
  $raw_key = 'name:' . $cookie['name'] . '|' . 'domain:' . $cookie['domain'] . '|' . 'path:' . $cookie['path'];
  $hashed_key = hash('sha256', $raw_key);
  return $hashed_key;
}

function visitor_cookiejar_to_string(&$cookiejar) {
  $json_cookies = array();
  foreach ($cookiejar['cookies'] as $cookie) {
    $json_cookie = $cookie;
    $json_cookies[] = $json_cookie;
  }

  // JSON_PRETTY_PRINT is only available for PHP >= 5.4.
  // For older PHP versions print a non pretty JSON. 
  if (defined('JSON_PRETTY_PRINT')) {
    return json_encode($json_cookies, JSON_PRETTY_PRINT);
  }
  else {
    return json_encode($json_cookies);
  }
}

function visitor_cookiejar_write(&$cookiejar, $file_uri) {
  $string = visitor_cookiejar_to_string($cookiejar);

  if (file_put_contents($file_uri, $string) === FALSE) {
    return FALSE;
  }

  return TRUE;
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

  $url_info = visitor_parse_url($page_url);
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

  // Relative urls will be resolved using the parent page.
  // If a "base" tag is present, its value will be used instead.
  $base_url_info = $url_info;
  $base_tag = $dom->getElementsByTagName('base')->item(0);

  if ($base_tag) {
    $base_tag_href = $base_tag->getAttribute('href');
    if ($base_tag_href) {
      $base_url_info = visitor_parse_url((string) $base_tag_href);
    }
  }

  foreach ($found as $value) {
    $orig_value = $value;

    if (empty($value) || $value[0] == '#') {
      continue;
    }

    $value_info = visitor_parse_relative_url($value, $base_url_info);

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

  $from_root = $from_info['scheme'] . '://' . $from_info['host'];
  if (isset($from_info['port'])) {
    $from_root .= ':' . $from_info['port'];
  }

  $from_base = $from_path;
  if (substr($from_path, -1) !== '/') {
    $from_base = rtrim(dirname($from_path), '/') . '/';
  }

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

  $url_info = visitor_parse_url($url);
  if ($url_info === FALSE) {
    return FALSE;
  }

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
  $assembled = $parsed['scheme'] . '://' . rtrim($parsed['host'], '/\\');

  if (isset($parsed['port'])) {
    $assembled .= ':' . $parsed['port'];
  }

  if (isset($parsed['path'])) {
    $assembled .= '/' . ltrim($parsed['path'], '/\\');
  }

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

function visitor_url_can_be_visited($url, $options = array()) {
  $options += array(
    'internal' => array(),
    'allow_external' => TRUE,
    'exclude' => array(),
  );

  $url_info = visitor_parse_url($url);

  $is_internal = (in_array($url_info['host'], $options['internal']));

  $check = array(
    'status' => TRUE,
    'is_internal' => $is_internal,
  );

  if (!$options['allow_external'] && !$is_internal) {
    $check['status'] = FALSE;
    $check['error'] = array(
      'key' => 'external_not_allowed',
      'message' => visitor_get_error('external_not_allowed', $url),
    );
    return $check;
  }

  $rule_passes = TRUE;
  foreach ($options['exclude'] as $exclude_rule) {
    if ($exclude_rule['type'] == 'regex' && $is_internal) {
      if (preg_match($exclude_rule['regex'], $url)) {
        $rule_passes = FALSE;
      }
    }
    else if ($exclude_rule['type'] == 'domain') {
      if ($exclude_rule['domain'] == $url_info['host']) {
        $rule_passes = FALSE;
      }
    }

    if (!$rule_passes) {
      $check['status'] = FALSE;
      $check['error'] = array(
        'key' => 'excluded_by_rule',
        'rule' => $exclude_rule,
        'message' => visitor_get_error('excluded_by_rule', $url, $exclude_rule),
      );
      break;
    }
  }

  return $check;
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
    'internal' => array(),
    'allow_external' => TRUE,
    'crawl_external' => FALSE,
    'exclude' => array(),
    'time_limit' => 30 * 60,
    'request_max_redirects' => 10,
    'crawlable_response_codes' => array(200, 404),
    'http' => array(),
    'collect' => array(
      'tags' => array(
        '*' => array('src', 'href')
      ),
    ),
    'cookies_enabled' => TRUE,
    'cookiejar' => FALSE,
    'format' => 'code:%code url:%url parent:%parent',
    'print' => TRUE,
  );
}

/**
 * Read argument from a list of parsed command line options.
 */
function visitor_console($cli_args, $options = array()) {
  $args = $cli_args;
  $options += array('silent' => FALSE);

  // Remove script name.
  array_shift($args);

  $input = array();
  $input['error'] = FALSE;
  $input['options'] = visitor_default_options();

  while ((($arg = array_shift($args)) !== NULL) && !$input['error']) {
    // Long options could be passed in the form --LONG_OPT=VALUE.
    if (preg_match('/(--[^=]+)=(.+)/', $arg, $matches)) {
      $arg = $matches[1];
      array_unshift($args, $matches[2]);
    }

    switch ($arg) {
      case '--help':
        visitor_show_usage();
        return;

      case '--project':
        $input['project_file'] = getcwd() . '/visitor.json';
        break;

      case '--project-file':
        $input['project_file'] = trim(array_shift($args));
        break;

      case '-f':
        $input['options']['format'] = trim(array_shift($args));
        break;

      case '-u':
        $input['options']['http']['auth'] = trim(array_shift($args));
        break;

      case '--no-cookies':
        $input['options']['cookies_enabled'] = FALSE;
        break;

      case '--cookiejar':
        $cookiejar_file = trim(array_shift($args));
        if ($cookiejar_file === basename($cookiejar_file)) {
          $cookiejar_file = getcwd() . DIRECTORY_SEPARATOR . $cookiejar_file;
        }

        $input['options']['cookiejar'] = $cookiejar_file;
        break;

      case '-t':
        $input['options']['time_limit'] = intval(trim(array_shift($args)));
        break;

      default:
        $input['start_url'] = trim($arg);
        break;
    }
  }

  // Cookiejar option cannot be set if cookies are disabled.
  if (!$input['options']['cookies_enabled']) {
    $input['options']['cookiejar'] = NULL;
  }

  if (!$input['error'] && !isset($input['start_url']) && !isset($input['project_file'])) {
    $input['error'] = array('key' => 'no_url', 'message' => visitor_get_error('no_url'));
  }

  if (!$input['error'] && isset($input['project_file']) && !file_exists($input['project_file'])) {
    $input['error'] = array('key' => 'invalid_project_file', 'message' => visitor_get_error('invalid_project_file', $project_file));
  }

  $console = array();
  $console['error'] = $input['error'];
  $console['input'] = $input;

  if ($input['error'] === FALSE) {
    if (isset($input['project_file'])) {
      $project = visitor_project_load_file($input['project_file']);

      if ($project['error']) {
        $console['error'] = $project['error'];
      }
      else {
        $console['visitor'] = $project['visitor'];
        $console['visitor']['options'] = array_merge($input['options'], $console['visitor']['options']);
      }
    }
    else {
      $console['visitor'] = visitor_create($input['start_url'], $input['options']);
    }
  }

  if ($console['error'] && !$options['silent']) {
    visitor_show_usage($console['error']['message']);
    exit(1);
  }

  return $console;
}

function visitor_project_load_file($project_file) {
  $project = array();
  $project['error'] = FALSE;

  $content = file_get_contents($project_file);
  if ($content === FALSE) {
    $project['error'] = array(
      'key' => 'project_file_not_readable',
      'message' => visitor_get_error('project_file_not_readable', $project_file),
    );
    return $project;
  }

  $json = json_decode($content, TRUE);
  if ($json === NULL) {
    $project['error'] = array(
      'key' => 'project_file_parse_error',
      'message' => visitor_get_error('project_file_parse_error', $project_file),
    );
    return $project;
  }

  $json += array('options' => array());

  $project['name'] = $json['name'];
  $project['visitor'] = visitor_create($json['start_url'], $json['options']);

  return $project;
}

function visitor_create($start_url, $options = NULL) {
  $visitor = array();
  visitor_reset($visitor);

  $visitor['start_url'] = $start_url;

  if (isset($options)) {
    $options = array_merge(visitor_default_options(), $options);
  }
  else {
    $options = visitor_default_options();
  }

  $visitor['options'] = $options;
  return $visitor;
}

function visitor_reset(&$visitor) {
  $visitor['cookiejar'] = visitor_cookiejar_create();
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

      case 'error':
      case 'warning':
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

  $timer = &$visitor['timers'][$timer_name];

  $timer['current'] = time();
  $timer['expired'] = ($timer['current'] > $timer['expires']);

  return TRUE;
}

function visitor_timer_expired(&$visitor, $timer_name) {
  if (!isset($visitor['timers'][$timer_name])) {
    return FALSE;
  }

  $timer = &$visitor['timers'][$timer_name];

  return $timer['expired'];
}

function visitor_timer_destroy(&$visitor, $timer_name) {
  if (!isset($visitor['timers'][$timer_name])) {
    return FALSE;
  }

  unset($visitor['timers'][$timer_name]);
  return TRUE;
}

function visitor_run(&$visitor) {
  $start_url = $visitor['start_url'];
  $start_info = visitor_parse_url($start_url);

  $visitor['queue'] = array();
  $visitor['queue'][] = array('url' => $start_url, 'url_info' => $start_info);

  $visitor['error'] = FALSE;

  $visitor['options']['internal'][] = $start_info['host'];

  // Cache a reference to visitor options.
  $options = &$visitor['options'];

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
    if (isset($visitor['visited'][$url])) {
      continue;
    }

    $visit = array();
    $visit['parents'] = join(' --> ', $queue_item['parents']);
    $visit['parent'] = end($queue_item['parents']);

    // Try to fetch with HEAD first. In this way if the file is not a web page we avoid
    // the download of unnecessary data.
    $next_item = array(
      'name' => 'request_head',
      'request' => array_merge($options['http'], array(
        'url' => $url,
        'method' => 'HEAD',
        'follow_redirects' => FALSE,
        'cookiejar' => ($options['cookies_enabled'] ? $visitor['cookiejar'] : NULL)
      )),
    );

    $final_res = FALSE;
    $redirects_count = 0;
    $touched_urls = array();
    
    while (isset($next_item)) {
      $cur_item = $next_item;
      $request = &$cur_item['request'];

      $response = visitor_http_request($request);

      $touched_urls[] = $request['url'];

      if (isset($response['cookiejar'])) {
        $visitor['cookiejar'] = $response['cookiejar'];
      }

      if ($response['error']) {
        visitor_log($visitor, array(
          'type' => 'error', 
          'key' => $response['error'],
          'message' => visitor_get_error($response['error'], $url),
        ));

        // Exit loop in case of errors.
        break;
      }
      else if ($response['code'] == 405 && $request['method'] == 'HEAD') {
        $next_item = array();

        if ($cur_item['name'] == 'request_head') {
          $next_item['name'] = 'request_get';
        }
        else if (strpos($cur_item['name'], 'redirect_') === 0) {
          $next_item['name'] = preg_replace_callback('/^redirect_(\d+)_(.+)+/', function($matches) {
            return 'redirect_' . (intval($matches[1]) + 1) . '_get';
          }, $cur_item['name']);
        }

        $next_item['request'] = $request;
        $next_item['request']['method'] = 'GET';
      }
      else if ($response['is_redirect']) {
        $redirects_count++;

        visitor_log($visitor, array(
          'type' => 'redirect',
          'data' => $response + $visit,
        ));

        visitor_timer_tick($visitor, 'queue');

        if ($redirects_count > $options['request_max_redirects']) {
          visitor_log($visitor, array(
            'type' => 'warning', 
            'key' => 'too_many_redirects',
            'data' => array(
              'url' => $url,
            ),
            'message' => visitor_get_error('too_many_redirects', $url),
          ));

          // Stop following redirects.
          break;
        }

        if (visitor_timer_expired($visitor, 'queue')) {
          visitor_log($visitor, array(
            'type' => 'error',
            'key' => 'time_limit_reached',
            'data' => array(
              'time_limit' => $options['time_limit'],
              'last_url' => $url,
            ),
            'message' => visitor_get_error('time_limit_reached', $options['time_limit'], $url)
          ));

          $visitor['error'] = 'time_limit_reached';
          break;
        }

        $next_item = array();
        $next_item['name'] = 'redirect_' . $redirects_count . '_head';
        $next_item['request'] = array_merge($options['http'], array(
          'url' => $response['redirect_url'],
          'method' => 'HEAD',
          'follow_redirects' => FALSE,
          'cookiejar' => ($options['cookies_enabled'] ? $visitor['cookiejar'] : NULL)
        ));
      }
      else {
        // Successful response.
        $final_req = $cur_item;
        $final_res = $response;
        $next_item = NULL;
      }
    }

    if ($final_res !== FALSE) {
      $visit += $final_res;
      $visit['is_internal'] = (in_array($visit['url_info']['host'], $options['internal']));
      visitor_log_visit($visitor, $visit);

      if (in_array($visit['code'], $options['crawlable_response_codes'])) {
        $crawl_allowed = ($options['crawl_external'] || $visit['is_internal']);
        $is_web_page = (strpos($visit['content_type'], 'text/html') === 0);
        $do_crawl = ($crawl_allowed && $is_web_page);
        
        if ($do_crawl) {
          $request_get = array_merge($options['http'], array(
            'url' => $visit['url'],
            'method' => 'GET',
            'follow_redirects' => FALSE,
            'cookiejar' => ($options['cookies_enabled'] ? $visitor['cookiejar'] : NULL)
          ));

          $response_get = visitor_http_request($request_get);

          $urls = visitor_collect_urls($response_get['data'], $visit['url'], $options['collect']);

          $new_parents = array_merge($queue_item['parents'], array($visit['url']));

          foreach ($urls as $collected) {
            $check = visitor_url_can_be_visited($collected['url'], array(
              'internal' => $options['internal'],
              'allow_external' => $options['allow_external'],
              'exclude' => $options['exclude'],
            ));

            if ($check['status']) {
              $collected['is_internal'] = $check['is_internal'];
              $collected['parents'] = $new_parents;
              $visitor['queue'][] = $collected;
            }
          }
        }
      }

      // Remember the visited urls.
      foreach ($touched_urls as $touched_url) {
        $visitor['visited'][$touched_url] = TRUE;
        $options['collect']['exclude'][] = $touched_url;
      }
    }

    visitor_timer_tick($visitor, 'queue');

    if ($visitor['error'] != 'time_limit_reached' && visitor_timer_expired($visitor, 'queue')) {
      visitor_log($visitor, array(
        'type' => 'error',
        'key' => 'time_limit_reached',
        'data' => array(
          'time_limit' => $options['time_limit'],
          'last_url' => $url,
        ),
        'message' => visitor_get_error('time_limit_reached', $options['time_limit'], $url)
      ));
      break;
    }
  }

  // We don't need the "queue" timer anymore.
  visitor_timer_destroy($visitor, 'queue');

  if ($options['cookiejar']) {
    if (visitor_cookiejar_write($visitor['cookiejar'], $options['cookiejar']) === FALSE) {
      visitor_log($visitor, array(
        'type' => 'warning',
        'key' => 'cookiejar_write_error',
        'message' => visitor_get_error('cookiejar_write_error', $options['cookiejar']),
      ));
    }
  }
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

// Read arguments from CLI and create the resulting visitor object.
$console = visitor_console($argv);

// Ensure a console object was created.
// In case a user just wants to show a help message, the console object won't be created.
if (isset($console) && !$console['error']) {
  $visitor = $console['visitor'];

  // Run, run, run, as fast as you can.
  visitor_run($visitor);
}
