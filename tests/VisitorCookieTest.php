<?php

class VisitorCookieTEst extends VisitorTestBase {
  public function testVisitorCookieParse() {
    // Cookie 1.
    $cookie_string = 'name2=value2; Expires=Wed, 09 Jun 2021 10:18:14 GMT';

    $cookie = visitor_parse_cookie($cookie_string);

    $this->assertArrayHasKey('name', $cookie);
    $this->assertArrayHasKey('value', $cookie);
    $this->assertArrayHasKey('expires', $cookie);
    $this->assertEquals('name2', $cookie['name']);
    $this->assertEquals('value2', $cookie['value']);
    $this->assertEquals('Wed, 09 Jun 2021 10:18:14 GMT', $cookie['expires']);
    $this->assertNotEmpty($cookie['expires_time']);


    // Cookie 2.
    $cookie_string = 'SSID=Ap4PGTEq; Domain=foo.com; Path=/; Expires=Wed, 13 Jan 2021 22:23:01 GMT; Secure; HttpOnly';

    $cookie = visitor_parse_cookie($cookie_string);

    $this->assertArrayHasKey('name', $cookie);
    $this->assertArrayHasKey('value', $cookie);
    $this->assertArrayHasKey('domain', $cookie);
    $this->assertArrayHasKey('expires', $cookie);
    $this->assertArrayHasKey('path', $cookie);
    $this->assertArrayHasKey('secure', $cookie);
    $this->assertArrayHasKey('httponly', $cookie);
    $this->assertNotEmpty($cookie['expires_time']);
    $this->assertEquals('SSID', $cookie['name']);
    $this->assertEquals('Ap4PGTEq', $cookie['value']);
    $this->assertEquals('Wed, 13 Jan 2021 22:23:01 GMT', $cookie['expires']);
    $this->assertEquals(TRUE, $cookie['secure']);
    $this->assertEquals(FALSE, $cookie['session']);
    $this->assertEquals(TRUE, $cookie['httponly']);
  }
}
