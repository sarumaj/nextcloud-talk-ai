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
 * The document jobs (their state, limits used and outcome) and each person's daily
 * use (jobs, seconds, estimated tokens) for the quotas.
 */
class Version000400Date20261015 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('talk_ai_jobs')) {
			$table = $schema->createTable('talk_ai_jobs');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('token', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('message_id', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			// queued, running, done, failed, cancelled
			$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('format', Types::STRING, ['notnull' => true, 'length' => 8]);
			$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('task', Types::TEXT, ['notnull' => true]);
			// JSON: input file ids, target folder, the house style, the previous job
			$table->addColumn('options', Types::TEXT, ['notnull' => true]);
			$table->addColumn('attempts', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('started_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('finished_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('heartbeat', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('cancel', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			// A reply to the preview while the job waits for it.
			$table->addColumn('feedback', Types::TEXT, ['notnull' => false]);
			$table->addColumn('tool_calls', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('rounds', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('tokens', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('outcome', Types::TEXT, ['notnull' => false]);
			// JSON: [{id, name, path}]
			$table->addColumn('delivered', Types::TEXT, ['notnull' => false]);
			$table->addColumn('trace_path', Types::STRING, ['notnull' => false, 'length' => 1000]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['user_id', 'created_at'], 'talk_ai_jobs_user');
			$table->addIndex(['token', 'created_at'], 'talk_ai_jobs_room');
			$table->addIndex(['status'], 'talk_ai_jobs_status');
		}
		if (!$schema->hasTable('talk_ai_usage')) {
			$table = $schema->createTable('talk_ai_usage');
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('day', Types::STRING, ['notnull' => true, 'length' => 10]);
			$table->addColumn('jobs', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('seconds', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('tokens', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['user_id', 'day']);
		}
		if (!$schema->hasTable('talk_ai_requests')) {
			// Each answered message once (token and message id), with what it cost and which tools ran.
			$table = $schema->createTable('talk_ai_requests');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('request_id', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->addColumn('token', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('message_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			// processing, answered, failed
			$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('model', Types::STRING, ['notnull' => false, 'length' => 128]);
			$table->addColumn('tokens_in', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('tokens_out', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('duration_ms', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			// JSON: [{tool, args (short), result (short)}]
			$table->addColumn('tools', Types::TEXT, ['notnull' => false]);
			$table->addColumn('error', Types::STRING, ['notnull' => false, 'length' => 1000]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['token', 'message_id'], 'talk_ai_req_msg');
			$table->addIndex(['user_id', 'created_at'], 'talk_ai_req_user');
			$table->addIndex(['created_at'], 'talk_ai_req_time');
		}
		if (!$schema->hasTable('talk_ai_actions')) {
			// What the bot changed outside the chat, to undo it within a day.
			$table = $schema->createTable('talk_ai_actions');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('token', Types::STRING, ['notnull' => true, 'length' => 64]);
			// event, task, share, file
			$table->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('summary', Types::STRING, ['notnull' => true, 'length' => 1000]);
			// JSON: what undoing needs (calendar and object uri, share id, file id)
			$table->addColumn('data', Types::TEXT, ['notnull' => true]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('undone_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['user_id', 'created_at'], 'talk_ai_act_user');
		}
		return $schema;
	}
}
