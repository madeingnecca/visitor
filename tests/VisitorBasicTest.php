<?php

class VisitorBasicTest extends VisitorTestBase {
  public function testVisitorReadArgumentsNoUrl() {
    $result = visitor_read_arguments(array(
      'visitor.php',
    ));

    $this->assertEquals($result['error'], visitor_get_error('no_url'));

    $result = visitor_read_arguments(array(
      'visitor.php',
      '-f',
      '%code %url'
    ));

    $this->assertEquals($result['error'], visitor_get_error('no_url'));
  }

  public function testVisitorReadArgumentsErrorInvalidPresets() {
    $invalid_presets = array('p1', 'p2');

    $result = visitor_read_arguments(array(
      'visitor.php',
      '-p',
      join('+', $invalid_presets),
    ));

    $this->assertEquals(visitor_get_error('invalid_presets', $invalid_presets), $result['error']);
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
    $from_info = parse_url('http://example.com/folder');
    $info = visitor_parse_relative_url('image.jpg', $from_info);

    $this->assertEquals('/image.jpg', $info['path']);

    $from_info = parse_url('http://example.com/folder/');
    $info = visitor_parse_relative_url('image.jpg', $from_info);

    $this->assertEquals('/folder/image.jpg', $info['path']);
  }
}
