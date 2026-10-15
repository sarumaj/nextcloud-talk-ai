<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\Share\IManager as ShareManager;

/**
 * What the bot changed outside the chat (calendar entries, tasks, shares, files),
 * to undo within a day: "undo" removes the entry, revokes the share, or moves the
 * file to the trash.
 */
class Actions {
	public const UNDO_WINDOW = 86400;

	public function __construct(
		private IDBConnection $db,
		private ITimeFactory $time,
		private Calendar $calendar,
		private ShareManager $shares,
		private IRootFolder $rootFolder,
	) {
	}

	public function record(string $userId, string $token, string $kind, string $summary, array $data): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('talk_ai_actions')->values([
			'user_id' => $qb->createNamedParameter($userId),
			'token' => $qb->createNamedParameter($token),
			'kind' => $qb->createNamedParameter($kind),
			'summary' => $qb->createNamedParameter(mb_substr($summary, 0, 990)),
			'data' => $qb->createNamedParameter(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
			'created_at' => $qb->createNamedParameter($this->time->getTime(), IQueryBuilder::PARAM_INT),
		])->executeStatement();
	}

	/** @return list<array> the person's actions of the last day that can still be undone (newest first) */
	public function undoable(string $userId, string $token = ''): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('talk_ai_actions')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('undone_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($this->time->getTime() - self::UNDO_WINDOW, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'DESC')->setMaxResults(20);
		if ($token !== '') {
			$qb->andWhere($qb->expr()->eq('token', $qb->createNamedParameter($token)));
		}
		return array_map(function (array $row): array {
			$row['id'] = (int)$row['id'];
			$row['data'] = json_decode((string)$row['data'], true) ?: [];
			return $row;
		}, $qb->executeQuery()->fetchAll());
	}

	/**
	 * Undoes one action of the person (by id; 0: the latest in the conversation).
	 *
	 * @return array{0: string, 1: string} what happened (undone, gone, nothing) and the action's summary
	 */
	public function undo(string $userId, string $token, int $id = 0): array {
		$candidates = $this->undoable($userId, $id === 0 ? $token : '');
		$action = null;
		foreach ($candidates as $candidate) {
			if ($id === 0 || $candidate['id'] === $id) {
				$action = $candidate;
				break;
			}
		}
		if ($action === null) {
			return ['nothing', ''];
		}
		$data = $action['data'];
		$done = match ($action['kind']) {
			'event', 'task' => $this->calendar->remove($userId, (int)$data['calendar'], (string)$data['object']),
			'share' => $this->revoke($userId, (string)$data['share']),
			'file' => $this->trash($userId, (int)$data['file']),
			default => false,
		};
		$qb = $this->db->getQueryBuilder();
		$qb->update('talk_ai_actions')->set('undone_at', $qb->createNamedParameter($this->time->getTime(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($action['id'], IQueryBuilder::PARAM_INT)))->executeStatement();
		return [$done ? 'undone' : 'gone', $action['summary']];
	}

	public function prune(int $before): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('talk_ai_actions')->where($qb->expr()->lt('created_at', $qb->createNamedParameter($before, IQueryBuilder::PARAM_INT)))->executeStatement();
	}

	public function forget(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('talk_ai_actions')->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))->executeStatement();
	}

	private function revoke(string $userId, string $shareId): bool {
		try {
			$share = $this->shares->getShareById($shareId, $userId);
		} catch (\Throwable) {
			return false;
		}
		if ($share->getSharedBy() !== $userId) {
			return false;
		}
		$this->shares->deleteShare($share);
		return true;
	}

	private function trash(string $userId, int $fileId): bool {
		$node = $this->rootFolder->getUserFolder($userId)->getFirstNodeById($fileId);
		if ($node === null || !$node->isDeletable()) {
			return false;
		}
		$node->delete();
		return true;
	}
}
