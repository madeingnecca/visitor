<?php

class VisitorHttpRequestTest extends VisitorTestBase {
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
