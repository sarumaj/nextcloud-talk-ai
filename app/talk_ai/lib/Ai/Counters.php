<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Ai;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Per day and engine: questions, errors, tokens (in, out, from the cache) and the
 * cost where the provider reports it (talk_ai_ai_daily). Numbers only, never what
 * was asked or answered.
 */
class Counters {
	public const TABLE = 'talk_ai_ai_daily';

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/** One question's outcome added to today's row of its engine. */
	public function add(string $engine, bool $ok, array $usage): void {
		$day = date('Y-m-d');
		$engine = mb_substr($engine !== '' ? $engine : 'unknown', 0, 32);
		$values = ['questions' => 1, 'errors' => $ok ? 0 : 1, 'tokens_in' => (int)($usage['in'] ?? 0), 'tokens_out' => (int)($usage['out'] ?? 0),
			'tokens_cached' => (int)($usage['cachedIn'] ?? 0)];
		$cost = (float)($usage['cost'] ?? 0);
		// An update first: the row exists for all but the day's first question.
		if ($this->update($day, $engine, $values, $cost) > 0) {
			return;
		}
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->insert(self::TABLE)->values(['day' => $qb->createNamedParameter($day), 'engine' => $qb->createNamedParameter($engine),
				'cost' => $qb->createNamedParameter($cost)] + array_map(fn (int $v) => $qb->createNamedParameter($v, IQueryBuilder::PARAM_INT), $values))
				->executeStatement();
		} catch (\OCP\DB\Exception $e) {
			// Another worker made the row in the meantime.
			$this->update($day, $engine, $values, $cost);
		}
	}

	/** @return list<array{day: string, engine: string, questions: int, errors: int, tokens_in: int, tokens_out: int, tokens_cached: int, cost: float}> newest first */
	public function days(int $days = 30): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)->where($qb->expr()->gte('day', $qb->createNamedParameter(date('Y-m-d', time() - $days * 86400))))
			->orderBy('day', 'DESC')->addOrderBy('engine');
		$result = $qb->executeQuery();
		$rows = [];
		while (($row = $result->fetch()) !== false) {
			$rows[] = ['day' => (string)$row['day'], 'engine' => (string)$row['engine']] + array_map('intval', array_intersect_key($row,
				array_flip(['questions', 'errors', 'tokens_in', 'tokens_out', 'tokens_cached']))) + ['cost' => round((float)$row['cost'], 4)];
		}
		$result->closeCursor();
		return $rows;
	}

	/** Rows older than the retention (retention_days of the app, 90). */
	public function clean(int $days): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)->where($qb->expr()->lt('day', $qb->createNamedParameter(date('Y-m-d', time() - $days * 86400))))->executeStatement();
	}

	private function update(string $day, string $engine, array $values, float $cost): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE);
		foreach ($values as $column => $value) {
			$qb->set($column, $qb->func()->add($column, $qb->createNamedParameter($value, IQueryBuilder::PARAM_INT)));
		}
		$qb->set('cost', $qb->func()->add('cost', $qb->createNamedParameter($cost)))
			->where($qb->expr()->eq('day', $qb->createNamedParameter($day)))
			->andWhere($qb->expr()->eq('engine', $qb->createNamedParameter($engine)));
		return $qb->executeStatement();
	}
}
