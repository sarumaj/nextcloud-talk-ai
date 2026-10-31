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
 * The AI layer's limits and counters (Ai\Limits, Ai\Counters): a row per question
 * (who, when, until when it holds its slot), so that the rate per person and the
 * questions at once are counted alike by every PHP worker, and a crashed worker's
 * slot runs out by itself; and a row per day and engine with the questions,
 * errors, tokens and cost. Neither holds any message content.
 */
class Version001200Date20261031 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;
		if (!$schema->hasTable('talk_ai_ai_slots')) {
			$table = $schema->createTable('talk_ai_ai_slots');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('scenario', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('started', Types::BIGINT, ['notnull' => true]);
			// 0 while the question runs; a slot whose lease ran out counts as ended.
			$table->addColumn('ended', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('lease', Types::BIGINT, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['user_id', 'started'], 'talk_ai_ai_slots_user');
			$table->addIndex(['ended', 'lease'], 'talk_ai_ai_slots_open');
			$changed = true;
		}
		if (!$schema->hasTable('talk_ai_ai_daily')) {
			$table = $schema->createTable('talk_ai_ai_daily');
			$table->addColumn('day', Types::STRING, ['notnull' => true, 'length' => 10]);
			$table->addColumn('engine', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->addColumn('questions', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('errors', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('tokens_in', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('tokens_out', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('tokens_cached', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			// In US dollars, where the provider reports it (a CLI); else 0 and estimated on the page.
			$table->addColumn('cost', Types::FLOAT, ['notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['day', 'engine']);
			$changed = true;
		}
		return $changed ? $schema : null;
	}
}
