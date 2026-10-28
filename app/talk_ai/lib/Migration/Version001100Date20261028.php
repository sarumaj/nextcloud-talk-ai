<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Migration;

use Closure;
use OCA\TalkAi\AppInfo\Application;
use OCP\DB\ISchemaWrapper;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The bot is no longer in Matrix (removed in 0.11.0): the tables Version000900Date20261020
 * made for it go, with their indexes, and so do the settings only Matrix rooms had: the
 * adapter's account and each Matrix room's own keys (by its token "mx-…"; Talk's tokens
 * never contain "-"). Each step checks first, so that the migration also runs where the
 * tables were never made.
 */
class Version001100Date20261028 extends SimpleMigrationStep {
	/** The Matrix tables of 0.9.0. */
	private const TABLES = ['talk_ai_mx_outbox', 'talk_ai_mx_messages', 'talk_ai_mx_members', 'talk_ai_mx_rooms'];
	/** The keys kept per conversation (by its token) that a Matrix room could have. */
	private const ROOM_KEY = '/^(context|memory|preview_off|room)_mx-/';

	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;
		foreach (self::TABLES as $table) {
			if ($schema->hasTable($table)) {
				$schema->dropTable($table);
				$changed = true;
			}
		}
		return $changed ? $schema : null;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		foreach ($this->appConfig->getKeys(Application::APP_ID) as $key) {
			if ($key === 'matrix_service_user' || preg_match(self::ROOM_KEY, $key) === 1) {
				$this->appConfig->deleteKey(Application::APP_ID, $key);
			}
		}
	}
}
