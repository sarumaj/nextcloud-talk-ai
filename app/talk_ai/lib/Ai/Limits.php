<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Ai;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;

/**
 * Questions per person and minute (ai_rate_per_minute, 10) and questions at once
 * on the server (ai_concurrent, 4), counted in talk_ai_ai_slots so that every PHP
 * worker sees the same. A question takes a slot by adding its row and then counting
 * the open ones before it: two that come together may both step back, never both
 * through. A slot holds for a lease that each turn renews; one whose worker died
 * runs out by itself. 0 switches a limit off.
 */
class Limits {
	public const TABLE = 'talk_ai_ai_slots';
	/** How long a slot holds without a turn (seconds): longer than any one tool runs. */
	public const LEASE = 2700;

	/** @var ?callable(int): void waits between tries for a slot (tests skip it) */
	public $pause = null;

	public function __construct(
		private IDBConnection $db,
		protected IAppConfig $appConfig,
	) {
	}

	/**
	 * A slot for a question, waiting at most $wait seconds for one.
	 *
	 * @return array{id?: int, error?: string} error rate-limited or busy
	 */
	public function acquire(string $userId, string $scenario, int $wait = 60): array {
		$now = time();
		$rate = $this->setting('ai_rate_per_minute', 10);
		if ($rate > 0 && $this->count(since: $now - 60, userId: $userId) >= $rate) {
			return ['error' => Errors::RATE_LIMITED];
		}
		$concurrent = $this->setting('ai_concurrent', 4);
		$deadline = $now + max(0, $wait);
		while (true) {
			$id = $this->open($userId, $scenario);
			if ($concurrent <= 0) {
				return ['id' => $id];
			}
			if ($this->count(open: true, upTo: $id) <= $concurrent) {
				return ['id' => $id];
			}
			$this->delete($id);
			if (time() >= $deadline) {
				return ['error' => Errors::BUSY];
			}
			is_callable($this->pause) ? ($this->pause)(1) : sleep(1);
		}
	}

	/** The question goes on: its slot holds for another lease. */
	public function renew(int $id): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)->set('lease', $qb->createNamedParameter(time() + self::LEASE, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
	}

	public function release(int $id): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)->set('ended', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
	}

	/** Questions running now (for the page and occ). */
	public function running(): int {
		return $this->count(open: true);
	}

	/** Rows older than a day: the rate needs only the last minute. */
	public function clean(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)->where($qb->expr()->lt('started', $qb->createNamedParameter(time() - 86400, IQueryBuilder::PARAM_INT)))->executeStatement();
	}

	protected function open(string $userId, string $scenario): int {
		$now = time();
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE)->values([
			'user_id' => $qb->createNamedParameter(mb_substr($userId, 0, 64)),
			'scenario' => $qb->createNamedParameter(mb_substr($scenario, 0, 64)),
			'started' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			'ended' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
			'lease' => $qb->createNamedParameter($now + self::LEASE, IQueryBuilder::PARAM_INT),
		])->executeStatement();
		return $qb->getLastInsertId();
	}

	protected function delete(int $id): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
	}

	/** Rows started since a time, of one person, open ones only (not ended, lease not run out), up to an id. */
	protected function count(?int $since = null, ?string $userId = null, bool $open = false, ?int $upTo = null): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'n'))->from(self::TABLE);
		if ($since !== null) {
			$qb->andWhere($qb->expr()->gt('started', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)));
		}
		if ($userId !== null) {
			$qb->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter(mb_substr($userId, 0, 64))));
		}
		if ($open) {
			$qb->andWhere($qb->expr()->eq('ended', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->gt('lease', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT)));
		}
		if ($upTo !== null) {
			$qb->andWhere($qb->expr()->lte('id', $qb->createNamedParameter($upTo, IQueryBuilder::PARAM_INT)));
		}
		$result = $qb->executeQuery();
		$n = (int)$result->fetchOne();
		$result->closeCursor();
		return $n;
	}

	private function setting(string $key, int $default): int {
		return (int)$this->appConfig->getValueString(Engines::APP, $key, (string)$default);
	}
}
