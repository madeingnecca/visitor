<?php

class VisitorTest extends PHPUnit_Framework_TestCase {
  protected $_config = array();
  protected $_webserver_pid;

  public function setUp() {
    // Require visitor as a library.
    require_once __DIR__ . '/../visitor.php';

    $this->_config = array(
      'webserver' => array(
        'host' => 'localhost',
        'port' => '9000',
        'docroot' => __DIR__ 
      ),
    );
  }

  public function tearDown() {
    $this->webserverStop();
  }

  public function webserverStart() {
    // Command that starts the built-in web server
    $command = sprintf(
      'php -S %s:%d -t %s >/dev/null 2>&1 & echo $!',
      $this->_config['webserver']['host'],
      $this->_config['webserver']['port'],
      $this->_config['webserver']['docroot']
    );

    $output = array();
    exec($command, $output);
    $pid = (int) $output[0];

    $this->_webserver_pid = $pid;
  }

  public function webserverStop() {
    if (isset($this->_webserver_pid)) {
      exec('kill ' . $this->_webserver_pid);
    }
  }

  public function webserverUrl($path) {
    return 'http://' . $this->_config['webserver']['host'] . ':' . $this->_config['webserver']['port'] . '/' . ltrim($path, '/');
  }

  public function testVisitorReadArgumentsNoUrl() {
    $result = visitor_read_arguments(array(
      'visitor.php',
    ));

    $this->assertEquals($result['error'], visitor_get_error('no_url'));

    $result = visitor_read_arguments(array(
      'visitor.php',
      '-f',
      '%code %url'
    ));

    $this->assertEquals($result['error'], visitor_get_error('no_url'));
  }

  public function testVisitorReadArgumentsErrorInvalidPresets() {
    $invalid_presets = array('p1', 'p2');

    $result = visitor_read_arguments(array(
      'visitor.php',
      '-p',
      join('+', $invalid_presets),
    ));

    $this->assertEquals(visitor_get_error('invalid_presets', $invalid_presets), $result['error']);
  }

  public function testVisitorFormatString() {
    $data = array(
      'code' => '200',
      'url' => 'http://domain.com',
      'deep' => array(
        'k1' => 'v1',
        'k2' => array(
          'k2.1' => 'deepest',
        )
      ),
    );

    $this->assertEquals(visitor_format_string('%code %url', $data), $data['code'] . ' ' . $data['url']);
    $this->assertEquals(visitor_format_string('%code %url %deep:k1', $data), $data['code'] . ' ' . $data['url'] . ' ' . $data['deep']['k1']);
    $this->assertEquals(visitor_format_string('%code %url %deep:k2:k2.1', $data), $data['code'] . ' ' . $data['url'] . ' ' . $data['deep']['k2']['k2.1']);
  }

  public function testVisitorResolveRelativeUrl() {
    $this->assertEquals('test.html', visitor_resolve_relative_path('', 'test.html'));
    $this->assertEquals('/test.html', visitor_resolve_relative_path('/', 'test.html'));
    $this->assertEquals('folder/test.html', visitor_resolve_relative_path('folder', 'test.html'));
    $this->assertEquals('/test.html', visitor_resolve_relative_path('/', './test.html'));
    $this->assertEquals('/test.html?bobo=3&b=444', visitor_resolve_relative_path('/', 'test.html?bobo=3&b=444'));
    $this->assertEquals('/test.html', visitor_resolve_relative_path('//folder1/', '../test.html'));
    $this->assertEquals('/test.html', visitor_resolve_relative_path('//folder1///', '../test.html'));
    $this->assertEquals('/folder1/folder2/test.html', visitor_resolve_relative_path('//folder1/folder2/folder3/', '../test.html'));
    $this->assertEquals('/folder1/test.html', visitor_resolve_relative_path('//folder1/folder2/folder3/', '../../test.html'));
    $this->assertEquals('/test.html', visitor_resolve_relative_path('//folder1/folder2/folder3/', '../../../test.html'));
    $this->assertEquals('/test.html', visitor_resolve_relative_path('//folder1/folder2/folder3/', './../../../test.html'));
    $this->assertEquals('/test.html', visitor_resolve_relative_path('//folder1/folder2/folder3/', '/././../../.././test.html'));
    $this->assertEquals(FALSE, visitor_resolve_relative_path('/', '../test.html'));
  }

  public function testVisitorHttpRequestResponseCodes() {
    $response = visitor_http_request('http://httpbin.org/status/200');
    $this->assertEquals('200', $response['code']);

    $response = visitor_http_request('http://httpbin.org/status/404');
    $this->assertEquals('404', $response['code']);

    $response = visitor_http_request('http://httpbin.org/status/500');
    $this->assertEquals('500', $response['code']);
  }

  public function testVisitorHttpRequestRedirects() {
    $response = visitor_http_request('http://httpbin.org/redirect-to?url=http://www.google.it/');
    $this->assertEquals('200', $response['code']);
    $this->assertEquals('http://www.google.it/', $response['last_redirect']);

    $response = visitor_http_request('http://httpbin.org/redirect-to?url=http://www.google.it/i-do-not-exist');
    $this->assertEquals('404', $response['code']);

    $response = visitor_http_request('http://httpbin.org/relative-redirect/10');
    $this->assertEquals('200', $response['code']);
    $this->assertEquals(10, $response['redirects_count']);

    $response = visitor_http_request('http://httpbin.org/relative-redirect/100');
    $this->assertEquals('302', $response['code']);
    $this->assertEquals('infinite-loop', $response['error']);

    $response = visitor_http_request('http://httpbin.org/relative-redirect/30', array(
      'max_redirects' => 200,
    ));
    $this->assertEquals('200', $response['code']);
    $this->assertNotEquals('infinite-loop', (isset($response['error']) ? $response['error'] : ''));
  }
}
