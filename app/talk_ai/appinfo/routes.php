<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

return [
	'routes' => [
		// The bot's answer, worked out off the request that posted the question:
		// the listener hands the question to this route (signed) and walks away.
		['name' => 'process#run', 'url' => '/process', 'verb' => 'POST'],
		// A document job (built in the sandbox), handed over the same way.
		['name' => 'document#run', 'url' => '/document', 'verb' => 'POST'],
		// The administration page.
		['name' => 'settings#save', 'url' => '/settings', 'verb' => 'PUT'],
		['name' => 'settings#status', 'url' => '/settings/status', 'verb' => 'GET'],
		['name' => 'settings#cancel', 'url' => '/settings/jobs/{id}/cancel', 'verb' => 'POST'],
		['name' => 'settings#upload', 'url' => '/settings/styles/{style}/files', 'verb' => 'POST'],
	],
];
