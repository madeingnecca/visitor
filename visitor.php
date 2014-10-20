<?php

function visitor_show_usage() {
  global $argv;
  print "Usage: \n";
  print $argv[0] . " [-f -u] <url>\n";
  print "  -f: String to output whenever a new url is collected. \n";
  print "    Available variables: %url, %code, %content_type, %parent, %headers:<header_name_lowercase>\n";
  print "  -u: Authentication credentials, <user>:<pass>\n";
  print "  -p: Presets to load. Choose between: " . (join(', ', array_keys(visitor_preset_list()))) . "\n";
  print "    Multiple presets can be specified using a plus (+) separator. Presets will be merged together.\n";
  print "  --accept-cookies: Names of the cookies to accept. Use '*' to accept all cookies.\n";
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
      $cookie = visitor_http_request_parse_cookie($cookie_data);
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

function visitor_http_request_parse_cookie($cookie_data) {
  $cookie = array(
    'path' => '/',
    'secure' => FALSE,
    'httpdonly' => FALSE,
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
    $url_info['path'] = visitor_resolve_relative_url($from_base, $url_info['path']);
  }

  return $url_info;
}

function visitor_resolve_relative_url($base_path, $rel_path) {
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

function visitor_parse_arguments($args) {
  $default_params = array(
    'http' => array(),
    'collect' => array(
      'allow_external' => FALSE,
    ),
    'accept-cookies' => FALSE,
    'format' => '%url %code',
    'exclude' => FALSE,
    'print' => TRUE,
  );

  $preset_list = visitor_preset_list();
  $presets_chosen = array(key($preset_list));

  $params = $default_params;
  foreach ($presets_chosen as $preset_params) {
    $params = array_merge_recursive($params, $preset_list[$preset_params]);
  }

  $result = array();
  $result['error'] = FALSE;
  $result['params'] = $params;
  $result['start_url'] = 'http://www.arper.com';
  return $result;
}

function visitor_init($start_url, $params) {
  $visitor = array();
  $visitor['cookies'] = array();
  $visitor['queue'] = array();
  $visitor['visited'] = array();
  $visitor['params'] = array();
  $visitor['print'] = array();
  $visitor['start_url'] = $start_url;
  $visitor['params'] = $params;
  return $visitor;
}

function visitor_process(&$visitor) {
  $queue = array();
  $cookies = array();
  $params = $visitor['params'];

  // Start url is always the last parameter.
  $start_url = $visitor['start_url'];

  $start_info = parse_url($start_url);
  $start_info += array('scheme' => 'http', 'path' => '');

  $visited = array();
  $queue[] = array('url' => $start_url, 'url_info' => $start_info);

  $print_visit = function($data) use ($visitor, $params) {
    if ($visitor['params']['print']) {
      print visitor_format_url($params['format'], $data);
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
    if ($params['exclude'] !== FALSE && preg_match('@' . $params['exclude'] . '@', $url)) {
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
    $response_head = visitor_http_request($url, array_merge($params['http'], array(
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
      $response = visitor_http_request($url, array_merge($params['http'], array(
        'cookies' => $request_cookies,
      )));

      // If the response contains cookies, accept only those specified by arguments.
      if (!empty($response['cookies'])) {
        foreach ($response['cookies'] as $response_cookie) {
          if ($params['accept-cookies'] !== FALSE) {
            if ($params['accept-cookies'] == '*' || in_array($response_cookie['name'], $params['accept-cookies'])) {
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

          $collect_redirect = $params['collect']['allow_external'] || ($last_redirect_info['host'] == $start_info['host']);
          $collect = $collect && $collect_redirect;
        }
      }

      $print_visit($visit);

      $is_web_page = (strpos($response['content_type'], 'text/html') === 0);

      // Collect urls only if it was a successful response, a page containing html
      // and collection was requested.
      if ($response['code'] == 200 && $is_web_page && $collect) {
        $urls = visitor_collect_urls($response['data'], $url, $params['collect']);

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

// Check for visitor_requirements first.
visitor_requirements();

$visitor_args = visitor_parse_arguments($argv);

$visitor = visitor_init($visitor_args['start_url'], $visitor_args['params']);

visitor_process($visitor);
