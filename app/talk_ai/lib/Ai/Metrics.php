<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Ai;

/**
 * The engines in use (the chosen one, the fallback chain, the scenarios' own) as
 * Prometheus metrics, for `occ talk_ai:metrics` and the /metrics route: ready,
 * signed in, last answer, last error, and when the sign-in or key runs out where
 * the administrator entered it (the engine setting "expires"). The same for every
 * engine: what it knows comes from its status and Watch, never from a provider's
 * own files. No key, prompt or answer is in them.
 */
class Metrics {
	public function __construct(
		private Engines $engines,
		private Config $config,
		private Watch $watch,
	) {
	}

	/** @return array<string, string> the engines in use, name => role (chosen, fallback, scenario) */
	public function inUse(): array {
		$out = [];
		if ($this->engines->chosen() !== '') {
			$out[$this->engines->chosen()] = 'chosen';
		}
		foreach ($this->engines->fallback() as $name) {
			$out[$name] ??= 'fallback';
		}
		foreach (array_keys(Admin::SCENARIOS) as $scenario) {
			$name = (string)($this->engines->scenario($scenario)['engine'] ?? '');
			if (in_array($name, Engines::NAMES, true)) {
				$out[$name] ??= 'scenario';
			}
		}
		return $out;
	}

	/** @return list<string> the engines in use that failed since their last answer, or have not answered for a day */
	public function stale(?int $now = null): array {
		$seen = $this->watch->all();
		$now ??= time();
		return array_values(array_filter(array_keys($this->inUse()), function (string $name) use ($seen, $now): bool {
			$row = $seen[$name] ?? ['last_ok' => 0, 'last_error_at' => 0];
			return $row['last_ok'] < $now - 86400 || ($row['last_error_at'] > 0 && $row['last_error_at'] >= $row['last_ok']);
		}));
	}

	/** The metrics in Prometheus' text format. */
	public function render(?int $now = null): string {
		$seen = $this->watch->all();
		$series = [];
		foreach ($this->inUse() as $name => $role) {
			try {
				$status = $this->engines->get($name)->status();
			} catch (\Throwable $e) {
				$status = Status::notReady(Errors::provider($e->getMessage()), $name);
			}
			$row = $seen[$name] ?? ['last_ok' => 0, 'last_error_at' => 0, 'last_error' => ''];
			// Signed out: the engine says so before any request, or its last turn failed so after its last answer.
			$failing = $row['last_error_at'] > 0 && $row['last_error_at'] >= $row['last_ok'];
			$authOk = $status->reason !== Errors::NO_KEY && !($failing && $row['last_error'] === Errors::NO_KEY);
			$labels = ['engine' => $name];
			$series['talk_ai_engine_info'][] = [['engine' => $name, 'role' => $role, 'provider' => $status->provider, 'model' => $status->model], 1];
			$series['talk_ai_engine_ready'][] = [$labels, $status->ready ? 1 : 0];
			$series['talk_ai_engine_auth_ok'][] = [$labels, $authOk ? 1 : 0];
			$series['talk_ai_engine_last_success_timestamp_seconds'][] = [$labels, $row['last_ok']];
			$series['talk_ai_engine_last_error_timestamp_seconds'][] = [$labels, $row['last_error_at']];
			if ($failing) {
				// The error word only: a provider's message varies and would make a new series each time.
				$series['talk_ai_engine_failing_info'][] = [['engine' => $name, 'error' => str_starts_with($row['last_error'], 'provider:') ? 'provider' : $row['last_error']], 1];
			}
			$expires = self::expiry((string)($this->config->engine($name)['expires'] ?? ''));
			if ($expires !== null) {
				$series['talk_ai_engine_credential_expiry_timestamp_seconds'][] = [$labels, $expires];
			}
		}
		$series['talk_ai_metrics_timestamp_seconds'][] = [[], $now ?? time()];
		$out = '';
		foreach ($series as $metric => $samples) {
			$out .= '# HELP ' . $metric . ' ' . self::HELP[$metric] . "\n# TYPE " . $metric . " gauge\n";
			foreach ($samples as [$labels, $value]) {
				$out .= $metric . self::labels($labels) . ' ' . $value . "\n";
			}
		}
		return $out;
	}

	/** The end of the day the setting names (Y-m-d, UTC); null when there is none or it is no date. */
	public static function expiry(string $day): ?int {
		$date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day, new \DateTimeZone('UTC'));
		return $date !== false && $date->format('Y-m-d') === $day ? $date->getTimestamp() + 86399 : null;
	}

	private const HELP = [
		'talk_ai_engine_info' => 'An engine in use (role: chosen, fallback, scenario), with its provider and model.',
		'talk_ai_engine_ready' => 'Whether the engine is set up (no model call).',
		'talk_ai_engine_auth_ok' => '0 when the engine is not signed in or its last turn failed for its sign-in or key.',
		'talk_ai_engine_last_success_timestamp_seconds' => 'When the engine last answered (0: never).',
		'talk_ai_engine_last_error_timestamp_seconds' => 'When a turn of the engine last failed (0: never).',
		'talk_ai_engine_failing_info' => 'The engine failed after its last answer, with the error word.',
		'talk_ai_engine_credential_expiry_timestamp_seconds' => 'When the sign-in or key runs out (the engine setting expires).',
		'talk_ai_metrics_timestamp_seconds' => 'When these metrics were made.',
	];

	private static function labels(array $labels): string {
		if ($labels === []) {
			return '';
		}
		$pairs = [];
		foreach ($labels as $name => $value) {
			$pairs[] = $name . '="' . str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], (string)$value) . '"';
		}
		return '{' . implode(',', $pairs) . '}';
	}
}
