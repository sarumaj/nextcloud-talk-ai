<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

// Nextcloud's coding standard, for the app's PHP and the tests.

require_once __DIR__ . '/vendor/autoload.php';

use Nextcloud\CodingStandard\Config;

$config = new Config();
$config
	->getFinder()
	->in(__DIR__ . '/app/talk_ai')
	->in(__DIR__ . '/tests')
	->append([__FILE__]);

return $config;
