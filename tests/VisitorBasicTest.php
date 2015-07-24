<?php

class VisitorBasicTest extends VisitorTestBase {
  public function testVisitorConsole() {
    $console = visitor_console(array(
      'visitor.php',
    ));

    $this->assertEquals($console['error']['key'], 'no_url');
    $this->assertEquals($console['error']['message'], visitor_get_error('no_url'));

    $console = visitor_console(array(
      'visitor.php',
      '-f',
      '%code %url'
    ));

    $this->assertEquals($console['error']['key'], 'no_url');
    $this->assertEquals($console['error']['message'], visitor_get_error('no_url'));
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

  public function testVisitorResolveRelativePath() {
    $this->assertEquals('test.html', visitor_resolve_relative_path('', 'test.html'));
    $this->assertEquals('/test.html', visitor_resolve_relative_path('/', 'test.html'));
    $this->assertEquals('test.html', visitor_resolve_relative_path('folder', 'test.html'));
    $this->assertEquals('folder/test.html', visitor_resolve_relative_path('folder/', 'test.html'));
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
    $this->assertEquals('/image.jpg', visitor_resolve_relative_path('/en', 'image.jpg'));
    $this->assertEquals('/en/image.jpg', visitor_resolve_relative_path('/en/', 'image.jpg'));
  }

  public function testVisitorParseRelativeUrl() {
    $from_info = parse_url('http://example.com/a/b/c/folder');
    $info = visitor_parse_relative_url('image.jpg', $from_info);

    $this->assertEquals('/a/b/c/image.jpg', $info['path']);

    $from_info = parse_url('http://example.com/a/b/c/folder/');
    $info = visitor_parse_relative_url('image.jpg', $from_info);

    $this->assertEquals('/a/b/c/folder/image.jpg', $info['path']);
  }
}
