<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Service;

use OCA\TalkAi\Document\Ics;
use OCP\Calendar\ICalendar;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager;
use OCP\Constants;
use OCP\IConfig;
use OCP\Security\ISecureRandom;

/**
 * The asking person's calendars: their events in a time range, new events
 * (with reminders, repeating) and tasks in their own writable calendars through
 * Nextcloud's calendar API, and .ics files. Times are read in the person's time zone.
 */
class Calendar {
	public function __construct(
		private IManager $calendars,
		private IConfig $config,
		private ISecureRandom $random,
	) {
	}

	/** The person's calendars: name, whether the bot may write to it. */
	public function list(string $uid): array {
		return array_values(array_map(fn (ICalendar $c) => ['name' => $c->getDisplayName() ?? $c->getUri(), 'writable' => $this->writable($c)], $this->all($uid)));
	}

	/** Events from … to …, by start. */
	public function events(string $uid, string $from, string $to): array {
		$tz = $this->timezone($uid);
		$start = Ics::time($from, $tz);
		$end = Ics::time($to, $tz, true);
		$out = [];
		foreach ($this->all($uid) as $calendar) {
			foreach ($calendar->search('', [], ['timerange' => ['start' => $start, 'end' => $end]], 200) as $result) {
				foreach ($result['objects'] ?? [] as $object) {
					$dtstart = $object['DTSTART'][0] ?? null;
					if (!$dtstart instanceof \DateTimeInterface) {
						continue;
					}
					$allDay = ($object['DTSTART'][1]['VALUE'] ?? '') === 'DATE';
					$local = \DateTimeImmutable::createFromInterface($dtstart)->setTimezone($tz);
					$out[] = [
						'start' => $allDay ? $local->format('Y-m-d') . ' (all day)' : $local->format('Y-m-d H:i'),
						'title' => (string)($object['SUMMARY'][0] ?? ''),
						'location' => (string)($object['LOCATION'][0] ?? ''),
						'calendar' => $calendar->getDisplayName() ?? $calendar->getUri(),
					];
				}
			}
		}
		usort($out, fn ($a, $b) => $a['start'] <=> $b['start']);
		return ['time_zone' => $tz->getName(), 'events' => array_map(fn ($e) => array_filter($e, fn ($v) => $v !== ''), $out)];
	}

	/**
	 * New events (each one with a reminder, a repeat …, see Ics::event), into the
	 * named or the first writable calendar.
	 */
	/** @var list<array{calendar: int, object: string, title: string}> what the last additions wrote (to undo them) */
	public array $written = [];

	public function addEvents(string $uid, array $events, string $calendar = ''): array {
		$tz = $this->timezone($uid);
		$added = [];
		foreach (array_slice($events, 0, 100) as $e) {
			$e = (array)$e;
			$id = $this->uid();
			[$lines, $years] = Ics::event($e, $tz, $id);
			$name = $this->save($uid, (string)($e['calendar'] ?? $calendar), $id, [...Ics::timezone($tz, min($years), max($years)), ...$lines]);
			$added[] = trim((string)($e['title'] ?? 'Event')) . ' – ' . str_replace('T', ' ', (string)($e['start'] ?? '')) . (isset($e['repeat']) && $e['repeat'] !== '' ? ' (' . $e['repeat'] . ')' : '') . ' → ' . $name;
		}
		return ['added' => $added, 'time_zone' => $tz->getName()];
	}

	/** New tasks (to-dos), with a due date and priority (1 high – 9 low). */
	public function addTasks(string $uid, array $tasks, string $calendar = ''): array {
		$tz = $this->timezone($uid);
		$added = [];
		foreach (array_slice($tasks, 0, 100) as $t) {
			$t = (array)$t;
			$id = $this->uid();
			$name = $this->save($uid, (string)($t['calendar'] ?? $calendar), $id, Ics::task($t, $tz, $id));
			$added[] = trim((string)($t['title'] ?? 'Task')) . (isset($t['due']) ? ' – due ' . $t['due'] : '') . ' → ' . $name;
		}
		return ['added_tasks' => $added];
	}

	/** Events and tasks as an .ics file's content, in the person's time zone. */
	public function file(string $uid, array $events, array $tasks, string $name): string {
		return Ics::calendar($events, $tasks, $this->timezone($uid), $name, fn () => $this->uid());
	}

	/**
	 * Removes an entry the bot wrote (undo), if it is still in a calendar the person
	 * may write to.
	 */
	public function remove(string $uid, int $calendarKey, string $objectUri): bool {
		foreach ($this->all($uid) as $calendar) {
			if ((int)$calendar->getKey() === $calendarKey && $this->writable($calendar)) {
				$backend = \OCP\Server::get(\OCA\DAV\CalDAV\CalDavBackend::class);
				if ($backend->getCalendarObject($calendarKey, $objectUri) === null) {
					return false;
				}
				$backend->deleteCalendarObject($calendarKey, $objectUri);
				return true;
			}
		}
		return false;
	}

	private function uid(): string {
		return $this->random->generate(24, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS) . '@talk-ai';
	}

	/** Writes the component (with its time zone) into the named calendar, else the first writable one that takes it. */
	private function save(string $uid, string $name, string $objectUid, array $lines): string {
		$ics = Ics::join(['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Nextcloud//Talk AI//EN', ...$lines, 'END:VCALENDAR']);
		$candidates = array_filter($this->all($uid), fn (ICalendar $c) => $this->writable($c));
		if ($name !== '') {
			$named = array_filter($candidates, fn (ICalendar $c) => strcasecmp((string)($c->getDisplayName() ?? $c->getUri()), $name) === 0);
			$candidates = $named !== [] ? $named : $candidates;
		}
		$errors = [];
		foreach ($candidates as $calendar) {
			try {
				$calendar->createFromString($objectUid . '.ics', $ics);
				$this->written[] = ['calendar' => (int)$calendar->getKey(), 'object' => $objectUid . '.ics',
					'title' => preg_match('/^SUMMARY:(.*)$/m', $ics, $m) === 1 ? trim($m[1]) : $objectUid];
				return (string)($calendar->getDisplayName() ?? $calendar->getUri());
			} catch (\Throwable $e) {
				// A calendar without tasks refuses a VTODO: the next one.
				$errors[] = $e->getMessage();
			}
		}
		throw new \RuntimeException('no calendar of the person took it' . ($errors !== [] ? ' (' . $errors[0] . ')' : ' (no writable calendar)'));
	}

	/** @return list<ICalendar> */
	private function all(string $uid): array {
		return array_values(array_filter($this->calendars->getCalendarsForPrincipal('principals/users/' . $uid),
			fn (ICalendar $c) => !(method_exists($c, 'isDeleted') && $c->isDeleted())));
	}

	private function writable(ICalendar $c): bool {
		return $c instanceof ICreateFromString && ($c->getPermissions() & Constants::PERMISSION_CREATE) !== 0;
	}

	private function timezone(string $uid): \DateTimeZone {
		try {
			// The person's (their browser sets it), else the server's default.
			return new \DateTimeZone($this->config->getUserValue($uid, 'core', 'timezone', '')
				?: $this->config->getSystemValueString('default_timezone', 'UTC') ?: 'UTC');
		} catch (\Throwable) {
			return new \DateTimeZone('UTC');
		}
	}
}
