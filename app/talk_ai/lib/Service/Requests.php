<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Each message the bot answers (talk_ai_requests): taken once (a second delivery
 * of the same message is refused), with the model, estimated tokens, duration and
 * the tools it ran, under a request id that the log lines carry too.
 */
class Requests {
	public function __construct(
		private IDBConnection $db,
		private ITimeFactory $time,
	) {
	}

	/** Takes a message to answer: its request id, or null when it was taken before (answered or in progress). */
	public function take(string $token, int $messageId, string $userId): ?string {
		$requestId = bin2hex(random_bytes(8));
		$qb = $this->db->getQueryBuilder();
		try {
			$qb->insert('talk_ai_requests')->values([
				'request_id' => $qb->createNamedParameter($requestId),
				'token' => $qb->createNamedParameter($token),
				'message_id' => $qb->createNamedParameter($messageId, IQueryBuilder::PARAM_INT),
				'user_id' => $qb->createNamedParameter($userId),
				'status' => $qb->createNamedParameter('processing'),
				'created_at' => $qb->createNamedParameter($this->time->getTime(), IQueryBuilder::PARAM_INT),
			])->executeStatement();
			return $requestId;
		} catch (DbException $e) {
			if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				return null;
			}
			throw $e;
		}
	}

	/** When a message was answered ('' if never). */
	public function answeredAt(string $token, int $messageId): int {
		$qb = $this->db->getQueryBuilder();
		$row = $qb->select('created_at')->from('talk_ai_requests')
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token)))
			->andWhere($qb->expr()->eq('message_id', $qb->createNamedParameter($messageId, IQueryBuilder::PARAM_INT)))
			->executeQuery()->fetch();
		return $row === false ? 0 : (int)$row['created_at'];
	}

	/** Frees a message to be answered again (after it was edited). */
	public function release(string $token, int $messageId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('talk_ai_requests')
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token)))
			->andWhere($qb->expr()->eq('message_id', $qb->createNamedParameter($messageId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/** The outcome of an answer. */
	public function done(string $requestId, string $status, string $model, int $tokensIn, int $tokensOut, int $ms, array $tools, string $error = ''): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('talk_ai_requests')
			->set('status', $qb->createNamedParameter($status))
			->set('model', $qb->createNamedParameter(mb_substr($model, 0, 120)))
			->set('tokens_in', $qb->createNamedParameter($tokensIn, IQueryBuilder::PARAM_INT))
			->set('tokens_out', $qb->createNamedParameter($tokensOut, IQueryBuilder::PARAM_INT))
			->set('duration_ms', $qb->createNamedParameter($ms, IQueryBuilder::PARAM_INT))
			->set('tools', $qb->createNamedParameter(json_encode($tools, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)))
			->set('error', $qb->createNamedParameter($error === '' ? null : mb_substr($error, 0, 990), $error === '' ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR))
			->where($qb->expr()->eq('request_id', $qb->createNamedParameter($requestId)))
			->executeStatement();
	}

	/**
	 * The tools the bot ran for recent answers in a conversation, by message id
	 * (for the history: what it looked up, not only what it said).
	 *
	 * @return array<int, list<array>>
	 */
	public function toolsOf(string $token, int $since): array {
		$qb = $this->db->getQueryBuilder();
		$rows = $qb->select('message_id', 'tools')->from('talk_ai_requests')
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token)))
			->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)))
			->executeQuery()->fetchAll();
		$out = [];
		foreach ($rows as $row) {
			$tools = json_decode((string)$row['tools'], true);
			if (is_array($tools) && $tools !== []) {
				$out[(int)$row['message_id']] = $tools;
			}
		}
		return $out;
	}

	/** @return list<array> recent answers (newest first), for the administration */
	public function recent(int $limit = 100, string $userId = ''): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('talk_ai_requests')->orderBy('id', 'DESC')->setMaxResults($limit);
		if ($userId !== '') {
			$qb->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		}
		return $qb->executeQuery()->fetchAll();
	}

	public function get(string $requestId): ?array {
		$qb = $this->db->getQueryBuilder();
		$row = $qb->select('*')->from('talk_ai_requests')->where($qb->expr()->eq('request_id', $qb->createNamedParameter($requestId)))->executeQuery()->fetch();
		return $row === false ? null : $row;
	}

	/**
	 * Sums since a time, by user, conversation and day.
	 *
	 * @return array{total: array, users: array, rooms: array, days: array}
	 */
	public function sums(int $since): array {
		$qb = $this->db->getQueryBuilder();
		$rows = $qb->select('user_id', 'token', 'created_at', 'status', 'tokens_in', 'tokens_out', 'duration_ms')->from('talk_ai_requests')
			->where($qb->expr()->gte('created_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)))
			->executeQuery()->fetchAll();
		$out = ['total' => self::zero(), 'users' => [], 'rooms' => [], 'days' => []];
		foreach ($rows as $row) {
			foreach ([['total', null], ['users', $row['user_id']], ['rooms', $row['token']], ['days', date('Y-m-d', (int)$row['created_at'])]] as [$group, $key]) {
				$bucket = &$out[$group];
				if ($key !== null) {
					$bucket[$key] ??= self::zero();
					$bucket = &$bucket[$key];
				}
				$bucket['requests']++;
				$bucket['failed'] += $row['status'] === 'failed' ? 1 : 0;
				$bucket['tokens_in'] += (int)$row['tokens_in'];
				$bucket['tokens_out'] += (int)$row['tokens_out'];
				$bucket['ms'] += (int)$row['duration_ms'];
				unset($bucket);
			}
		}
		return $out;
	}

	/** Removes records older than a time; and all of one person on request. */
	public function prune(int $before): int {
		$qb = $this->db->getQueryBuilder();
		return $qb->delete('talk_ai_requests')->where($qb->expr()->lt('created_at', $qb->createNamedParameter($before, IQueryBuilder::PARAM_INT)))->executeStatement();
	}

	public function forget(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		return $qb->delete('talk_ai_requests')->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))->executeStatement();
	}

	private static function zero(): array {
		return ['requests' => 0, 'failed' => 0, 'tokens_in' => 0, 'tokens_out' => 0, 'ms' => 0];
	}
}
