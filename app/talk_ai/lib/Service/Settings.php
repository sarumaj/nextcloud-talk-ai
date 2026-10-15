<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Service;

use OCA\TalkAi\AppInfo\Application;
use OCP\IAppConfig;

/**
 * The app's settings for the administration: each with its type, default, limits
 * and an explanation (the page and occ config:app:set read the same keys).
 */
class Settings {
	/** key => [section, type, default, min, max, label, help] */
	public const FIELDS = [
		'enabled' => ['general', 'bool', 'yes', null, null, 'The bot answers', 'Off: it answers nobody (emergency stop); running document jobs are cancelled.'],
		'allow_guests' => ['general', 'bool', 'no', null, null, 'Answer guests and federated participants', 'They get chat answers only: no files, calendars or documents.'],
		'history_window' => ['general', 'int', '30', 5, 200, 'Messages per answer', 'How many recent messages of the conversation go with each question; older ones are summarized.'],
		'context_tokens' => ['general', 'int', '0', 0, 2000000, 'Context size of the model (tokens)', '0: known from the model\'s name. The context is cut to fit.'],
		'transparency_text' => ['general', 'text', '', null, null, 'Notice when the bot is enabled in a conversation', 'Empty: names the AI provider and model from AI-Hub, and the location below.'],
		'provider_location' => ['general', 'string', '', null, null, 'Where the AI provider processes the data', 'For the notice, e.g. "the USA (Anthropic)" or "this server (Ollama)".'],
		'max_jobs_per_user' => ['quotas', 'int', '2', 1, 20, 'Document jobs per person at the same time', 'Waiting or running; more are refused until one is done.'],
		'quota_jobs' => ['quotas', 'int', '0', 0, 1000, 'Document jobs per person and day', '0: no limit.'],
		'quota_seconds' => ['quotas', 'int', '0', 0, 86400, 'Seconds of document building per person and day', '0: no limit.'],
		'quota_tokens' => ['quotas', 'int', '0', 0, 100000000, 'Tokens per person and day (estimated)', '0: no limit. AI-Hub reports no use; the app estimates from the characters sent and received.'],
		'cost_in' => ['quotas', 'float', '0', 0, 1000, 'Cost per million input tokens', 'For the cost estimate in the statistics (in your currency).'],
		'cost_out' => ['quotas', 'float', '0', 0, 1000, 'Cost per million output tokens', 'For the cost estimate in the statistics.'],
		'document_max_tools' => ['documents', 'int', '60', 5, 300, 'Tool calls per document job', 'The hard limit of the model\'s steps (F-01).'],
		'document_max_seconds' => ['documents', 'int', '900', 60, 3600, 'Time per document job (seconds)', 'The container is removed after this; the runner\'s own limit caps it.'],
		'document_corrections' => ['documents', 'int', '3', 0, 10, 'Correction rounds after the render check', 'Then the best result is delivered with a note.'],
		'document_cpus' => ['documents', 'int', '2', 1, 16, 'CPUs per sandbox container', 'Capped by the runner (RUNNER_CPUS).'],
		'document_memory_mb' => ['documents', 'int', '2048', 256, 16384, 'Memory per sandbox container (MB)', 'Capped by the runner (RUNNER_MEMORY_MB).'],
		'document_disk_mb' => ['documents', 'int', '1024', 64, 8192, 'Working space per container (MB)', '/work, in memory; capped by the runner.'],
		'document_folder' => ['documents', 'string', 'Talk-AI', null, null, 'Folder for documents', 'In the person\'s files, with a folder per day.'],
		'preview_wait' => ['documents', 'int', '120', 0, 600, 'Seconds to wait for an answer to a preview', '0: no preview before delivering. People can switch previews off per conversation.'],
		'trace_days' => ['retention', 'int', '14', 7, 365, 'Days to keep the protocols of document jobs', 'In the person\'s files (Talk-AI/…/Traces); at least 7.'],
		'retention_days' => ['retention', 'int', '90', 7, 3650, 'Days to keep job and answer records, use and conversation memories', 'Then they are deleted.'],
		'retention_files_days' => ['retention', 'int', '0', 0, 3650, 'Days to keep delivered documents', '0: never deleted (they are the people\'s files).'],
		'debug_hours' => ['debug', 'int', '0', 0, 24, 'Debug log for the next hours', 'Questions and answers go to the Nextcloud log, with e-mail addresses, IBANs and numbers masked. Ends by itself.'],
	];

	public const GROUP_FEATURES = ['documents', 'sandbox', 'web', 'calendar', 'youth'];

	public function __construct(
		private IAppConfig $appConfig,
		private Jobs $jobs,
		private Requests $requests,
		private Health $health,
		private Policy $policy,
		private Switches $switches,
	) {
	}

	public function all(): array {
		$values = [];
		foreach (self::FIELDS as $key => [$section, $type, $default]) {
			$values[$key] = $key === 'debug_hours' ? max(0, (int)ceil(((int)$this->get('debug_until', '0') - time()) / 3600)) : $this->get($key, $default);
		}
		return [
			'values' => $values,
			'features' => json_decode($this->get('features', '{}'), true) ?: (object)[],
			'styles' => json_decode($this->get('styles', '[]'), true) ?: [],
			'rooms_off' => $this->switches->list('rooms_off'),
			'users_off' => $this->switches->list('users_off'),
		];
	}

	/** Saves the given settings, checked; the errors by key. */
	public function save(array $input): array {
		$errors = [];
		foreach ($input['values'] ?? [] as $key => $value) {
			if (!isset(self::FIELDS[$key])) {
				continue;
			}
			[, $type, , $min, $max] = self::FIELDS[$key];
			$value = match ($type) {
				'bool' => in_array($value, [true, 'yes', '1', 1], true) ? 'yes' : 'no',
				'int' => (string)(int)$value,
				'float' => (string)(float)$value,
				default => mb_substr(trim((string)$value), 0, 2000),
			};
			if (in_array($type, ['int', 'float'], true) && (($min !== null && (float)$value < $min) || ($max !== null && (float)$value > $max))) {
				$errors[$key] = sprintf('between %s and %s', $min, $max);
				continue;
			}
			if ($key === 'debug_hours') {
				$this->set('debug_until', (string)((int)$value > 0 ? time() + (int)$value * 3600 : 0));
				continue;
			}
			if ($key === 'enabled' && $value === 'no') {
				$this->switches->stop('all');
				continue;
			}
			$this->set($key, $value);
		}
		if (isset($input['features']) && is_array($input['features'])) {
			$features = [];
			foreach (self::GROUP_FEATURES as $feature) {
				$groups = array_values(array_filter(array_map('trim', (array)($input['features'][$feature] ?? [])), fn ($g) => $g !== ''));
				if ($groups !== []) {
					$features[$feature] = $groups;
				}
			}
			$this->set('features', json_encode((object)$features));
		}
		foreach (['rooms_off' => 'room', 'users_off' => 'user'] as $key => $scope) {
			if (isset($input[$key]) && is_array($input[$key])) {
				$wanted = array_values(array_filter(array_map('trim', $input[$key])));
				foreach (array_diff($wanted, $this->switches->list($key)) as $id) {
					$this->switches->stop($scope, $id);
				}
				foreach (array_diff($this->switches->list($key), $wanted) as $id) {
					$this->switches->start($scope, $id);
				}
			}
		}
		if (isset($input['styles']) && is_array($input['styles'])) {
			$styles = [];
			foreach ($input['styles'] as $style) {
				if (!is_array($style) || trim((string)($style['name'] ?? '')) === '') {
					continue;
				}
				$styles[] = [
					'name' => mb_substr(trim((string)$style['name']), 0, 100),
					'groups' => array_values(array_filter(array_map('trim', (array)($style['groups'] ?? [])))),
					'description' => mb_substr(trim((string)($style['description'] ?? '')), 0, 2000),
					'palette' => array_values(array_filter((array)($style['palette'] ?? []), fn ($c) => preg_match('/^#[0-9a-f]{6}$/i', (string)$c) === 1)),
					'fonts' => ['heading' => mb_substr((string)($style['fonts']['heading'] ?? ''), 0, 100), 'body' => mb_substr((string)($style['fonts']['body'] ?? ''), 0, 100)],
					'templates' => array_filter(array_map('strval', (array)($style['templates'] ?? []))),
					'logo' => (string)($style['logo'] ?? ''),
				];
			}
			$this->set('styles', json_encode($styles, JSON_UNESCAPED_UNICODE));
		}
		return $errors;
	}

	/** The state for the page: health, queue, answers, statistics. */
	public function status(bool $deep = false): array {
		$since30 = time() - 30 * 86400;
		$sums = $this->requests->sums($since30);
		$costIn = (float)$this->get('cost_in', '0');
		$costOut = (float)$this->get('cost_out', '0');
		$cost = fn (array $b) => round(($b['tokens_in'] * $costIn + $b['tokens_out'] * $costOut) / 1e6, 2);
		$top = function (array $buckets) use ($cost): array {
			uasort($buckets, fn ($a, $b) => ($b['tokens_in'] + $b['tokens_out']) <=> ($a['tokens_in'] + $a['tokens_out']));
			return array_map(fn ($b) => $b + ['cost' => $cost($b)], array_slice($buckets, 0, 15, true));
		};
		return [
			'health' => $this->health->check($deep),
			'jobs' => array_map(fn ($j) => ['id' => $j['id'], 'user' => $j['user_id'], 'status' => $j['status'], 'name' => $j['name'] . '.' . $j['format'],
				'created' => date('Y-m-d H:i', $j['created_at']), 'seconds' => $j['finished_at'] > 0 ? $j['finished_at'] - $j['started_at'] : null,
				'tools' => $j['tool_calls'], 'rounds' => $j['rounds'], 'tokens' => $j['tokens'], 'outcome' => mb_substr((string)$j['outcome'], 0, 300),
				'trace' => $j['trace_path'], 'rating' => $j['options']['rating']['value'] ?? null], $this->jobs->recent(50)),
			'requests' => array_map(fn ($r) => ['id' => $r['request_id'], 'time' => date('Y-m-d H:i:s', (int)$r['created_at']), 'user' => $r['user_id'],
				'room' => $r['token'], 'status' => $r['status'], 'model' => $r['model'], 'tokens_in' => (int)$r['tokens_in'], 'tokens_out' => (int)$r['tokens_out'],
				'ms' => (int)$r['duration_ms'], 'tools' => json_decode((string)$r['tools'], true) ?: [], 'error' => $r['error']], $this->requests->recent(100)),
			'golden' => json_decode($this->get('golden_results', '{}'), true) ?: (object)[],
			'stats' => [
				'jobs_7' => $this->jobs->stats(time() - 7 * 86400),
				'jobs_30' => $this->jobs->stats($since30),
				'answers_30' => $sums['total'] + ['cost' => $cost($sums['total'])],
				'by_user' => $top($sums['users']),
				'by_room' => $top($sums['rooms']),
				'by_day' => array_map(fn ($b) => $b + ['cost' => $cost($b)], $sums['days']),
			],
		];
	}

	private function get(string $key, string $default): string {
		return $this->appConfig->getValueString(Application::APP_ID, $key, $default);
	}

	private function set(string $key, string $value): void {
		$this->appConfig->setValueString(Application::APP_ID, $key, $value);
	}
}
