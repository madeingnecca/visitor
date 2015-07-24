<?php

class VisitorHttpRequestTest extends VisitorTestBase {
  public function testVisitorHttpRequestResponseCodes() {
    $httpbin_response = $this->httpbinHttpRequest('status/200');
    $this->assertEquals('200', $httpbin_response['http_response']['code']);

    $httpbin_response = $this->httpbinHttpRequest('status/404');
    $this->assertEquals('404', $httpbin_response['http_response']['code']);

    $httpbin_response = $this->httpbinHttpRequest('status/500');
    $this->assertEquals('500', $httpbin_response['http_response']['code']);
  }

  public function testVisitorHttpRequestRedirects() {
    $httpbin_response = $this->httpbinHttpRequest('redirect-to?url=http://www.google.it/');
    $this->assertEquals('200', $httpbin_response['http_response']['code']);

    $httpbin_response = $this->httpbinHttpRequest('redirect-to?url=http://www.google.it/i-do-not-exist');
    $this->assertEquals('404', $httpbin_response['http_response']['code']);

    $httpbin_response = $this->httpbinHttpRequest('relative-redirect/100');
    $this->assertEquals('302', $httpbin_response['http_response']['code']);
    $this->assertEquals('too_many_redirects', $httpbin_response['http_response']['error']);

    $httpbin_response = $this->httpbinHttpRequest('relative-redirect/30', array(
      'http_params' => array('max_redirects' => 200),
    ));
    $this->assertEquals('200', $httpbin_response['http_response']['code']);
    $this->assertNotEquals('too_many_redirects', (isset($httpbin_response['http_response']['error']) ? $httpbin_response['http_response']['error'] : ''));
  }

  public function testVisitorHttpRequestUserAgent() {
    $httpbin_response = $this->httpbinHttpRequest('user-agent', array(
      'http_params' => array(
        'user_agent' => 'my_custom_user_agent',
      ),
      'json' => TRUE,
    ));

    $this->assertEquals('200', $httpbin_response['http_response']['code']);
    $this->assertEquals('my_custom_user_agent', $httpbin_response['json']['user-agent']);
  }

  public function testVisitorHttpRequestCookies() {
    $httpbin_response = $this->httpbinHttpRequest('cookies', array(
      'json' => TRUE,
    ));

    $this->assertArrayHasKey('cookies', $httpbin_response['json']);
    $this->assertArrayHasKey('cookies', $httpbin_response['http_response']);
    $this->assertEquals($httpbin_response['json']['cookies'], $httpbin_response['http_response']['cookies']);
  }

  public function testVisitorHttpRequestBasicAuthSuccess() {
    $httpbin_response = $this->httpbinHttpRequest('basic-auth/user/passwd', array(
      'http_params' => array(
        'auth' => 'user:passwd',
      ),
    ));

    $this->assertEquals('200', $httpbin_response['http_response']['code']);
  }

  public function testVisitorHttpRequestBasicAuthFail() {
    $httpbin_response = $this->httpbinHttpRequest('basic-auth/user/passwd', array(
      'http_params' => array(
        'auth' => 'user:wrongpassword',
      ),
    ));

    $this->assertEquals('401', $httpbin_response['http_response']['code']);
  }

  public function testVisitorHttpRequestConnectionTimeout() {
    $connection_timeout_secs = 20;
    $profiler = array();
    $profiler['start'] = time();

    // Thanks
    // http://stackoverflow.com/questions/100841/artificially-create-a-connection-timeout-error
    $http_response = visitor_http_request('http://10.255.255.1', array(
      'connection_timeout' => $connection_timeout_secs,
    ));

    $profiler['end'] = time();

    $elapsed_secs = ($profiler['end'] - $profiler['start']);

    $this->assertEquals('connection_timedout', $http_response['error']);
    $this->assertEquals($elapsed_secs, $connection_timeout_secs);
  }
}
