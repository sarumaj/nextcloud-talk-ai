<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Tests;

use OCA\TalkAi\Migration\Version001100Date20261028;
use OCP\DB\ISchemaWrapper;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class MigrationTest extends TestCase {
	/** 0.11.0 drops the Matrix tables that exist and leaves the schema alone without them. */
	public function testTheMatrixTablesAreDroppedWhereTheyExist(): void {
		$schema = $this->createStub(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturnCallback(fn (string $t) => $t !== 'talk_ai_mx_outbox');
		$dropped = [];
		$schema->method('dropTable')->willReturnCallback(function (string $t) use (&$dropped, $schema) {
			$dropped[] = $t;
			return $schema;
		});
		$migration = new Version001100Date20261028($this->createStub(IAppConfig::class));
		$this->assertSame($schema, $migration->changeSchema($this->createStub(IOutput::class), fn () => $schema, []));
		$this->assertSame(['talk_ai_mx_messages', 'talk_ai_mx_members', 'talk_ai_mx_rooms'], $dropped);

		$none = $this->createMock(ISchemaWrapper::class);
		$none->method('hasTable')->willReturn(false);
		$none->expects($this->never())->method('dropTable');
		$this->assertNull($migration->changeSchema($this->createStub(IOutput::class), fn () => $none, []));
	}

	/** Only the adapter's account and the Matrix rooms' own keys go; Talk's conversations keep theirs. */
	public function testOnlyTheMatrixSettingsAreDeleted(): void {
		$config = $this->createStub(IAppConfig::class);
		$config->method('getKeys')->willReturn(['enabled', 'matrix_service_user', 'context_mx-0a1b', 'memory_mx-0a1b',
			'preview_off_mx-0a1b', 'room_mx-0a1b', 'context_abcd1234', 'memory_abcd1234', 'room_abcd1234']);
		$deleted = [];
		$config->method('deleteKey')->willReturnCallback(function (string $app, string $key) use (&$deleted) {
			$deleted[] = $key;
		});
		(new Version001100Date20261028($config))->postSchemaChange($this->createStub(IOutput::class), fn () => null, []);
		$this->assertSame(['matrix_service_user', 'context_mx-0a1b', 'memory_mx-0a1b', 'preview_off_mx-0a1b', 'room_mx-0a1b'], $deleted);
	}
}
