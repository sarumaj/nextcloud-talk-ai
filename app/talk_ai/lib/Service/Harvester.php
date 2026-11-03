<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Service;

use OCA\TalkAi\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IURLGenerator;

/**
 * The External App Harvester (appid harvester): its recipe files, the person's templates and
 * jobs, through AppAPI as the asking person (PublicFunctions::exAppRequest, which signs the
 * request with the ExApp's secret and the user's id; Harvester answers for that user only).
 *
 * Optional, like the sandbox's External App: without AppAPI, without Harvester installed and
 * enabled, or with the app setting harvester = no (the default), nothing here is offered.
 *
 * What goes to the model never holds an account or a session: Harvester keeps those in
 * Nextcloud's preferences, not in a job, and its credentials route is never called. A job's
 * listing drops its options (its recipe comes by the recipe file Harvester exports, whose
 * steps lose the account's values) and the address of a page that asked for a person, which
 * may carry a token; a value a recipe fills into a field that looks like a password's is
 * replaced by HIDDEN, and put back from the job when a fixed recipe is applied to it.
 */
class Harvester {
	public const APP = 'harvester';
	/** The recipe file's kind and the largest file Harvester reads (recipe_file.py). */
	public const KIND = 'harvester-recipe';
	public const MAX_BYTES = 256000;
	/** A value kept from the model; a fixed recipe that keeps it gets the job's own back. */
	public const HIDDEN = '(hidden: kept from the job)';
	/** HIDDEN while a fixed recipe is checked, before the job's own value takes its place. */
	private const PLACEHOLDER = '__talk_ai_hidden__';
	/** Log lines handed to the model by default and at most, and characters of one line. */
	public const LOG_LINES = 40;
	public const LOG_LINES_MAX = 200;
	private const LINE_CHARS = 400;
	/** Characters of a text from Harvester (a run's message, why it waits). */
	private const TEXT_CHARS = 600;
	/** Seconds a test is waited for at most, and between two looks at the log. */
	public const TEST_WAIT_MAX = 90;
	private const TEST_POLL = 5;
	/** A fill step's field that looks like a password's (as Harvester's recipe.PASSWORD_FIELD). */
	private const PASSWORD_FIELD = '/pass|pwd|(?<![a-z])pw(?![a-z])|current-password|new-password/i';
	/** The fields of a job as Harvester's form sends them (main.JobIn). */
	private const JOB_FIELDS = ['name', 'source', 'target', 'schedule', 'options', 'tags', 'enabled', 'timezone', 'once'];

	/**
	 * The recipe format for the model, from Harvester's guide (docs/user.md, "Recipes" and
	 * "Recipes as files"): what a model needs to write a valid file; Harvester's check says
	 * the rest.
	 */
	public const FORMAT = 'A recipe file (JSON): {"format": "harvester-recipe", "version": 1, "name": "…", "source": "site", '
		. '"schedule": "0 6 * * *" (cron, in the person\'s time zone; "" = on demand: it runs on an address sent to it), "once": false, '
		. '"tags": ["{year}"], "options": {…}}. Never an account, password, token, cookie or folder (the person sets them in Harvester). '
		. 'Sources: site (a website, by a recipe), feed, media (yt-dlp), gallery (gallery-dl), mail (IMAP attachments), remote (rclone), '
		. 'releases; options a source lacks are refused. Every source: file_type (any, pdf, zip, image, audio, video, html, markdown; '
		. 'several comma-separated), min_size (bytes), max_size (MB), max_items (files per run, -1: no limit), unpack (0/1). site: recipe '
		. '(an object), path (file name, default "{filename}"), download_url ("{url}"), browser (1: Chromium for pages built by '
		. 'JavaScript; steps always use it), max_pages (10), stop_at_seen (1), weekly_test (0/1). A site recipe: {"start": the listing\'s '
		. 'URL, "steps": [optional sign-in or preparation steps], "follow": [levels of links to follow first: {"selector": CSS, '
		. '"pattern": a regex on the link\'s URL whose named groups (?P<year>\\d{4}) become values}], "links": {"selector": CSS of the '
		. 'file links, "pattern": regex}, "next": {"selector": the next page\'s link, "level": 0} or {"kind": "number", "selector": the '
		. 'pager, "level": 0}}. A step: {"action": goto (url) | click | fill (value) | press (key, e.g. Enter) | select (value) | hover | '
		. 'scroll (rounds) | popup | opener | each (selector, values: "options"; repeats for each choice, {choice}) | ask-code | approve | '
		. 'handover, "selector": CSS or Playwright (text-is("…"), internal:role=button[name="…"s]), "credential": "username" | '
		. '"password" | "totp" (fills the account\'s; never write a password), "optional": true (a consent banner), "pause": seconds, '
		. '"retry": {"times": 3, "delay": 2}, "wait": {"url": regex, "selector": CSS}}. Fill values may use {today}, '
		. '{today-30d:%d.%m.%Y}, {year}. A level or the links may have "click": true (elements without an address; the pattern is '
		. 'searched in their text), "values": [{"name", "closest", "selector", "attribute", "regex", "type": text|number|date}], '
		. '"scroll": rounds, "load_more": CSS, "wait_for": {"selector", "timeout", "reload"}; links may be {"kind": "json", "url", '
		. '"items", "file", "text"} for a listing in JSON, "save": "pdf"|"html"|"markdown" (pages saved as files) or "capture": '
		. '["application/pdf"]. {"start": …, "save": "pdf"} saves the start page; {"start": …, "watch": {"selector": …}} watches a page '
		. 'for changes. Values for path and tags: the groups, {url}, {text}, {filename}, {stem}, {ext}, {page}, {host}, {title}, '
		. '{disposition}. Example: {"format": "harvester-recipe", "version": 1, "name": "Minutes", "source": "site", "schedule": '
		. '"0 6 * * 1", "tags": ["Minutes"], "options": {"recipe": {"start": "https://example.org/minutes", "links": {"selector": '
		. '"a[href]", "pattern": "\\\\.pdf(\\\\?|$)"}}, "path": "{filename}", "file_type": "pdf"}}. Without steps the pages are fetched '
		. 'over HTTP. Keep a recipe as small as the task needs.';

	public function __construct(
		private IAppConfig $appConfig,
		private IURLGenerator $urls,
	) {
	}

	/** Whether the administrator switched the integration on (app setting harvester). */
	public function switchedOn(): bool {
		return $this->appConfig->getValueString(Application::APP_ID, 'harvester', 'no') === 'yes';
	}

	/** Whether AppAPI is there and Harvester installed and enabled in it. */
	public function installed(): bool {
		$api = $this->appApi();
		if ($api === null) {
			return false;
		}
		try {
			$exApp = $api->getExApp(self::APP);
		} catch (\Throwable) {
			return false;
		}
		return is_array($exApp) && !empty($exApp['enabled']);
	}

	/** Whether the tools are offered: switched on, and Harvester there. */
	public function available(): bool {
		return $this->switchedOn() && $this->installed();
	}

	/** Harvester's page in Nextcloud (its top menu entry, through AppAPI). */
	public function pageUrl(): string {
		try {
			return $this->urls->linkToRouteAbsolute('app_api.TopMenu.viewExAppPage', ['appId' => self::APP, 'name' => self::APP]);
		} catch (\Throwable) {
			return $this->urls->getAbsoluteURL('/index.php/apps/app_api/embedded/' . self::APP . '/' . self::APP);
		}
	}

	/**
	 * Checks a recipe file as Harvester's import does (nothing is saved).
	 *
	 * @return array{ok: bool, job?: array, problems?: string} the job it describes, or why it is refused
	 */
	public function check(string $userId, string $text): array {
		if (strlen($text) > self::MAX_BYTES) {
			return ['ok' => false, 'problems' => 'The file is larger than a recipe (' . intdiv(self::MAX_BYTES, 1000) . ' KB at most).'];
		}
		if (str_contains($text, self::HIDDEN)) {
			return ['ok' => false, 'problems' => 'A step still holds "' . self::HIDDEN . '": a value only the job has. Leave that step\'s value out '
				. 'and mark it as the account\'s ("credential": "password" or "username"), which the person sets in Harvester.'];
		}
		[$status, $body] = $this->call($userId, 'POST', '/api/recipes/import', ['text' => $text]);
		if ($status === 422 || $status === 400) {
			return ['ok' => false, 'problems' => self::detail($body)];
		}
		if ($status !== 200 || !is_array($body)) {
			throw new \RuntimeException('Harvester answered HTTP ' . $status . ': ' . self::detail($body));
		}
		return ['ok' => true, 'job' => $body];
	}

	/**
	 * Keeps a checked job as the person's template under a name (Harvester keeps 50, the oldest
	 * go first). Harvester saves its recipe file: no account, session or folder.
	 *
	 * @return array{ok: bool, template?: array{id: int, name: string, file: array}, problems?: string}
	 */
	public function saveTemplate(string $userId, array $job, string $name): array {
		$body = self::jobBody($job + ['target' => '/']);
		$name = trim($name);
		if ($name !== '') {
			$body['name'] = mb_substr($name, 0, 200);
		}
		if ($body['target'] === '') {
			$body['target'] = '/';
		}
		[$status, $answer] = $this->call($userId, 'POST', '/api/templates', $body);
		if ($status === 422 || $status === 400) {
			return ['ok' => false, 'problems' => self::detail($answer)];
		}
		if ($status !== 200 || !is_array($answer)) {
			throw new \RuntimeException('Harvester answered HTTP ' . $status . ': ' . self::detail($answer));
		}
		return ['ok' => true, 'template' => ['id' => (int)($answer['id'] ?? 0), 'name' => (string)($answer['name'] ?? $body['name']),
			'file' => is_array($answer['file'] ?? null) ? $answer['file'] : []]];
	}

	/** @return list<array> the person's templates: id, name and a short summary, newest last */
	public function templates(string $userId): array {
		return array_map(fn (array $t) => ['id' => (int)($t['id'] ?? 0), 'name' => (string)($t['name'] ?? '')]
			+ self::summary(is_array($t['file'] ?? null) ? $t['file'] : []), $this->rawTemplates($userId));
	}

	/** One of the person's templates as its recipe file (values like passwords hidden), or null. */
	public function template(string $userId, int $id): ?array {
		foreach ($this->rawTemplates($userId) as $t) {
			if ((int)($t['id'] ?? 0) === $id && is_array($t['file'] ?? null)) {
				return self::hidden($t['file']);
			}
		}
		return null;
	}

	/** Removes one of the person's templates; false when they have none of that id. */
	public function deleteTemplate(string $userId, int $id): bool {
		[$status] = $this->call($userId, 'DELETE', '/api/templates/' . $id);
		return $status === 200;
	}

	/**
	 * The person's jobs and how they stand: no options, no account, no address of a page that
	 * asked for a person.
	 *
	 * @return list<array>
	 */
	public function jobs(string $userId): array {
		return array_map(fn (array $job) => self::listed($job), $this->rawJobs($userId));
	}

	/** @return list<array{id: int, at: string, level: string, message: string, shot: bool}> a job's latest log lines, oldest first */
	public function log(string $userId, int $jobId, int $count = self::LOG_LINES, int $after = 0): array {
		[$status, $body] = $this->call($userId, 'GET', '/api/log', ['job' => $jobId, 'after' => max(0, $after)]);
		if ($status === 404) {
			throw new \RuntimeException('no such job of the person\'s in Harvester');
		}
		if ($status !== 200 || !is_array($body)) {
			throw new \RuntimeException('Harvester answered HTTP ' . $status . ': ' . self::detail($body));
		}
		$lines = array_slice(array_values((array)($body['lines'] ?? [])), -max(1, min(self::LOG_LINES_MAX, $count)));
		return array_map(fn ($l) => [
			'id' => (int)($l['id'] ?? 0),
			'at' => date('Y-m-d H:i:s', (int)($l['at'] ?? 0)),
			'level' => (string)($l['level'] ?? ''),
			'message' => mb_substr((string)($l['message'] ?? ''), 0, self::LINE_CHARS),
			'shot' => !empty($l['shot']),
		], array_filter($lines, 'is_array'));
	}

	/**
	 * What the job's browser showed at its last failed step or challenge: when, the page's
	 * address and title, what went wrong, and the picture (a JPEG, base64); null when none.
	 *
	 * @return array{at: string, url: string, title: string, what: string, image: string}|null
	 */
	public function shot(string $userId, int $jobId): ?array {
		[$status, $body] = $this->call($userId, 'GET', '/api/jobs/' . $jobId . '/shot');
		if ($status === 404 || !is_array($body)) {
			return null;
		}
		if ($status !== 200) {
			throw new \RuntimeException('Harvester answered HTTP ' . $status . ': ' . self::detail($body));
		}
		return ['at' => date('Y-m-d H:i:s', (int)($body['at'] ?? 0)), 'url' => mb_substr((string)($body['url'] ?? ''), 0, 500),
			'title' => mb_substr((string)($body['title'] ?? ''), 0, 300), 'what' => mb_substr((string)($body['what'] ?? ''), 0, self::TEXT_CHARS),
			'image' => (string)($body['image'] ?? '')];
	}

	/** A job's recipe file, as Harvester exports it (no account, session or folder; password-like values hidden). */
	public function recipe(string $userId, int $jobId): array {
		$job = $this->rawJob($userId, $jobId);
		[$status, $body] = $this->call($userId, 'POST', '/api/recipes/export', self::jobBody($job));
		if ($status !== 200 || !is_array($body)) {
			throw new \RuntimeException('Harvester did not export the recipe (HTTP ' . $status . '): ' . self::detail($body));
		}
		return self::hidden($body);
	}

	/**
	 * Asks Harvester to test a website job's recipe (its walk, nothing downloaded; after the
	 * run going on), and waits up to $wait seconds for the lines it logs.
	 *
	 * @return array{requested: bool, why?: string, lines?: list<array>}
	 */
	public function test(string $userId, int $jobId, int $wait = 0): array {
		$before = $this->log($userId, $jobId, 1);
		$last = $before === [] ? 0 : $before[count($before) - 1]['id'];
		[$status, $body] = $this->call($userId, 'POST', '/api/jobs/' . $jobId . '/test');
		if ($status === 409 || $status === 404) {
			return ['requested' => false, 'why' => self::detail($body)];
		}
		if ($status !== 200) {
			throw new \RuntimeException('Harvester answered HTTP ' . $status . ': ' . self::detail($body));
		}
		$lines = [];
		$waited = 0;
		$wait = max(0, min(self::TEST_WAIT_MAX, $wait));
		while ($waited < $wait) {
			$this->pause(self::TEST_POLL);
			$waited += self::TEST_POLL;
			$new = $this->log($userId, $jobId, self::LOG_LINES, $last);
			if ($new !== []) {
				$lines = [...$lines, ...$new];
				$last = $new[count($new) - 1]['id'];
			}
		}
		return ['requested' => true, 'lines' => array_slice($lines, -self::LOG_LINES)];
	}

	/**
	 * Puts a fixed recipe file into an existing job: its options (the recipe and the settings
	 * the file has) over the job's; the job's name, folder, schedule, time zone, tags, one-time
	 * flag and whether it is on stay as they are, and Harvester keeps its account. A value the
	 * listing hid is the job's own again. The file is checked as an import first.
	 *
	 * @return array{ok: bool, problems?: string, before?: array, job?: array} before: the job's settings as they were, for undo
	 */
	public function apply(string $userId, int $jobId, string $text): array {
		$job = $this->rawJob($userId, $jobId);
		$checked = $this->check($userId, self::marked($text));
		if (!$checked['ok']) {
			return $checked;
		}
		$fixed = $checked['job'];
		if ((string)($fixed['source'] ?? '') !== (string)($job['source'] ?? '')) {
			return ['ok' => false, 'problems' => 'The recipe is for the source ' . ($fixed['source'] ?? '?') . ', the job is of ' . ($job['source'] ?? '?') . '.'];
		}
		$options = array_merge((array)($job['options'] ?? []), (array)($fixed['options'] ?? []));
		if (isset($options['recipe']) && is_string($options['recipe'])) {
			$restored = self::restore($options['recipe'], (string)($job['options']['recipe'] ?? ''));
			if ($restored === null) {
				return ['ok' => false, 'problems' => 'A step keeps a hidden value the job does not have at that step (the same action and selector). '
					. 'Keep such steps as they were, or mark the value as the account\'s ("credential").'];
			}
			$options['recipe'] = $restored;
		}
		$before = self::jobBody($job);
		$body = ['options' => $options] + $before;
		[$status, $answer] = $this->call($userId, 'PUT', '/api/jobs/' . $jobId, self::jobBody($body));
		if ($status === 422 || $status === 400) {
			return ['ok' => false, 'problems' => self::detail($answer)];
		}
		if ($status !== 200 || !is_array($answer)) {
			throw new \RuntimeException('Harvester answered HTTP ' . $status . ': ' . self::detail($answer));
		}
		return ['ok' => true, 'before' => $before, 'job' => self::listed($answer)];
	}

	/** Puts a job's settings back as they were before apply() (undo); false when the job is gone. */
	public function restoreJob(string $userId, int $jobId, array $before): bool {
		[$status] = $this->call($userId, 'PUT', '/api/jobs/' . $jobId, self::jobBody($before));
		return $status === 200;
	}

	/** The person's job as Harvester holds it (internal: never handed to the model as it is). */
	private function rawJob(string $userId, int $jobId): array {
		foreach ($this->rawJobs($userId) as $job) {
			if ((int)($job['id'] ?? 0) === $jobId) {
				return $job;
			}
		}
		throw new \RuntimeException('no job ' . $jobId . ' of the person\'s in Harvester (list the jobs for their ids)');
	}

	/** @return list<array> */
	private function rawJobs(string $userId): array {
		[$status, $body] = $this->call($userId, 'GET', '/api/jobs');
		if ($status !== 200 || !is_array($body)) {
			throw new \RuntimeException('Harvester answered HTTP ' . $status . ': ' . self::detail($body));
		}
		return array_values(array_filter($body, 'is_array'));
	}

	/** @return list<array> */
	private function rawTemplates(string $userId): array {
		[$status, $body] = $this->call($userId, 'GET', '/api/templates');
		if ($status !== 200 || !is_array($body)) {
			throw new \RuntimeException('Harvester answered HTTP ' . $status . ': ' . self::detail($body));
		}
		return array_values(array_filter($body, 'is_array'));
	}

	/** A job for the model: how it stands, without its options, account or a challenge's address. */
	private static function listed(array $job): array {
		$asking = is_array($job['asking'] ?? null) ? $job['asking'] : null;
		$recipe = is_string($job['options']['recipe'] ?? null) ? json_decode($job['options']['recipe'], true) : null;
		return array_filter([
			'id' => (int)($job['id'] ?? 0),
			'name' => (string)($job['name'] ?? ''),
			'source' => (string)($job['source'] ?? ''),
			'folder' => (string)($job['target'] ?? ''),
			'schedule' => (string)($job['schedule'] ?? '') === '' ? 'on demand' : (string)$job['schedule'] . ' (' . ($job['timezone'] ?? 'UTC') . ')',
			'enabled' => (bool)($job['enabled'] ?? true),
			'site' => is_array($recipe) ? (parse_url((string)($recipe['start'] ?? ''), PHP_URL_HOST) ?: '') : '',
			'last_run' => !empty($job['last_run']) ? date('Y-m-d H:i', (int)$job['last_run']) : 'never',
			'last_status' => (string)($job['last_status'] ?? ''),
			'last_message' => mb_substr((string)($job['last_message'] ?? ''), 0, self::TEXT_CHARS),
			'needs_person' => !empty($job['challenge_url']) || $asking !== null,
			'asking' => $asking !== null ? mb_substr((string)($asking['kind'] ?? '') . ': ' . ($asking['label'] ?? ''), 0, 200) : '',
			'running' => !empty($job['running']),
			'queued' => !empty($job['queued']),
			'paused' => !empty($job['paused']),
			'waiting' => mb_substr((string)($job['waiting'] ?? ''), 0, self::TEXT_CHARS),
			'addresses_waiting' => count((array)($job['fetches'] ?? [])),
		], fn ($v, $k) => $k === 'id' || $k === 'enabled' || ($v !== '' && $v !== 0 && $v !== false), ARRAY_FILTER_USE_BOTH);
	}

	/** A recipe file's gist: source, schedule, the site, its steps and levels. */
	private static function summary(array $file): array {
		$recipe = $file['options']['recipe'] ?? null;
		if (is_string($recipe)) {
			$recipe = json_decode($recipe, true);
		}
		$out = ['source' => (string)($file['source'] ?? ''), 'schedule' => (string)($file['schedule'] ?? '') === '' ? 'on demand' : (string)$file['schedule']];
		if (is_array($recipe)) {
			$out['start'] = mb_substr((string)($recipe['start'] ?? ''), 0, 300);
			$out['steps'] = count((array)($recipe['steps'] ?? []));
			$out['levels'] = count((array)($recipe['follow'] ?? []));
			$out['kind'] = match (true) {
				isset($recipe['watch']) => 'watches a page',
				isset($recipe['save']) => 'saves the page as ' . $recipe['save'],
				isset($recipe['links']['save']) => 'saves linked pages as ' . $recipe['links']['save'],
				default => 'downloads files',
			};
		}
		return $out;
	}

	/** A recipe file with the values of password-like fields hidden. */
	public static function hidden(array $file): array {
		$recipe = $file['options']['recipe'] ?? null;
		$asText = is_string($recipe);
		if ($asText) {
			$recipe = json_decode($recipe, true);
		}
		if (!is_array($recipe) || !is_array($recipe['steps'] ?? null)) {
			return $file;
		}
		foreach ($recipe['steps'] as $i => $step) {
			if (is_array($step) && self::secretLike($step)) {
				$recipe['steps'][$i]['value'] = self::HIDDEN;
			}
		}
		$file['options']['recipe'] = $asText ? json_encode($recipe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) : $recipe;
		return $file;
	}

	/** Whether a step fills a value of its own into a field that looks like a password's. */
	private static function secretLike(array $step): bool {
		return ($step['action'] ?? '') === 'fill' && empty($step['credential']) && (string)($step['value'] ?? '') !== ''
			&& preg_match(self::PASSWORD_FIELD, (string)($step['selector'] ?? '')) === 1;
	}

	/** A recipe file's text with each step's HIDDEN value a placeholder, which restore() replaces. */
	private static function marked(string $text): string {
		$file = json_decode($text, true);
		$recipe = is_array($file) ? ($file['options']['recipe'] ?? null) : null;
		$asText = is_string($recipe);
		if ($asText) {
			$recipe = json_decode($recipe, true);
		}
		if (!is_array($recipe) || !is_array($recipe['steps'] ?? null)) {
			return $text;
		}
		foreach ($recipe['steps'] as $i => $step) {
			if (is_array($step) && ($step['value'] ?? null) === self::HIDDEN) {
				$recipe['steps'][$i]['value'] = self::PLACEHOLDER;
			}
		}
		$file['options']['recipe'] = $asText ? json_encode($recipe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $recipe;
		return (string)json_encode($file, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	/**
	 * The fixed recipe (JSON text) with each hidden value the job's own, found at a step of the
	 * same action and selector; null when one has none there.
	 */
	private static function restore(string $fixed, string $current): ?string {
		$recipe = json_decode($fixed, true);
		if (!is_array($recipe) || !is_array($recipe['steps'] ?? null) || !str_contains($fixed, self::PLACEHOLDER)) {
			return $fixed;
		}
		$own = json_decode($current, true);
		$ownSteps = is_array($own) && is_array($own['steps'] ?? null) ? $own['steps'] : [];
		foreach ($recipe['steps'] as $i => $step) {
			if (!is_array($step) || ($step['value'] ?? null) !== self::PLACEHOLDER) {
				continue;
			}
			$value = null;
			foreach ($ownSteps as $candidate) {
				if (is_array($candidate) && ($candidate['action'] ?? '') === ($step['action'] ?? '') && ($candidate['selector'] ?? '') === ($step['selector'] ?? '')
					&& isset($candidate['value'])) {
					$value = $candidate['value'];
					break;
				}
			}
			if ($value === null) {
				return null;
			}
			$recipe['steps'][$i]['value'] = $value;
		}
		return json_encode($recipe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: null;
	}

	/** A job as Harvester's form sends it (main.JobIn): only its fields, options as an object. */
	private static function jobBody(array $job): array {
		$body = array_intersect_key($job, array_flip(self::JOB_FIELDS)) + ['schedule' => '', 'tags' => [], 'enabled' => true, 'timezone' => 'UTC', 'once' => false, 'target' => '/'];
		$body['options'] = (object)(array)($body['options'] ?? []);
		$body['tags'] = array_values((array)$body['tags']);
		return $body;
	}

	/** Why Harvester refused: FastAPI's detail, a text or a list of problems by field. */
	public static function detail(mixed $body): string {
		$detail = is_array($body) && array_key_exists('detail', $body) ? $body['detail'] : $body;
		if (is_array($detail)) {
			$detail = implode('; ', array_map(fn ($p) => is_array($p)
				? implode('.', array_map('strval', array_slice((array)($p['loc'] ?? []), 1))) . ': ' . ($p['msg'] ?? '')
				: (string)$p, $detail));
		}
		return mb_substr(trim((string)$detail), 0, 4000);
	}

	/**
	 * One request to Harvester as the person.
	 *
	 * @return array{0: int, 1: mixed} the HTTP status and the answer (decoded JSON, else its text)
	 */
	protected function call(string $userId, string $method, string $route, array $params = [], int $timeout = 30): array {
		$api = $this->appApi();
		if ($api === null) {
			throw new \RuntimeException('Harvester is not reachable (AppAPI is not installed)');
		}
		$response = $api->exAppRequest(self::APP, $route, $userId, $method, $params, ['timeout' => $timeout, 'connect_timeout' => 10]);
		if (is_array($response)) {
			throw new \RuntimeException('Harvester is not reachable (' . ($response['error'] ?? 'unknown') . ')');
		}
		$text = (string)$response->getBody();
		$json = json_decode($text, true);
		return [(int)$response->getStatusCode(), $json ?? $text];
	}

	/** AppAPI's public functions, or null without AppAPI (looked up only when needed). */
	protected function appApi(): ?object {
		if (!class_exists('OCA\AppAPI\PublicFunctions')) {
			return null;
		}
		try {
			$api = \OCP\Server::get('OCA\AppAPI\PublicFunctions');
		} catch (\Throwable) {
			return null;
		}
		return is_object($api) ? $api : null;
	}

	protected function pause(int $seconds): void {
		sleep($seconds);
	}
}
