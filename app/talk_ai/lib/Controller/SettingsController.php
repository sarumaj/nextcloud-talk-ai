<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Controller;

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
