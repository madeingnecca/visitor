<?php

class VisitorTestBase extends PHPUnit_Framework_TestCase {
  protected $_config = array();
  protected $_webserver_pid;

  public function setUp() {
    $this->_config = array(
      'webserver' => array(
        'host' => 'localhost',
        'port' => '9000',
        'docroot' => __DIR__ . '/test_sites',
      ),
    );
  }

  public function tearDown() {
    $this->webserverStop();
  }

  public function webserverStart() {
    $command_webserver_start = sprintf(
      'php -S %s:%d -t %s >/dev/null 2>&1 & echo $!',
      $this->_config['webserver']['host'],
      $this->_config['webserver']['port'],
      $this->_config['webserver']['docroot']
    );

    $output = array();
    exec($command_webserver_start, $output);
    $pid = (int) $output[0];

    $this->_webserver_pid = $pid;

    // Wait for the webserver to load completely.
    sleep(3);
  }

  public function webserverStop() {
    if (isset($this->_webserver_pid)) {
      exec('kill ' . $this->_webserver_pid);
    }
  }

  public function webserverUrl($path) {
    return 'http://' . $this->_config['webserver']['host'] . ':' . $this->_config['webserver']['port'] . '/' . ltrim($path, '/');
  }

  public function httpbinHttpRequest($path, $params = array()) {
    $params += array(
      'json' => FALSE,
      'http_params' => array(),
    );

    $result = array();

    $response = visitor_http_request('http://httpbin.org/' . $path, $params['http_params']);
    $result['http_response'] = $response;

    if ($params['json'] && !empty($result['http_response']['data'])) {
      $result['json'] = json_decode($result['http_response']['data'], TRUE);
    }

    return $result;
  }
}