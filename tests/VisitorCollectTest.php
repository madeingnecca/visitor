<?php

class VisitorCollectTest extends VisitorTestBase {
  public function testVisitorCollectHttpbinLinks() {
    $httpbin_num_links = 10;

    $httpbin_response = $this->httpbinHttpRequest('links/' . $httpbin_num_links . '/0');

    $this->assertEquals('200', $httpbin_response['http_response']['code']);
    $this->assertArrayHasKey('data', $httpbin_response['http_response']);

    $page_html = $httpbin_response['http_response']['data'];
    $this->assertTrue(strlen($page_html) > 0);

    $urls = visitor_collect_urls($page_html, 'http://httpbin.org/links/' . $httpbin_num_links . '/0', array(
      'tags' => array('a' => array('href')),
    ));

    $this->assertEquals($httpbin_num_links - 1, count($urls));
  }

  public function testVisitorCollectWithExclude() {
    $httpbin_num_links = 10;

    $httpbin_response = $this->httpbinHttpRequest('links/' . $httpbin_num_links . '/0');

    $page_html = $httpbin_response['http_response']['data'];

    $exclude = array(
      'http://httpbin.org/links/' . $httpbin_num_links . '/1',
      'http://httpbin.org/links/' . $httpbin_num_links . '/2',
    );

    $urls = visitor_collect_urls($page_html, 'http://httpbin.org/links/' . $httpbin_num_links . '/0', array(
      'tags' => array('a' => array('href')),
      'exclude' => $exclude,
    ));

    $urls_list = array();
    foreach ($urls as $item) {
      $urls_list[] = $item['url'];
    }

    $this->assertNotEquals($httpbin_num_links - 1, count($urls));
    $this->assertEquals($httpbin_num_links - 1 - count($exclude), count($urls));
    $this->assertEmpty(array_intersect($urls_list, $exclude));
  }
}