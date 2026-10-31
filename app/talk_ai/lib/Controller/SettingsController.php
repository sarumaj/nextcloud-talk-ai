<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Controller;

use OCA\TalkAi\Ai\Admin as AiAdmin;
use OCA\TalkAi\Ai\Engines;
use OCA\TalkAi\Service\DocumentJob;
use OCA\TalkAi\Service\Jobs;
use OCA\TalkAi\Service\Settings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\IRequest;

/** The administration page's requests; administrators only (the framework's default). */
class SettingsController extends Controller {
	private const STYLE_FILES = ['docx', 'dotx', 'odt', 'ott', 'xlsx', 'xltx', 'ods', 'ots', 'pptx', 'potx', 'odp', 'otp', 'png', 'jpg', 'jpeg', 'svg'];

	public function __construct(
		string $appName,
		IRequest $request,
		private Settings $settings,
		private Jobs $jobs,
		private IAppData $appData,
		private AiAdmin $ai,
		private Engines $engines,
	) {
		parent::__construct($appName, $request);
	}

	public function save(): DataResponse {
		$errors = $this->settings->save((array)$this->request->getParams());
		return new DataResponse(['errors' => (object)$errors, 'settings' => $this->settings->all()], $errors === [] ? Http::STATUS_OK : Http::STATUS_BAD_REQUEST);
	}

	public function status(bool $deep = false): DataResponse {
		return new DataResponse($this->settings->status($deep));
	}

	public function cancel(int $id): DataResponse {
		$this->jobs->cancel($id);
		return new DataResponse(['ok' => true]);
	}

	/** The AI connection for the page: the engines (no key, only whether one is set), the choice, the scenarios, the use. */
	public function ai(): DataResponse {
		return new DataResponse([
			'engines' => $this->ai->engines(),
			'chosen' => $this->engines->chosen(),
			'fallback' => $this->engines->fallback(),
			'scenarios' => (object)$this->ai->scenarios(),
			'scenarioNames' => AiAdmin::SCENARIOS,
			'usage' => $this->ai->usage(30),
		]);
	}

	/** An engine's settings; a key given is stored encrypted and never sent back. */
	public function saveEngine(string $name): DataResponse {
		if (!isset(AiAdmin::ENGINES[$name])) {
			return new DataResponse(['error' => 'unknown engine'], Http::STATUS_NOT_FOUND);
		}
		$params = (array)$this->request->getParams();
		$input = array_intersect_key($params, array_flip([...AiAdmin::ENGINES[$name][2], 'flags']));
		$errors = $this->ai->save($name, $input);
		return new DataResponse(['errors' => (object)$errors, 'engine' => $this->engine($name)], $errors === [] ? Http::STATUS_OK : Http::STATUS_BAD_REQUEST);
	}

	public function clearKey(string $name): DataResponse {
		if (!isset(AiAdmin::ENGINES[$name])) {
			return new DataResponse(['error' => 'unknown engine'], Http::STATUS_NOT_FOUND);
		}
		$this->ai->clearKey($name);
		return new DataResponse(['engine' => $this->engine($name)]);
	}

	/** The engine questions go to, and the chain that answers when it cannot. */
	public function choose(string $engine, array $fallback = []): DataResponse {
		try {
			$this->ai->choose($engine, array_map('strval', $fallback));
		} catch (\InvalidArgumentException) {
			return new DataResponse(['error' => 'unknown engine'], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse(['chosen' => $this->engines->chosen(), 'fallback' => $this->engines->fallback()]);
	}

	/** Per scenario: another engine, model or an extra prompt. */
	public function saveScenarios(array $scenarios = []): DataResponse {
		$this->ai->setScenarios($scenarios);
		return new DataResponse(['scenarios' => (object)$this->ai->scenarios()]);
	}

	public function models(string $name): DataResponse {
		if (!isset(AiAdmin::ENGINES[$name])) {
			return new DataResponse(['error' => 'unknown engine'], Http::STATUS_NOT_FOUND);
		}
		return new DataResponse(['models' => $this->ai->models($name)]);
	}

	/** One short question to the engine: whether it answers, and how fast. */
	public function test(string $name): DataResponse {
		if (!isset(AiAdmin::ENGINES[$name])) {
			return new DataResponse(['error' => 'unknown engine'], Http::STATUS_NOT_FOUND);
		}
		return new DataResponse($this->ai->test($name));
	}

	/** Whether the engine's model calls a tool. */
	public function probe(string $name): DataResponse {
		if (!isset(AiAdmin::ENGINES[$name])) {
			return new DataResponse(['error' => 'unknown engine'], Http::STATUS_NOT_FOUND);
		}
		return new DataResponse($this->ai->probe($name));
	}

	/** One engine as the page shows it. */
	private function engine(string $name): ?array {
		foreach ($this->ai->engines() as $engine) {
			if ($engine['name'] === $name) {
				return $engine;
			}
		}
		return null;
	}

	/** A house style's file (template or logo) into the app data. */
	public function upload(string $style): DataResponse {
		$file = $this->request->getUploadedFile('file');
		$name = basename((string)($file['name'] ?? ''));
		$extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		if (!is_array($file) || ($file['error'] ?? 1) !== UPLOAD_ERR_OK || !in_array($extension, self::STYLE_FILES, true) || ($file['size'] ?? 0) > 25 * 1024 * 1024) {
			return new DataResponse(['error' => 'a template or image of at most 25 MB'], Http::STATUS_BAD_REQUEST);
		}
		try {
			$root = $this->appData->getFolder('styles');
		} catch (NotFoundException) {
			$root = $this->appData->newFolder('styles');
		}
		$slug = DocumentJob::slug($style);
		try {
			$folder = $root->getFolder($slug);
		} catch (NotFoundException) {
			$folder = $root->newFolder($slug);
		}
		$folder->newFile($name, (string)file_get_contents((string)$file['tmp_name']));
		return new DataResponse(['name' => $name]);
	}
}
