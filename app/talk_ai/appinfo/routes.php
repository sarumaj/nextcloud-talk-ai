<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

return [
	'routes' => [
		// The bot's answer, worked out off the request that posted the question:
		// the listener hands the question to this route (signed) and walks away.
		['name' => 'process#run', 'url' => '/process', 'verb' => 'POST'],
	],
];
