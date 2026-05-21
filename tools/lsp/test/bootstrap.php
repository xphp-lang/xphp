<?php

declare(strict_types=1);

ini_set('display_errors', '1');
// Mute E_DEPRECATED from phpactor's transitive deps (thecodingmachine/safe) so
// PHPUnit's deprecation-as-fail config doesn't get tripped by their PHP 8.4
// implicit-nullable warnings at autoload time. Our own code still gets full
// error_reporting via ini_set above.
ini_set('error_reporting', (string) (E_ALL & ~E_DEPRECATED));

require dirname(__DIR__) . '/vendor/autoload.php';
