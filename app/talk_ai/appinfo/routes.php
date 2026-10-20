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
	],
	'ocs' => [
		// The bot in Matrix: an adapter hands in the rooms' messages and carries out what the bot does there.
		['name' => 'matrix#info', 'url' => '/api/v1/matrix/info', 'verb' => 'GET'],
		['name' => 'matrix#message', 'url' => '/api/v1/matrix/messages', 'verb' => 'POST'],
		['name' => 'matrix#observe', 'url' => '/api/v1/matrix/observe', 'verb' => 'POST'],
		['name' => 'matrix#redaction', 'url' => '/api/v1/matrix/redactions', 'verb' => 'POST'],
		['name' => 'matrix#reaction', 'url' => '/api/v1/matrix/reactions', 'verb' => 'POST'],
		['name' => 'matrix#joined', 'url' => '/api/v1/matrix/joined', 'verb' => 'POST'],
		['name' => 'matrix#outbox', 'url' => '/api/v1/matrix/outbox', 'verb' => 'GET'],
		['name' => 'matrix#acknowledge', 'url' => '/api/v1/matrix/outbox/ack', 'verb' => 'POST'],
		['name' => 'matrix#posted', 'url' => '/api/v1/matrix/posted', 'verb' => 'POST'],
	],
];
