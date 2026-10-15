<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Service;

use OCP\Calendar\ICalendar;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager;
use OCP\Constants;
use OCP\IConfig;
use OCP\Security\ISecureRandom;

/**
 * The asking person's calendars: their events in a time range, and new events
 * (with a reminder) and tasks, in their own writable calendars through
 * Nextcloud's calendar API. Times are read in the person's time zone.
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
		$start = $this->time($from, $tz);
		$end = $this->time($to, $tz, true);
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
	 * A new event: start and end as local date-times ("2026-11-12 09:00") or dates
	 * for a whole day; a reminder this many minutes before.
	 */
	public function addEvent(string $uid, array $e): array {
		$tz = $this->timezone($uid);
		$allDay = preg_match('/^\d{4}-\d{2}-\d{2}$/', trim((string)($e['start'] ?? ''))) === 1;
		$start = $this->time((string)($e['start'] ?? ''), $tz);
		$end = isset($e['end']) && trim((string)$e['end']) !== '' ? $this->time((string)$e['end'], $tz, $allDay) : ($allDay ? $start->modify('+1 day') : $start->modify('+1 hour'));
		if ($end <= $start) {
			throw new \InvalidArgumentException('the end must be after the start');
		}
		$lines = ['BEGIN:VEVENT', 'SUMMARY:' . self::text((string)($e['title'] ?? 'Event'))];
		if ($allDay) {
			$lines[] = 'DTSTART;VALUE=DATE:' . $start->format('Ymd');
			$lines[] = 'DTEND;VALUE=DATE:' . $end->format('Ymd');
		} else {
			$lines[] = 'DTSTART:' . $start->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
			$lines[] = 'DTEND:' . $end->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
		}
		foreach (['location' => 'LOCATION', 'description' => 'DESCRIPTION'] as $key => $property) {
			if (trim((string)($e[$key] ?? '')) !== '') {
				$lines[] = $property . ':' . self::text((string)$e[$key]);
			}
		}
		if (isset($e['reminder_minutes']) && (int)$e['reminder_minutes'] >= 0) {
			$lines = [...$lines, 'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:' . self::text((string)($e['title'] ?? 'Reminder')),
				'TRIGGER:-PT' . (int)$e['reminder_minutes'] . 'M', 'END:VALARM'];
		}
		$calendar = $this->save($uid, (string)($e['calendar'] ?? ''), $lines, 'VEVENT');
		return ['added' => (string)($e['title'] ?? 'Event'), 'start' => $allDay ? $start->format('Y-m-d') . ' (all day)' : $start->format('Y-m-d H:i'),
			'end' => $allDay ? $end->modify('-1 day')->format('Y-m-d') : $end->format('Y-m-d H:i'), 'calendar' => $calendar, 'time_zone' => $tz->getName()];
	}

	/** A new task (to-do), with an optional due date and priority (1 high – 9 low). */
	public function addTask(string $uid, array $t): array {
		$tz = $this->timezone($uid);
		$lines = ['BEGIN:VTODO', 'SUMMARY:' . self::text((string)($t['title'] ?? 'Task')), 'STATUS:NEEDS-ACTION'];
		if (trim((string)($t['due'] ?? '')) !== '') {
			$due = $this->time((string)$t['due'], $tz);
			$lines[] = preg_match('/^\d{4}-\d{2}-\d{2}$/', trim((string)$t['due'])) === 1
				? 'DUE;VALUE=DATE:' . $due->format('Ymd')
				: 'DUE:' . $due->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
		}
		if (isset($t['priority'])) {
			$lines[] = 'PRIORITY:' . max(1, min(9, (int)$t['priority']));
		}
		if (trim((string)($t['description'] ?? '')) !== '') {
			$lines[] = 'DESCRIPTION:' . self::text((string)$t['description']);
		}
		$calendar = $this->save($uid, (string)($t['calendar'] ?? ''), $lines, 'VTODO');
		return ['added_task' => (string)($t['title'] ?? 'Task'), 'due' => $t['due'] ?? null, 'list' => $calendar];
	}

	/** Writes the component into the named calendar, else the first writable one that takes it. */
	private function save(string $uid, string $name, array $lines, string $component): string {
		$uidValue = $this->random->generate(24, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS) . '@talk-ai';
		$now = gmdate('Ymd\THis\Z');
		array_splice($lines, 1, 0, ['UID:' . $uidValue, 'DTSTAMP:' . $now, 'CREATED:' . $now]);
		$ics = implode("\r\n", array_map([self::class, 'fold'], ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Nextcloud//Talk AI//EN', ...$lines, 'END:' . $component, 'END:VCALENDAR'])) . "\r\n";
		$candidates = array_filter($this->all($uid), fn (ICalendar $c) => $this->writable($c));
		if ($name !== '') {
			$named = array_filter($candidates, fn (ICalendar $c) => strcasecmp((string)($c->getDisplayName() ?? $c->getUri()), $name) === 0);
			$candidates = $named !== [] ? $named : $candidates;
		}
		$errors = [];
		foreach ($candidates as $calendar) {
			try {
				$calendar->createFromString($uidValue . '.ics', $ics);
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

	/** A local date or date-time; a date alone is the day's start, or with $endOfDay the next day's. */
	private function time(string $value, \DateTimeZone $tz, bool $endOfDay = false): \DateTimeImmutable {
		$value = trim(str_replace('T', ' ', $value));
		$date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $value, $tz) ?: \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $tz)
			?: \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $tz);
		if ($date === false) {
			throw new \InvalidArgumentException('a date as 2026-11-12 or a time as 2026-11-12 09:00: ' . $value);
		}
		return $endOfDay && strlen($value) === 10 ? $date->modify('+1 day') : $date;
	}

	/** iCalendar text: backslashes, commas, semicolons and new lines escaped. */
	private static function text(string $value): string {
		return str_replace(["\\", ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], trim($value));
	}

	/** Lines longer than 75 octets folded, as iCalendar requires. */
	private static function fold(string $line): string {
		$out = '';
		while (strlen($line) > 75) {
			$cut = 75;
			while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
				$cut--;
			}
			$out .= substr($line, 0, $cut) . "\r\n ";
			$line = substr($line, $cut);
		}
		return $out . $line;
	}
}
