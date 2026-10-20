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
 * The bot in Matrix (Matrix\Store): the rooms, their members, the messages (the history
 * Talk keeps itself) and the outbox of what the adapter is to do there.
 */
class Version000900Date20261020 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('talk_ai_mx_rooms')) {
			$table = $schema->createTable('talk_ai_mx_rooms');
			$table->addColumn('token', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('room_id', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('name', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('people', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true]);
			$table->setPrimaryKey(['token']);
		}
		if (!$schema->hasTable('talk_ai_mx_members')) {
			$table = $schema->createTable('talk_ai_mx_members');
			$table->addColumn('token', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('moderator', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$table->setPrimaryKey(['token', 'user_id']);
		}
		if (!$schema->hasTable('talk_ai_mx_messages')) {
			$table = $schema->createTable('talk_ai_mx_messages');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('token', Types::STRING, ['notnull' => true, 'length' => 64]);
			// Empty until the adapter reports the event a message of the bot became.
			$table->addColumn('event_id', Types::STRING, ['notnull' => false, 'length' => 255]);
			// The Nextcloud account; empty for people of other servers.
			$table->addColumn('user_id', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('actor', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('bot', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$table->addColumn('text', Types::TEXT, ['notnull' => false]);
			// JSON: [{id, name, mime, size}]
			$table->addColumn('files', Types::TEXT, ['notnull' => false]);
			$table->addColumn('reply_to', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('deleted', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['token', 'created_at'], 'talk_ai_mx_msg_room');
			$table->addIndex(['token', 'event_id'], 'talk_ai_mx_msg_event');
			$table->addIndex(['created_at'], 'talk_ai_mx_msg_time');
		}
		if (!$schema->hasTable('talk_ai_mx_outbox')) {
			$table = $schema->createTable('talk_ai_mx_outbox');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('token', Types::STRING, ['notnull' => true, 'length' => 64]);
			// text, edit, redact, react, file, poll
			$table->addColumn('type', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('payload', Types::TEXT, ['notnull' => true]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['created_at'], 'talk_ai_mx_out_time');
		}
		return $schema;
	}
}
