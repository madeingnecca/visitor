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

    $this->assertEquals('http://example.com/image.jpg', $urls[0]['url']);
  }

  public function testVisitorBugWithParseRelativeUrl() {
    $from_info = parse_url('https://example.com/sites/default/files/iframe/eng/index.html');
    $info = visitor_parse_relative_url('blog.example.com/wp-content/themes/example/infografica_eng/img/bancomatfocus/Fronte1.png', $from_info);

    $this->assertEquals('/sites/default/files/iframe/eng/blog.example.com/wp-content/themes/example/infografica_eng/img/bancomatfocus/Fronte1.png', $info['path']);
  }
}
