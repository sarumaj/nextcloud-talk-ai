<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

/*
 * An MCP server on stdio for a command line model (Claude Code): it lists the
 * question's tools and hands each call to the PHP process that runs the question
 * (Engine\ClaudeCli), over a Unix socket only that process and this one know, and
 * waits for the result. The tools run there, in Loop, with the asking person's
 * rights; this script runs nothing itself and needs no Nextcloud.
 *
 * Environment: TALKAI_BRIDGE_SOCKET (the socket's path), TALKAI_BRIDGE_TOKEN (sent
 * with each call, so another local process that finds the socket cannot pose as
 * this one), TALKAI_BRIDGE_TOOLS (a JSON file: name => {description, input}).
 */

$socket = (string)getenv('TALKAI_BRIDGE_SOCKET');
$token = (string)getenv('TALKAI_BRIDGE_TOKEN');
$tools = json_decode((string)@file_get_contents((string)getenv('TALKAI_BRIDGE_TOOLS')), true);
$tools = is_array($tools) ? $tools : [];

$reply = function (mixed $id, ?array $result, ?array $error = null): void {
	$message = ['jsonrpc' => '2.0', 'id' => $id] + ($error !== null ? ['error' => $error] : ['result' => $result]);
	fwrite(STDOUT, json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
	fflush(STDOUT);
};

/** One call through the socket: the result's content blocks, or an error text. */
$call = function (string $id, string $name, array $args) use ($socket, $token): array {
	$conn = @stream_socket_client('unix://' . $socket, $errno, $errstr, 10);
	if ($conn === false) {
		return [[['type' => 'text', 'text' => 'The tool could not be reached: ' . $errstr]], true];
	}
	// The tool may take as long as it takes (a document build); the engine watches the time.
	stream_set_timeout($conn, 86400);
	fwrite($conn, json_encode(['token' => $token, 'id' => $id, 'name' => $name, 'args' => (object)$args], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
	$line = fgets($conn);
	fclose($conn);
	$result = is_string($line) ? json_decode($line, true) : null;
	if (!is_array($result)) {
		return [[['type' => 'text', 'text' => 'The tool gave no result.']], true];
	}
	$content = [['type' => 'text', 'text' => (string)($result['content'] ?? '')]];
	foreach ((array)($result['images'] ?? []) as $image) {
		$content[] = ['type' => 'image', 'data' => (string)$image['data'], 'mimeType' => (string)$image['type']];
	}
	return [$content, (bool)($result['error'] ?? false)];
};

while (($line = fgets(STDIN)) !== false) {
	$message = json_decode($line, true);
	if (!is_array($message) || !isset($message['method'])) {
		continue;
	}
	$id = $message['id'] ?? null;
	$params = (array)($message['params'] ?? []);
	switch ($message['method']) {
		case 'initialize':
			$reply($id, ['protocolVersion' => (string)($params['protocolVersion'] ?? '2025-06-18'), 'capabilities' => ['tools' => (object)[]],
				'serverInfo' => ['name' => 'talkai', 'version' => '1.0']]);
			break;
		case 'tools/list':
			$list = [];
			foreach ($tools as $name => $tool) {
				$schema = (array)($tool['input'] ?? ['type' => 'object']);
				$schema['properties'] = (object)($schema['properties'] ?? []);
				$list[] = ['name' => (string)$name, 'description' => (string)($tool['description'] ?? ''), 'inputSchema' => $schema];
			}
			$reply($id, ['tools' => $list]);
			break;
		case 'tools/call':
			$name = (string)($params['name'] ?? '');
			[$content, $isError] = isset($tools[$name])
				// Claude Code names the tool_use block a call answers: the engine pairs them by it.
				? $call((string)($params['_meta']['claudecode/toolUseId'] ?? ''), $name, (array)($params['arguments'] ?? []))
				: [[['type' => 'text', 'text' => 'There is no tool named ' . $name . '.']], true];
			$reply($id, ['content' => $content, 'isError' => $isError]);
			break;
		case 'ping':
			$reply($id, []);
			break;
		default:
			// Notifications (no id) need no answer; unknown requests get "method not found".
			if ($id !== null) {
				$reply($id, null, ['code' => -32601, 'message' => 'Method not found']);
			}
	}
}
