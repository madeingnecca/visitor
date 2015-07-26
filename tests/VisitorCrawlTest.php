<?php

class VisitorCrawlTest extends VisitorTestBase {
  public function testVisitorCrawlHttpbinLinks() {
    $httpbin_generated_links = array(
      'http://httpbin.org/links/10/0',
      'http://httpbin.org/links/10/1',
      'http://httpbin.org/links/10/2',
      'http://httpbin.org/links/10/3',
      'http://httpbin.org/links/10/4',
      'http://httpbin.org/links/10/5',
      'http://httpbin.org/links/10/6',
      'http://httpbin.org/links/10/7',
      'http://httpbin.org/links/10/8',
      'http://httpbin.org/links/10/9',
    );

    $httpbin_num_links = count($httpbin_generated_links);

    $visitor_options = visitor_default_options();
    $visitor_options['print'] = FALSE;
    $visitor_options['collect'] = array(
      'tags' => array('a' => array('href')),
    );

    $visitor = visitor_create('http://httpbin.org/links/10/0', $visitor_options);
    visitor_run($visitor);

    $this->assertNotEmpty($visitor['visited']);
    $this->assertEquals(10, count($visitor['visited']));
    $this->assertEmpty(array_diff($httpbin_generated_links, array_keys($visitor['visited'])));
    $this->assertEmpty(array_diff(array_keys($visitor['visited']), $httpbin_generated_links));
  }
}
