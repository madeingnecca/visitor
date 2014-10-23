<?php

class VisitorTestBase extends PHPUnit_Framework_TestCase {
  protected $_config = array();
  protected $_webserver_pid;

  public function setUp() {
    $this->_config = array(
      'webserver' => array(
        'host' => 'localhost',
        'port' => '9000',
        'docroot' => __DIR__,
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