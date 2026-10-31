<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Ai;

/**
 * The error words of the layer: what Turn::$error, Status::$reason and Hub's
 * results say. A provider's own message comes as "provider:<message>", without
 * keys or URLs (Errors::provider() cuts them out).
 */
final class Errors {
	public const NOT_READY = 'not-ready';
	public const NO_KEY = 'no-key';
	public const NO_CLI = 'no-cli';
	public const NO_MODEL = 'no-model';
	public const USER_NOT_ALLOWED = 'user-not-allowed';
	public const APP_NOT_ALLOWED = 'app-not-allowed';
	public const NO_SCENARIO = 'no-scenario';
	public const EMPTY = 'empty';
	public const BUSY = 'busy';
	public const RATE_LIMITED = 'rate-limited';
	public const TIMEOUT = 'timeout';
	public const NO_IMAGES = 'no-images';
	public const TOO_MANY_IMAGES = 'too-many-images';
	public const IMAGE_TOO_LARGE = 'image-too-large';
	public const IMAGE_TYPE = 'image-type';
	public const BAD_ANSWER = 'bad-answer';
	/** Loop's own: the heartbeat said stop. */
	public const CANCELLED = 'cancelled';

	/** Worth asking again after a pause: the provider was busy, slow or briefly down. */
	public static function transient(string $error): bool {
		return in_array($error, [self::BUSY, self::RATE_LIMITED, self::TIMEOUT], true)
			|| preg_match('/^provider:.*(\b5\d\d\b|overloaded|unavailable|connection|time(d)? ?out)/i', $error) === 1;
	}

	/** The next engine of a fallback chain may answer instead. */
	public static function passOn(string $error): bool {
		return self::transient($error) || in_array($error, [self::NOT_READY, self::NO_KEY, self::NO_CLI, self::NO_MODEL], true);
	}

	/** A provider's message as an error word: one line, no secrets, at most 300 characters. */
	public static function provider(string $message): string {
		$message = preg_replace([
			'/\b(sk|key|AIza|xai|gsk)[-_][A-Za-z0-9_\-]{8,}/', '/([?&](key|api_key|token)=)[^&\s]+/i', '/Bearer\s+\S+/i',
		], ['[key]', '$1[key]', 'Bearer [key]'], $message) ?? '';
		return 'provider:' . mb_substr(trim(preg_replace('/\s+/', ' ', $message) ?? ''), 0, 300);
	}

	/** An HTTP status as an error word. */
	public static function http(int $status, string $message = ''): string {
		return match (true) {
			$status === 429 => self::RATE_LIMITED,
			$status === 529, $status === 503 => self::BUSY,
			$status === 408, $status === 504 => self::TIMEOUT,
			$status === 401, $status === 403 => self::NO_KEY,
			default => self::provider('HTTP ' . $status . ($message !== '' ? ': ' . $message : '')),
		};
	}
}
