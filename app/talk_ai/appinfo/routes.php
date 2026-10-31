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
		// The status of a slow answer or job, shown while it runs.
		['name' => 'ticker#run', 'url' => '/ticker', 'verb' => 'POST'],
		// The administration page.
		['name' => 'settings#save', 'url' => '/settings', 'verb' => 'PUT'],
		['name' => 'settings#status', 'url' => '/settings/status', 'verb' => 'GET'],
		['name' => 'settings#cancel', 'url' => '/settings/jobs/{id}/cancel', 'verb' => 'POST'],
		['name' => 'settings#upload', 'url' => '/settings/styles/{style}/files', 'verb' => 'POST'],
		// A question of the OCS API, handed over the same way (Ai\Tickets).
		['name' => 'ticket#run', 'url' => '/api/v1/run', 'verb' => 'POST'],
	],
	// The AI layer for other clients: /ocs/v2.php/apps/talk_ai/api/v1/… (docs/developer.md).
	'ocs' => [
		['name' => 'api#status', 'url' => '/api/v1/status', 'verb' => 'GET'],
		['name' => 'api#ask', 'url' => '/api/v1/ask', 'verb' => 'POST'],
		['name' => 'api#result', 'url' => '/api/v1/result/{id}', 'verb' => 'GET'],
		['name' => 'api#forget', 'url' => '/api/v1/forget', 'verb' => 'POST'],
	],
];
