<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Ai\Engine;

use OCA\TalkAi\Ai\Engine;
use OCA\TalkAi\Ai\Errors;
use OCA\TalkAi\Ai\Request;
use OCA\TalkAi\Ai\Status;
use OCA\TalkAi\Ai\TextProtocol;
use OCA\TalkAi\Ai\Turn;
use OCP\Server;

/**
 * Temporary: AI-Hub's engines (the provider set up there for the app) behind our
 * Engine, until the app has engines of its own. AI-Hub's engines take text and
 * images and return text, so tools and answers go by TextProtocol, as they did
 * inside AI-Hub. Only its engines are used: our Loop runs the tools.
 */
final class AiHub implements Engine {
	private const HUB = 'OCA\AIHub\Service\HubService';
	private const ENGINES = 'OCA\AIHub\Engine\EngineFactory';
	/** AI-Hub's own limit of images with one question. */
	private const MAX_IMAGES = 4;

	public function __construct(
		private string $app,
	) {
	}

	public function status(): Status {
		if (!class_exists(self::HUB)) {
			return Status::notReady(Errors::NOT_READY, 'aihub');
		}
		try {
			$s = Server::get(self::HUB)->status($this->app);
		} catch (\Throwable) {
			return Status::notReady(Errors::NOT_READY, 'aihub');
		}
		$provider = (string)$s['provider'];
		return new Status((bool)$s['ready'], $s['ready'] ? '' : ((string)$s['reason'] ?: Errors::NOT_READY), $provider, (string)$s['model'],
			(bool)$s['images'], $s['images'] ? self::MAX_IMAGES : 0, (bool)$s['search'], engine: 'aihub-' . $provider . '-' . ($s['mode'] ?? 'api'));
	}

	public function turn(Request $request): Turn {
		try {
			$engine = Server::get(self::ENGINES)->forApp($this->app);
		} catch (\Throwable) {
			return Turn::failed(Errors::NOT_READY);
		}
		$turns = TextProtocol::flatten($request->messages);
		$last = array_pop($turns);
		if ($last === null || $last['role'] !== 'user') {
			return Turn::failed(Errors::EMPTY);
		}
		$history = array_map(fn (array $t) => ['role' => $t['role'], 'text' => $t['text']] + ($t['images'] !== [] ? ['images' => $t['images']] : []), $turns);
		$system = trim($request->system . "\n\n" . TextProtocol::instructions($request->tools, $request->answerSchema));
		$options = ['search' => $request->search] + ($last['images'] !== [] ? ['images' => $last['images']] : []);
		try {
			$result = $engine->run($history, $last['text'], $system, false, $options);
		} catch (\Throwable $e) {
			return Turn::failed(Errors::provider($e->getMessage()));
		}
		if (!$result->isOk()) {
			return Turn::failed($result->kind === 'auth_error' ? Errors::NO_KEY : self::error((string)$result->detail));
		}
		$parsed = TextProtocol::parse((string)$result->output, array_keys($request->tools));
		return new Turn($parsed['text'], $parsed['calls'], $parsed['answer'], stopReason: $parsed['calls'] !== [] ? 'tools' : 'end');
	}

	public function models(): array {
		try {
			return array_values(array_map('strval', Server::get(self::ENGINES)->forApp($this->app)->listModels()));
		} catch (\Throwable) {
			return [];
		}
	}

	/** AI-Hub's engines say what went wrong in words. */
	private static function error(string $detail): string {
		return match (true) {
			preg_match('/\b429\b|rate.?limit/i', $detail) === 1 => Errors::RATE_LIMITED,
			preg_match('/overloaded|\b529\b|\b503\b|busy/i', $detail) === 1 => Errors::BUSY,
			preg_match('/time(d)? ?out|in time|stalled/i', $detail) === 1 => Errors::TIMEOUT,
			default => Errors::provider($detail !== '' ? $detail : 'no answer'),
		};
	}
}
