<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Tests;

use OCA\TalkAi\Service\Conversation;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\L10N\IFactory;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ShareTest extends TestCase {
	/** A file the bot posts can be forwarded: the conversation's share lets its members share it on. */
	public function testAFileTheBotPostsMayBeSharedOn(): void {
		$permissions = null;
		$share = $this->createMock(IShare::class);
		foreach (['setNode', 'setShareType', 'setSharedWith', 'setSharedBy', 'setShareOwner'] as $setter) {
			$share->method($setter)->willReturnSelf();
		}
		$share->method('setPermissions')->willReturnCallback(function (int $p) use (&$permissions, $share) {
			$permissions = $p;
			return $share;
		});
		$manager = $this->createMock(IManager::class);
		$manager->method('newShare')->willReturn($share);
		$manager->expects($this->once())->method('createShare')->willReturn($share);
		$conversation = new Conversation(
			$this->createMock(IRootFolder::class),
			$manager,
			$this->createMock(IFactory::class),
			$this->createMock(LoggerInterface::class),
		);
		$room = new class {
			public function getToken(): string {
				return 'room1';
			}
		};
		$conversation->share($room, 'ewa', $this->createMock(File::class));
		$this->assertSame(Constants::PERMISSION_READ | Constants::PERMISSION_SHARE, $permissions);
		$this->assertSame(0, $permissions & Constants::PERMISSION_UPDATE, 'nobody may change the file through the conversation');
	}
}
