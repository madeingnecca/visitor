<?php

class VisitorErrorTest extends VisitorTestBase {  
  public function testVisitorErrorConnectionTimedout() {
    $visitor_options = visitor_default_options();
    $visitor_options['print'] = FALSE;
    $visitor_options['http']['connection_timeout'] = 5;

    $visitor = visitor_create('http://10.255.255.1', $visitor_options);
    visitor_run($visitor);

    $log_len = count($visitor['log']);

    $this->assertNotEquals(0, $log_len);

    $last_log = $visitor['log'][$log_len - 1];

    $this->assertEquals('error', $last_log['type']);
    $this->assertNotEmpty($last_log['key']);
  }

  public function testVisitorErrorTimeLimit() {
    $this->webserverStart();

    $visitor = visitor_create($this->webserverUrl('time_limit'), array(
      'print' => FALSE,
      'http' => array(
        'connection_timeout' => 999
      ),
      'time_limit'=> 30,
    ));

    visitor_run($visitor);

    $log_len = count($visitor['log']);
    $last_log = $visitor['log'][$log_len - 1];

    $this->assertEquals('error', $last_log['type']);
    $this->assertEquals('time_limit_reached', $last_log['key']); 
  }
}
