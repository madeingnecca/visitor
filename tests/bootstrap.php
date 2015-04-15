<?php

// PHP warnings are annoying yes, but also make phpunit fail.
date_default_timezone_set('UTC');

// Require "Visitor" as a library.
require_once __DIR__ . '/../visitor.php';

// Require our test framework.
require_once __DIR__ . '/VisitorTestBase.php';
