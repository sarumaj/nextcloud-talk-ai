<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Ai;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Per engine: when it last answered and when it last failed, and with which error
 * word (talk_ai_ai_engines), for monitoring (Metrics). It learns this from the
 * turns that run anyway, whatever the engine or its subscription: a sign-in that
 * ran out shows as no-key after the last answer, without a request of its own.
 */
class Watch {
	public const TABLE = 'talk_ai_ai_engines';
	/** A working engine's row is written at most once a minute, not on every step of a question. */
	private const QUIET = 60;
	/** The errors that say nothing about the engine: the question's own, or a stop. */
	private const IGNORED = [Errors::CANCELLED, Errors::EMPTY, Errors::NO_IMAGES, Errors::TOO_MANY_IMAGES, Errors::IMAGE_TOO_LARGE, Errors::IMAGE_TYPE,
		Errors::USER_NOT_ALLOWED, Errors::APP_NOT_ALLOWED, Errors::NO_SCENARIO];

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/** One turn's outcome for the engine that made it. */
	public function record(string $engine, Turn $turn): void {
		if (!$turn->ok() && in_array($turn->error, self::IGNORED, true)) {
			return;
		}
		$engine = mb_substr($engine, 0, 32);
		$now = time();
		try {
			if ($turn->ok()) {
				$qb = $this->db->getQueryBuilder();
				$qb->update(self::TABLE)->set('last_ok', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
					->where($qb->expr()->eq('engine', $qb->createNamedParameter($engine)))
					->andWhere($qb->expr()->lt('last_ok', $qb->createNamedParameter($now - self::QUIET, IQueryBuilder::PARAM_INT)));
				if ($qb->executeStatement() === 0 && !$this->exists($engine)) {
					$this->insert($engine, ['last_ok' => $now]);
				}
				return;
			}
			$values = ['last_error_at' => $now, 'last_error' => mb_substr($turn->error, 0, 300)];
			$qb = $this->db->getQueryBuilder();
			$qb->update(self::TABLE)->where($qb->expr()->eq('engine', $qb->createNamedParameter($engine)));
			foreach ($values as $column => $value) {
				$qb->set($column, $qb->createNamedParameter($value, is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR));
			}
			if ($qb->executeStatement() === 0) {
				$this->insert($engine, $values);
			}
		} catch (\OCP\DB\Exception) {
			// Monitoring never fails a question (another worker made the row in the meantime).
		}
	}

	/** @return array<string, array{last_ok: int, last_error_at: int, last_error: string}> by engine; 0: never */
	public function all(): array {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select('*')->from(self::TABLE)->executeQuery();
		$rows = [];
		while (($row = $result->fetch()) !== false) {
			$rows[(string)$row['engine']] = ['last_ok' => (int)$row['last_ok'], 'last_error_at' => (int)$row['last_error_at'], 'last_error' => (string)$row['last_error']];
		}
		$result->closeCursor();
		return $rows;
	}

	private function exists(string $engine): bool {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select('engine')->from(self::TABLE)->where($qb->expr()->eq('engine', $qb->createNamedParameter($engine)))->executeQuery();
		$found = $result->fetch() !== false;
		$result->closeCursor();
		return $found;
	}

	private function insert(string $engine, array $values): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE)->values(['engine' => $qb->createNamedParameter($engine)]
			+ array_map(fn ($v) => $qb->createNamedParameter($v, is_int($v) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR), $values))
			->executeStatement();
	}
}
