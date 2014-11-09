<?php

class VisitorBugsTest extends VisitorTestBase {
  public function testVisitorBugWithFolderCollect() {
    $page_html = '<body><img src="image.jpg"></body>';

    $page_url = 'http://example.com/folder/';
    $urls = visitor_collect_urls($page_html, $page_url, array(
      'tags' => array('img' => array('src'))
    ));

    $this->assertEquals('http://example.com/folder/image.jpg', $urls[0]['url']);

    $page_url = 'http://example.com/folder';
    $urls = visitor_collect_urls($page_html, $page_url, array(
      'tags' => array('img' => array('src'))
    ));

    $this->assertEquals('http://example.com/folder/image.jpg', $urls[0]['url']);
  }
}
