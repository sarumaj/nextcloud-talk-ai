<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Document;

/**
 * iCalendar (RFC 5545): events (timed or all-day, repeating, with a reminder)
 * and tasks, in the person's time zone with its VTIMEZONE, so a weekly lesson
 * keeps its hour across the change to and from summer time. For a file to
 * import into any calendar, and for Nextcloud's calendars.
 */
class Ics {
	private const FREQUENCIES = ['daily' => 'DAILY', 'weekly' => 'WEEKLY', 'biweekly' => 'WEEKLY', 'monthly' => 'MONTHLY', 'yearly' => 'YEARLY'];

	/**
	 * A whole calendar: events {title, start, end, all-day by a date alone,
	 * location, description, reminder_minutes, repeat, until, count, except} and
	 * tasks {title, due, priority, description}.
	 */
	public static function calendar(array $events, array $tasks, \DateTimeZone $tz, string $name = '', ?callable $uid = null): string {
		$uid ??= fn () => bin2hex(random_bytes(12)) . '@talk-ai';
		$body = [];
		$years = [];
		foreach ($events as $e) {
			[$lines, $span] = self::event((array)$e, $tz, $uid());
			$body = [...$body, ...$lines];
			$years = [...$years, ...$span];
		}
		foreach ($tasks as $t) {
			$body = [...$body, ...self::task((array)$t, $tz, $uid())];
		}
		if ($body === []) {
			throw new \InvalidArgumentException('no events or tasks');
		}
		$head = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Nextcloud//Talk AI//EN', 'CALSCALE:GREGORIAN'];
		if ($name !== '') {
			$head[] = 'X-WR-CALNAME:' . self::text($name);
		}
		$zone = $years === [] ? [] : self::timezone($tz, min($years), max($years));
		return self::join([...$head, ...$zone, ...$body, 'END:VCALENDAR']);
	}

	/** @return array{list<string>, list<int>} the VEVENT's lines and the years it spans (for the time zone) */
	public static function event(array $e, \DateTimeZone $tz, string $uid): array {
		$startText = trim((string)($e['start'] ?? ''));
		$allDay = preg_match('/^\d{4}-\d{2}-\d{2}$/', $startText) === 1;
		$start = self::time($startText, $tz);
		$end = trim((string)($e['end'] ?? '')) !== '' ? self::time((string)$e['end'], $tz, $allDay)
			: ($allDay ? $start->modify('+1 day') : $start->modify('+' . max(1, (int)($e['minutes'] ?? 60)) . ' minutes'));
		if ($end <= $start) {
			throw new \InvalidArgumentException('"' . ($e['title'] ?? 'event') . '": the end must be after the start');
		}
		$now = gmdate('Ymd\THis\Z');
		$lines = ['BEGIN:VEVENT', 'UID:' . $uid, 'DTSTAMP:' . $now, 'SUMMARY:' . self::text((string)($e['title'] ?? 'Event'))];
		$zone = $tz->getName() === 'UTC' ? null : $tz->getName();
		foreach (['DTSTART' => $start, 'DTEND' => $end] as $property => $at) {
			$lines[] = $allDay ? $property . ';VALUE=DATE:' . $at->format('Ymd')
				: ($zone === null ? $property . ':' . $at->format('Ymd\THis\Z') : $property . ';TZID=' . $zone . ':' . $at->format('Ymd\THis'));
		}
		$years = [(int)$start->format('Y'), (int)$end->format('Y')];
		$repeat = strtolower(trim((string)($e['repeat'] ?? '')));
		if ($repeat !== '' && $repeat !== 'none') {
			if (!isset(self::FREQUENCIES[$repeat])) {
				throw new \InvalidArgumentException('repeat: ' . implode(', ', array_keys(self::FREQUENCIES)));
			}
			$rule = 'RRULE:FREQ=' . self::FREQUENCIES[$repeat] . ($repeat === 'biweekly' ? ';INTERVAL=2' : '');
			if (trim((string)($e['until'] ?? '')) !== '') {
				// UNTIL in UTC (or a date for all-day events), at the end of that day.
				$until = self::time((string)$e['until'], $tz, true);
				$rule .= ';UNTIL=' . ($allDay ? $until->modify('-1 day')->format('Ymd') : $until->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z'));
				$years[] = (int)$until->format('Y');
			} elseif ((int)($e['count'] ?? 0) > 0) {
				$rule .= ';COUNT=' . (int)$e['count'];
			} else {
				$years[] = (int)$start->format('Y') + 1;
			}
			$lines[] = $rule;
			foreach ((array)($e['except'] ?? []) as $date) {
				// Skipped dates (holidays): at the event's own time of day.
				$day = self::time((string)$date, $tz);
				$lines[] = $allDay ? 'EXDATE;VALUE=DATE:' . $day->format('Ymd')
					: ($zone === null ? 'EXDATE:' . $day->setTime((int)$start->format('H'), (int)$start->format('i'))->format('Ymd\THis\Z')
						: 'EXDATE;TZID=' . $zone . ':' . $day->format('Ymd') . 'T' . $start->format('His'));
			}
		}
		foreach (['location' => 'LOCATION', 'description' => 'DESCRIPTION'] as $key => $property) {
			if (trim((string)($e[$key] ?? '')) !== '') {
				$lines[] = $property . ':' . self::text((string)$e[$key]);
			}
		}
		if (isset($e['reminder_minutes']) && $e['reminder_minutes'] !== '' && (int)$e['reminder_minutes'] >= 0) {
			$lines = [...$lines, 'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:' . self::text((string)($e['title'] ?? 'Reminder')),
				'TRIGGER:-PT' . (int)$e['reminder_minutes'] . 'M', 'END:VALARM'];
		}
		$lines[] = 'END:VEVENT';
		return [$lines, $years];
	}

	/** @return list<string> */
	public static function task(array $t, \DateTimeZone $tz, string $uid): array {
		$lines = ['BEGIN:VTODO', 'UID:' . $uid, 'DTSTAMP:' . gmdate('Ymd\THis\Z'), 'SUMMARY:' . self::text((string)($t['title'] ?? 'Task')), 'STATUS:NEEDS-ACTION'];
		$dueText = trim((string)($t['due'] ?? ''));
		if ($dueText !== '') {
			$due = self::time($dueText, $tz);
			$lines[] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueText) === 1 ? 'DUE;VALUE=DATE:' . $due->format('Ymd')
				: 'DUE:' . $due->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
		}
		if (isset($t['priority']) && $t['priority'] !== '') {
			$lines[] = 'PRIORITY:' . max(1, min(9, (int)$t['priority']));
		}
		if (trim((string)($t['description'] ?? '')) !== '') {
			$lines[] = 'DESCRIPTION:' . self::text((string)$t['description']);
		}
		$lines[] = 'END:VTODO';
		return $lines;
	}

	/**
	 * The zone's definition for these years: each change between standard and
	 * summer time as its own observance (valid iCalendar, without rules to derive).
	 *
	 * @return list<string>
	 */
	public static function timezone(\DateTimeZone $tz, int $from, int $to): array {
		if ($tz->getName() === 'UTC') {
			return [];
		}
		$begin = (new \DateTimeImmutable(($from - 1) . '-12-31 00:00', new \DateTimeZone('UTC')))->getTimestamp();
		$end = (new \DateTimeImmutable(($to + 1) . '-12-31 23:59', new \DateTimeZone('UTC')))->getTimestamp();
		$transitions = $tz->getTransitions($begin, $end) ?: [];
		$offset = fn (int $seconds) => ($seconds < 0 ? '-' : '+') . sprintf('%02d%02d', intdiv(abs($seconds), 3600), intdiv(abs($seconds) % 3600, 60));
		$lines = ['BEGIN:VTIMEZONE', 'TZID:' . $tz->getName()];
		$previous = $transitions[0]['offset'] ?? 0;
		foreach ($transitions as $i => $t) {
			if ($i === 0) {
				// The state at the start of the range: an observance from then.
				$lines = [...$lines, 'BEGIN:' . ($t['isdst'] ? 'DAYLIGHT' : 'STANDARD'), 'DTSTART:' . gmdate('Ymd\THis', $t['ts'] + $t['offset']),
					'TZOFFSETFROM:' . $offset($t['offset']), 'TZOFFSETTO:' . $offset($t['offset']), 'TZNAME:' . $t['abbr'], 'END:' . ($t['isdst'] ? 'DAYLIGHT' : 'STANDARD')];
				continue;
			}
			// DTSTART is the local time before the change.
			$lines = [...$lines, 'BEGIN:' . ($t['isdst'] ? 'DAYLIGHT' : 'STANDARD'), 'DTSTART:' . gmdate('Ymd\THis', $t['ts'] + $previous),
				'TZOFFSETFROM:' . $offset($previous), 'TZOFFSETTO:' . $offset($t['offset']), 'TZNAME:' . $t['abbr'], 'END:' . ($t['isdst'] ? 'DAYLIGHT' : 'STANDARD')];
			$previous = $t['offset'];
		}
		$lines[] = 'END:VTIMEZONE';
		return $lines;
	}

	/** A local date or date-time; a date alone is the day's start, or with $endOfDay the next day's. */
	public static function time(string $value, \DateTimeZone $tz, bool $endOfDay = false): \DateTimeImmutable {
		$value = trim(str_replace('T', ' ', $value));
		$date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $value, $tz) ?: \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $tz)
			?: \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $tz);
		if ($date === false) {
			throw new \InvalidArgumentException('a date as 2026-11-12 or a time as 2026-11-12 09:00: ' . $value);
		}
		return $endOfDay && strlen($value) === 10 ? $date->modify('+1 day') : $date;
	}

	/** iCalendar text: backslashes, commas, semicolons and new lines escaped. */
	public static function text(string $value): string {
		return str_replace(["\\", ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], trim($value));
	}

	/** Lines joined with CRLF, each folded at 75 octets (not inside a UTF-8 character). */
	public static function join(array $lines): string {
		return implode("\r\n", array_map(function (string $line): string {
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
		}, $lines)) . "\r\n";
	}
}
