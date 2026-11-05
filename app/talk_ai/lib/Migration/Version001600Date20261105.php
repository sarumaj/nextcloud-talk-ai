<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The engines' last answer and last error (Ai\Watch), for monitoring: a row per
 * engine with two times and an error word, never what was asked or answered.
 */
class Version001600Date20261105 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('talk_ai_ai_engines')) {
			return null;
		}
		$table = $schema->createTable('talk_ai_ai_engines');
		$table->addColumn('engine', Types::STRING, ['notnull' => true, 'length' => 32]);
		// Unix times; 0: never.
		$table->addColumn('last_ok', Types::BIGINT, ['notnull' => true, 'default' => 0]);
		$table->addColumn('last_error_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
		$table->addColumn('last_error', Types::STRING, ['notnull' => false, 'length' => 300, 'default' => '']);
		$table->setPrimaryKey(['engine']);
		return $schema;
	}
}
