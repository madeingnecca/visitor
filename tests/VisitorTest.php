<?php

class VisitorTest extends PHPUnit_Framework_TestCase {
  public function setUp() {
    require_once __DIR__ . '/../visitor.php';
  }

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
      'url' => 'http://www.google.it',
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
}
