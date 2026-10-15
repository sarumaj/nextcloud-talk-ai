<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Service;

use OCA\TalkAi\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;

/**
 * The document jobs (table talk_ai_jobs): queued, running, done, failed or
 * cancelled, with their limits used and outcome, and each person's daily use
 * (talk_ai_usage) against the quotas.
 *
 * App settings of the quotas, per person and day (0: no limit): quota_jobs,
 * quota_seconds, quota_tokens.
 */
class Jobs {
	public const QUEUED = 'queued';
	public const RUNNING = 'running';
	public const DONE = 'done';
	public const FAILED = 'failed';
	public const CANCELLED = 'cancelled';
	/**
	 * A running job whose heartbeat is older is taken as lost (a restart, a crash):
	 * longer than one tool call may take (600 s) and a model's turn.
	 */
	public const STALE = 900;

	public function __construct(
		private IDBConnection $db,
		private ITimeFactory $time,
		private IAppConfig $appConfig,
	) {
	}

	/** A new queued job; its id. */
	public function add(string $userId, string $token, int $messageId, string $format, string $name, string $task, array $options): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('talk_ai_jobs')->values([
			'user_id' => $qb->createNamedParameter($userId),
			'token' => $qb->createNamedParameter($token),
			'message_id' => $qb->createNamedParameter($messageId, IQueryBuilder::PARAM_INT),
			'status' => $qb->createNamedParameter(self::QUEUED),
			'format' => $qb->createNamedParameter($format),
			'name' => $qb->createNamedParameter(mb_substr($name, 0, 250)),
			'task' => $qb->createNamedParameter($task),
			'options' => $qb->createNamedParameter(json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
			'created_at' => $qb->createNamedParameter($this->time->getTime(), IQueryBuilder::PARAM_INT),
		])->executeStatement();
		return $qb->getLastInsertId();
	}

	public function get(int $id): ?array {
		$qb = $this->db->getQueryBuilder();
		$row = $qb->select('*')->from('talk_ai_jobs')->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeQuery()->fetch();
		return $row === false ? null : self::decode($row);
	}

	/**
	 * Takes a queued job to run it: false when another worker took it first, or it
	 * is no longer queued.
	 */
	public function claim(int $id): bool {
		$now = $this->time->getTime();
		$qb = $this->db->getQueryBuilder();
		return $qb->update('talk_ai_jobs')
			->set('status', $qb->createNamedParameter(self::RUNNING))
			->set('started_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->set('heartbeat', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->set('attempts', $qb->createFunction('attempts + 1'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(self::QUEUED)))
			->executeStatement() === 1;
	}

	/** Back into the queue (the sandbox was busy): it runs with the next free slot. */
	public function requeue(int $id): void {
		$this->set($id, ['status' => self::QUEUED, 'attempts' => 0]);
	}

	/** The running job's progress; whether it should go on (false: cancelled). */
	public function beat(int $id, int $toolCalls, int $rounds, int $tokens): bool {
		$this->set($id, ['heartbeat' => $this->time->getTime(), 'tool_calls' => $toolCalls, 'rounds' => $rounds, 'tokens' => $tokens]);
		$job = $this->get($id);
		return $job !== null && !$job['cancel'];
	}

	public function finish(int $id, string $status, string $outcome, array $delivered, string $tracePath): void {
		$job = $this->get($id);
		$now = $this->time->getTime();
		$this->set($id, ['status' => $status, 'finished_at' => $now, 'outcome' => mb_substr($outcome, 0, 4000),
			'delivered' => json_encode($delivered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'trace_path' => $tracePath]);
		if ($job !== null) {
			$this->use((string)$job['user_id'], 1, max(0, $now - (int)$job['started_at']), 0);
		}
	}

	/** Asks a job to stop: a queued one at once, a running one at its next step. */
	public function cancel(int $id): void {
		$job = $this->get($id);
		if ($job === null) {
			return;
		}
		if ($job['status'] === self::QUEUED) {
			$this->set($id, ['status' => self::CANCELLED, 'finished_at' => $this->time->getTime(), 'outcome' => 'cancelled before it started']);
		} elseif ($job['status'] === self::RUNNING) {
			$this->set($id, ['cancel' => true]);
		}
	}

	/** One of the job's options (status message, rating …). */
	public function setOption(int $id, string $key, mixed $value): void {
		$job = $this->get($id);
		if ($job !== null) {
			$this->set($id, ['options' => json_encode([$key => $value] + $job['options'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
		}
	}

	/** The job of a conversation that delivered this file, or whose status message this is; null if none. */
	public function byMessage(string $token, int $fileId, int $statusMessageId): ?array {
		foreach ($this->ofRoom($token, 50) as $job) {
			if (($statusMessageId > 0 && (int)($job['options']['status_message'] ?? 0) === $statusMessageId)
				|| ($fileId > 0 && in_array($fileId, array_map(fn ($d) => (int)$d['id'], $job['delivered']), true))) {
				return $job;
			}
		}
		return null;
	}

	public function setTracePath(int $id, string $path): void {
		$this->set($id, ['trace_path' => $path]);
	}

	/** Puts a lost job (a restart) back into the queue, or ends it after two attempts. */
	public function recover(array $job): bool {
		if ($job['attempts'] >= 2) {
			$this->set($job['id'], ['status' => self::FAILED, 'finished_at' => $this->time->getTime(), 'outcome' => 'lost twice (server restart?)']);
			return false;
		}
		$this->set($job['id'], ['status' => self::QUEUED, 'feedback' => null]);
		return true;
	}

	public function setFeedback(int $id, ?string $text): void {
		$this->set($id, ['feedback' => $text]);
	}

	/** The person's unfinished job in a conversation (the newest), or null. */
	public function active(string $token, ?string $userId = null): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('talk_ai_jobs')
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token)))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter([self::QUEUED, self::RUNNING], IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('id', 'DESC')->setMaxResults(1);
		if ($userId !== null) {
			$qb->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		}
		$row = $qb->executeQuery()->fetch();
		return $row === false ? null : self::decode($row);
	}

	/** @return list<array> the jobs of a conversation (newest first) */
	public function ofRoom(string $token, int $limit = 20): array {
		return $this->query(fn (IQueryBuilder $qb) => $qb->where($qb->expr()->eq('token', $qb->createNamedParameter($token))), $limit);
	}

	/** @return list<array> the person's jobs (newest first) */
	public function ofUser(string $userId, int $limit = 20): array {
		return $this->query(fn (IQueryBuilder $qb) => $qb->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId))), $limit);
	}

	/** @return list<array> all jobs (newest first), for the administration */
	public function recent(int $limit = 50): array {
		return $this->query(fn (IQueryBuilder $qb) => $qb, $limit);
	}

	/** @return list<array> queued jobs, oldest first */
	public function queued(int $limit = 10): array {
		if ($limit <= 0) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$rows = $qb->select('*')->from('talk_ai_jobs')->where($qb->expr()->eq('status', $qb->createNamedParameter(self::QUEUED)))
			->orderBy('id', 'ASC')->setMaxResults($limit)->executeQuery()->fetchAll();
		return array_map(self::decode(...), $rows);
	}

	/** @return list<array> running jobs whose heartbeat stopped */
	public function stale(): array {
		$qb = $this->db->getQueryBuilder();
		$rows = $qb->select('*')->from('talk_ai_jobs')->where($qb->expr()->eq('status', $qb->createNamedParameter(self::RUNNING)))
			->andWhere($qb->expr()->lt('heartbeat', $qb->createNamedParameter($this->time->getTime() - self::STALE, IQueryBuilder::PARAM_INT)))
			->executeQuery()->fetchAll();
		return array_map(self::decode(...), $rows);
	}

	/** How many jobs run now. */
	public function running(): int {
		$qb = $this->db->getQueryBuilder();
		return (int)$qb->select($qb->func()->count('*'))->from('talk_ai_jobs')
			->where($qb->expr()->eq('status', $qb->createNamedParameter(self::RUNNING)))->executeQuery()->fetchOne();
	}

	/** Counts toward the person's use today. */
	public function use(string $userId, int $jobs, int $seconds, int $tokens): void {
		$day = date('Y-m-d', $this->time->getTime());
		$qb = $this->db->getQueryBuilder();
		$updated = $qb->update('talk_ai_usage')
			->set('jobs', $qb->createFunction('jobs + ' . $jobs))
			->set('seconds', $qb->createFunction('seconds + ' . $seconds))
			->set('tokens', $qb->createFunction('tokens + ' . $tokens))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('day', $qb->createNamedParameter($day)))
			->executeStatement();
		if ($updated === 0) {
			$qb = $this->db->getQueryBuilder();
			try {
				$qb->insert('talk_ai_usage')->values([
					'user_id' => $qb->createNamedParameter($userId), 'day' => $qb->createNamedParameter($day),
					'jobs' => $qb->createNamedParameter($jobs, IQueryBuilder::PARAM_INT),
					'seconds' => $qb->createNamedParameter($seconds, IQueryBuilder::PARAM_INT),
					'tokens' => $qb->createNamedParameter($tokens, IQueryBuilder::PARAM_INT),
				])->executeStatement();
			} catch (\OCP\DB\Exception $e) {
				// Inserted at the same moment by another request: add to that.
				if ($e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					$this->use($userId, $jobs, $seconds, $tokens);
				} else {
					throw $e;
				}
			}
		}
	}

	/** @return array{jobs: int, seconds: int, tokens: int} the person's use today */
	public function usage(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$row = $qb->select('jobs', 'seconds', 'tokens')->from('talk_ai_usage')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('day', $qb->createNamedParameter(date('Y-m-d', $this->time->getTime()))))
			->executeQuery()->fetch();
		return ['jobs' => (int)($row['jobs'] ?? 0), 'seconds' => (int)($row['seconds'] ?? 0), 'tokens' => (int)($row['tokens'] ?? 0)];
	}

	/** What of today's quota is used up, as a sentence for the person; '' while within it. */
	public function overQuota(string $userId, bool $document): string {
		$use = $this->usage($userId);
		$limits = ['jobs' => $this->limit('quota_jobs'), 'seconds' => $this->limit('quota_seconds'), 'tokens' => $this->limit('quota_tokens')];
		if ($document && $limits['jobs'] > 0 && $use['jobs'] >= $limits['jobs']) {
			return sprintf('today\'s %d document jobs are used up', $limits['jobs']);
		}
		if ($document && $limits['seconds'] > 0 && $use['seconds'] >= $limits['seconds']) {
			return sprintf('today\'s %d minutes of document building are used up', intdiv($limits['seconds'], 60));
		}
		if ($limits['tokens'] > 0 && $use['tokens'] >= $limits['tokens']) {
			return sprintf('today\'s %s tokens are used up', number_format($limits['tokens'], 0, '.', ' '));
		}
		return '';
	}

	/** @return array{jobs: int, done: int, failed: int, cancelled: int, seconds: float, tokens: int} jobs ended since $since */
	public function stats(int $since): array {
		$qb = $this->db->getQueryBuilder();
		$rows = $qb->select('status', 'started_at', 'finished_at', 'tokens')->from('talk_ai_jobs')
			->where($qb->expr()->gte('created_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)))
			->executeQuery()->fetchAll();
		$out = ['jobs' => count($rows), 'done' => 0, 'failed' => 0, 'cancelled' => 0, 'seconds' => 0.0, 'tokens' => 0];
		$times = [];
		foreach ($rows as $row) {
			if (isset($out[$row['status']])) {
				$out[$row['status']]++;
			}
			if ((int)$row['finished_at'] > 0 && (int)$row['started_at'] > 0) {
				$times[] = (int)$row['finished_at'] - (int)$row['started_at'];
			}
			$out['tokens'] += (int)$row['tokens'];
		}
		$out['seconds'] = $times === [] ? 0.0 : round(array_sum($times) / count($times), 1);
		return $out;
	}

	/** Removes the person's jobs and use (their data, on request). */
	public function forget(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$count = $qb->delete('talk_ai_jobs')->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->notIn('status', $qb->createNamedParameter([self::QUEUED, self::RUNNING], IQueryBuilder::PARAM_STR_ARRAY)))
			->executeStatement();
		$qb = $this->db->getQueryBuilder();
		$qb->delete('talk_ai_usage')->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))->executeStatement();
		return $count;
	}

	/** @return list<array> ended jobs created before $before (for the retention) */
	public function older(int $before, int $limit = 200): array {
		$qb = $this->db->getQueryBuilder();
		$rows = $qb->select('*')->from('talk_ai_jobs')
			->where($qb->expr()->lt('created_at', $qb->createNamedParameter($before, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->notIn('status', $qb->createNamedParameter([self::QUEUED, self::RUNNING], IQueryBuilder::PARAM_STR_ARRAY)))
			->setMaxResults($limit)->executeQuery()->fetchAll();
		return array_map(self::decode(...), $rows);
	}

	public function delete(int $id): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('talk_ai_jobs')->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
	}

	/** Daily use older than $days is removed. */
	public function pruneUsage(int $days): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('talk_ai_usage')->where($qb->expr()->lt('day', $qb->createNamedParameter(date('Y-m-d', $this->time->getTime() - $days * 86400))))->executeStatement();
	}

	private function set(int $id, array $values): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('talk_ai_jobs');
		foreach ($values as $column => $value) {
			$type = is_int($value) ? IQueryBuilder::PARAM_INT : (is_bool($value) ? IQueryBuilder::PARAM_BOOL : (is_null($value) ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR));
			$qb->set($column, $qb->createNamedParameter($value, $type));
		}
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
	}

	private function query(callable $where, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('talk_ai_jobs');
		$where($qb);
		$rows = $qb->orderBy('id', 'DESC')->setMaxResults($limit)->executeQuery()->fetchAll();
		return array_map(self::decode(...), $rows);
	}

	private function limit(string $key): int {
		return max(0, (int)$this->appConfig->getValueString(Application::APP_ID, $key, '0'));
	}

	private static function decode(array $row): array {
		$row['id'] = (int)$row['id'];
		$row['message_id'] = (int)$row['message_id'];
		$row['cancel'] = (bool)$row['cancel'];
		$row['options'] = json_decode((string)$row['options'], true) ?: [];
		$row['delivered'] = json_decode((string)($row['delivered'] ?? ''), true) ?: [];
		foreach (['attempts', 'created_at', 'started_at', 'finished_at', 'heartbeat', 'tool_calls', 'rounds', 'tokens'] as $key) {
			$row[$key] = (int)$row[$key];
		}
		return $row;
	}
}
