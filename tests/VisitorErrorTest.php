<?php

class VisitorErrorTest extends VisitorTestBase {  
  public function testVisitorErrorConnectionTimedout() {
    $visitor_options = visitor_default_options();
    $visitor_options['print'] = FALSE;
    $visitor_options['http']['connection_timeout'] = 5;

    $visitor = visitor_init('http://10.255.255.1', $visitor_options);
    visitor_run($visitor);

    $log_len = count($visitor['log']);

    $this->assertNotEquals(0, $log_len);

    $last_log = $visitor['log'][$log_len - 1];

    $this->assertEquals('error', $last_log['type']);
    $this->assertNotEmpty($last_log['error']);
  }

  public function testVisitorErrorTimeLimit() {
    $profiler = array();
    $profiler['start'] = time();

    $time_limit = 40;
    $dns_resolve_delay = 0.5;
    $delays = array(3, 4, 5, 6, 7, 8, 9, 10);
    $total_delay = array_sum($delays);

    $visitor_options = visitor_default_options();
    $visitor_options['print'] = FALSE;
    $visitor_options['http']['connection_timeout'] = 999;
    $visitor_options['time_limit'] = $time_limit;

    $visitor = visitor_init('http://httpbin.org/delay/' . (array_shift($delays)), $visitor_options);

    while ($_delay = array_shift($delays)) {
      $url = 'http://httpbin.org/delay/' . $_delay;
      $visitor['queue'][] = array('url' => $url, 'url_info' => parse_url($url));  
    }

    visitor_run($visitor);

    $log_len = count($visitor['log']);
    $last_log = $visitor['log'][$log_len - 1];

    $profiler['end'] = time();

    $elapsed_secs = ($profiler['end'] - $profiler['start']);

    if ($time_limit < $total_delay) {
      $this->assertLessThanOrEqual($dns_resolve_delay * count($delays) + $total_delay, $elapsed_secs);
      $this->assertEquals('error', $last_log['type']);
      $this->assertEquals('time_limit_reached', $last_log['error']);
    }
  }
}
