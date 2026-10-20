<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Matrix;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The Matrix rooms the bot answers in, as far as the bot needs them: each room (its
 * name, how many people), its members' rights, its messages (the people's, as the Matrix
 * adapter hands them in, and the bot's own), and the outbox of what the adapter is to do
 * in Matrix (post, edit, remove, react, post a file or a poll).
 *
 * A Matrix room is known by a token of its own ("mx-" and a hash of its ID), so that
 * everything keyed by a Talk token (jobs, requests, confirmations, settings, memories)
 * works for it unchanged; Talk's tokens never contain "-".
 */
class Store {
	public const PREFIX = 'mx-';

	public function __construct(
		private IDBConnection $db,
		private ITimeFactory $time,
	) {
	}

	public static function isMatrix(string $token): bool {
		return str_starts_with($token, self::PREFIX);
	}

	/** The token of a Matrix room ID ("!abc:example.org"). */
	public static function token(string $roomId): string {
		return self::PREFIX . substr(sha1($roomId), 0, 29);
	}

	/** Records the room as the adapter sees it now: its name and how many people (not bots) are in it. */
	public function saveRoom(string $roomId, string $name, int $people): Room {
		$token = self::token($roomId);
		$qb = $this->db->getQueryBuilder();
		$updated = $qb->update('talk_ai_mx_rooms')
			->set('name', $qb->createNamedParameter(mb_substr($name, 0, 255)))
			->set('people', $qb->createNamedParameter(max(0, $people), IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter($this->time->getTime(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token)))
			->executeStatement();
		if ($updated === 0) {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('talk_ai_mx_rooms')->values([
				'token' => $qb->createNamedParameter($token),
				'room_id' => $qb->createNamedParameter(mb_substr($roomId, 0, 255)),
				'name' => $qb->createNamedParameter(mb_substr($name, 0, 255)),
				'people' => $qb->createNamedParameter(max(0, $people), IQueryBuilder::PARAM_INT),
				'updated_at' => $qb->createNamedParameter($this->time->getTime(), IQueryBuilder::PARAM_INT),
			])->executeStatement();
		}
		return new Room($token, $roomId, $name, $people);
	}

	public function room(string $token): ?Room {
		$qb = $this->db->getQueryBuilder();
		$row = $qb->select('*')->from('talk_ai_mx_rooms')
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token)))
			->executeQuery()->fetch();
		return $row === false ? null : new Room($token, (string)$row['room_id'], (string)$row['name'], (int)$row['people']);
	}

	/** Records whether a person may change the room's settings (a moderator in Matrix: power level 50 or more). */
	public function saveMember(string $token, string $userId, bool $moderator): void {
		$qb = $this->db->getQueryBuilder();
		$updated = $qb->update('talk_ai_mx_members')
			->set('moderator', $qb->createNamedParameter($moderator, IQueryBuilder::PARAM_BOOL))
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->executeStatement();
		if ($updated === 0) {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('talk_ai_mx_members')->values([
				'token' => $qb->createNamedParameter($token),
				'user_id' => $qb->createNamedParameter($userId),
				'moderator' => $qb->createNamedParameter($moderator, IQueryBuilder::PARAM_BOOL),
			])->executeStatement();
		}
	}

	public function isModerator(string $token, string $userId): bool {
		$qb = $this->db->getQueryBuilder();
		$row = $qb->select('moderator')->from('talk_ai_mx_members')
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->executeQuery()->fetch();
		return $row !== false && (bool)$row['moderator'];
	}

	/**
	 * Stores a message; its ID is the bot's message ID in this room.
	 *
	 * @param list<array{id: int, name: string, mime: string, size: int}> $files
	 */
	public function addMessage(string $token, string $eventId, string $userId, string $actor, bool $bot, string $text, array $files, int $replyTo): Message {
		$now = $this->time->getTime();
		$qb = $this->db->getQueryBuilder();
		$qb->insert('talk_ai_mx_messages')->values([
			'token' => $qb->createNamedParameter($token),
			'event_id' => $qb->createNamedParameter(mb_substr($eventId, 0, 255)),
			'user_id' => $qb->createNamedParameter(mb_substr($userId, 0, 64)),
			'actor' => $qb->createNamedParameter(mb_substr($actor, 0, 255)),
			'bot' => $qb->createNamedParameter($bot, IQueryBuilder::PARAM_BOOL),
			'text' => $qb->createNamedParameter($text),
			'files' => $qb->createNamedParameter(json_encode($files, JSON_THROW_ON_ERROR)),
			'reply_to' => $qb->createNamedParameter($replyTo, IQueryBuilder::PARAM_INT),
			'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			'deleted' => $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL),
		])->executeStatement();
		return new Message($qb->getLastInsertId(), $token, $eventId, $userId, $actor, $bot, $text, $files, $replyTo, $now, false);
	}

	public function message(string $token, int $id): ?Message {
		$qb = $this->db->getQueryBuilder();
		$row = $qb->select('*')->from('talk_ai_mx_messages')
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token)))
			->andWhere($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeQuery()->fetch();
		return $row === false ? null : self::fromRow($row);
	}

	public function byEvent(string $token, string $eventId): ?Message {
		if ($eventId === '') {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$row = $qb->select('*')->from('talk_ai_mx_messages')
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token)))
			->andWhere($qb->expr()->eq('event_id', $qb->createNamedParameter($eventId)))
			->setMaxResults(1)->executeQuery()->fetch();
		return $row === false ? null : self::fromRow($row);
	}

	/**
	 * The room's last messages, newest first (not the removed ones).
	 *
	 * @return list<Message>
	 */
	public function recent(string $token, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select('*')->from('talk_ai_mx_messages')
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token)))
			->andWhere($qb->expr()->eq('deleted', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->orderBy('id', 'DESC')->setMaxResults(max(1, $limit))->executeQuery();
		$messages = [];
		while (($row = $result->fetch()) !== false) {
			$messages[] = self::fromRow($row);
		}
		return $messages;
	}

	/** Changes a message's text (an edit in Matrix, or the bot's status moving on). */
	public function setText(string $token, int $id, string $text): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('talk_ai_mx_messages')->set('text', $qb->createNamedParameter($text))
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token)))
			->andWhere($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/** The Matrix event a bot message became, once the adapter posted it. */
	public function setEvent(string $token, int $id, string $eventId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('talk_ai_mx_messages')->set('event_id', $qb->createNamedParameter(mb_substr($eventId, 0, 255)))
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token)))
			->andWhere($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	public function markDeleted(string $token, int $id): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('talk_ai_mx_messages')->set('deleted', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token)))
			->andWhere($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * The bot's messages in reply to a message (its answer, pictures, files), not removed.
	 *
	 * @return list<Message>
	 */
	public function botReplies(string $token, int $messageId): array {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select('*')->from('talk_ai_mx_messages')
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token)))
			->andWhere($qb->expr()->eq('reply_to', $qb->createNamedParameter($messageId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('bot', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->eq('deleted', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->executeQuery();
		$messages = [];
		while (($row = $result->fetch()) !== false) {
			$messages[] = self::fromRow($row);
		}
		return $messages;
	}

	/** Adds a task for the adapter: post, edit, redact, react, file, poll. */
	public function push(string $token, string $type, array $payload): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('talk_ai_mx_outbox')->values([
			'token' => $qb->createNamedParameter($token),
			'type' => $qb->createNamedParameter($type),
			'payload' => $qb->createNamedParameter(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
			'created_at' => $qb->createNamedParameter($this->time->getTime(), IQueryBuilder::PARAM_INT),
		])->executeStatement();
	}

	/**
	 * The adapter's tasks after the given one, oldest first, with each room's Matrix ID.
	 *
	 * @return list<array{id: int, type: string, room_id: string, payload: array}>
	 */
	public function outbox(int $after, int $limit = 100): array {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select('o.id', 'o.type', 'o.payload', 'r.room_id')->from('talk_ai_mx_outbox', 'o')
			->innerJoin('o', 'talk_ai_mx_rooms', 'r', $qb->expr()->eq('o.token', 'r.token'))
			->where($qb->expr()->gt('o.id', $qb->createNamedParameter($after, IQueryBuilder::PARAM_INT)))
			->orderBy('o.id', 'ASC')->setMaxResults(max(1, min(500, $limit)))->executeQuery();
		$tasks = [];
		while (($row = $result->fetch()) !== false) {
			$tasks[] = ['id' => (int)$row['id'], 'type' => (string)$row['type'], 'room_id' => (string)$row['room_id'],
				'payload' => json_decode((string)$row['payload'], true) ?: []];
		}
		return $tasks;
	}

	/** Forgets the tasks the adapter has done, up to the given one. */
	public function acknowledge(int $upTo): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('talk_ai_mx_outbox')
			->where($qb->expr()->lte('id', $qb->createNamedParameter($upTo, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/** Removes messages older than the given time (the retention). */
	public function clean(int $before): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('talk_ai_mx_messages')
			->where($qb->expr()->lt('created_at', $qb->createNamedParameter($before, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		$qb = $this->db->getQueryBuilder();
		$qb->delete('talk_ai_mx_outbox')
			->where($qb->expr()->lt('created_at', $qb->createNamedParameter($before, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	private static function fromRow(array $row): Message {
		$files = json_decode((string)$row['files'], true);
		return new Message((int)$row['id'], (string)$row['token'], (string)$row['event_id'], (string)$row['user_id'], (string)$row['actor'],
			(bool)$row['bot'], (string)$row['text'], is_array($files) ? $files : [], (int)$row['reply_to'], (int)$row['created_at'], (bool)$row['deleted']);
	}
}
