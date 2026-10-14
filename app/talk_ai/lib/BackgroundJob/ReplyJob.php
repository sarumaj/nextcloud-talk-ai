<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\BackgroundJob;

use OCA\TalkAi\AppInfo\Application;
use OCA\TalkAi\Service\Reply;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\IAppConfig;

/** An answer the Dispatcher could not hand off over HTTP (the text waits in the app config). */
class ReplyJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private Reply $reply,
		private IAppConfig $appConfig,
	) {
		parent::__construct($time);
	}

	protected function run($argument): void {
		$key = 'pending_' . (string)($argument['ref'] ?? '');
		$text = $this->appConfig->getValueString(Application::APP_ID, $key, '', lazy: true);
		$this->appConfig->deleteKey(Application::APP_ID, $key);
		if ($text !== '') {
			$this->reply->answer((string)$argument['token'], (string)$argument['userId'], (int)$argument['messageId'], $text);
		}
	}
}
