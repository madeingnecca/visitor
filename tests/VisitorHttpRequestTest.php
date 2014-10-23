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
    $this->assertEquals('http://www.google.it/', $httpbin_response['http_response']['last_redirect']);

    $httpbin_response = $this->httpbinHttpRequest('redirect-to?url=http://www.google.it/i-do-not-exist');
    $this->assertEquals('404', $httpbin_response['http_response']['code']);

    $httpbin_response = $this->httpbinHttpRequest('relative-redirect/10');
    $this->assertEquals('200', $httpbin_response['http_response']['code']);
    $this->assertEquals(10, $httpbin_response['http_response']['redirects_count']);

    $httpbin_response = $this->httpbinHttpRequest('relative-redirect/100');
    $this->assertEquals('302', $httpbin_response['http_response']['code']);
    $this->assertEquals('infinite-loop', $httpbin_response['http_response']['error']);

    $httpbin_response = $this->httpbinHttpRequest('relative-redirect/30', array(
      'http_params' => array('max_redirects' => 200),
    ));
    $this->assertEquals('200', $httpbin_response['http_response']['code']);
    $this->assertNotEquals('infinite-loop', (isset($httpbin_response['http_response']['error']) ? $httpbin_response['http_response']['error'] : ''));
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
}
