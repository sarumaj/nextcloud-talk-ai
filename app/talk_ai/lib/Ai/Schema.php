<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Ai;

/** Enough of JSON Schema to tell a model what is wrong with its arguments or answer. */
final class Schema {
	/** '' when the value fits, else where and why it does not. */
	public static function check(mixed $value, array $schema, string $at = '$'): string {
		$types = (array)($schema['type'] ?? []);
		if ($types !== [] && !array_filter($types, fn ($t) => self::is($value, (string)$t))) {
			return $at . ' should be ' . implode(' or ', $types);
		}
		if (isset($schema['enum']) && is_array($schema['enum']) && !in_array($value, $schema['enum'], true)) {
			return $at . ' should be one of ' . json_encode($schema['enum'], JSON_UNESCAPED_UNICODE);
		}
		if (is_array($value) && (!array_is_list($value) || $value === [])) {
			foreach ((array)($schema['required'] ?? []) as $key) {
				if (!array_key_exists($key, $value)) {
					return $at . '.' . $key . ' is missing';
				}
			}
			foreach ((array)($schema['properties'] ?? []) as $key => $sub) {
				if (array_key_exists($key, $value) && is_array($sub) && ($fail = self::check($value[$key], $sub, $at . '.' . $key)) !== '') {
					return $fail;
				}
			}
		}
		if (is_array($value) && array_is_list($value) && is_array($schema['items'] ?? null)) {
			foreach ($value as $i => $item) {
				if (($fail = self::check($item, $schema['items'], $at . '[' . $i . ']')) !== '') {
					return $fail;
				}
			}
		}
		return '';
	}

	/** An object schema as providers want it: "properties" an object even when empty. */
	public static function object(array $schema): array {
		$schema['type'] ??= 'object';
		if ($schema['type'] === 'object') {
			$schema['properties'] = (object)($schema['properties'] ?? []);
		}
		return $schema;
	}

	private static function is(mixed $value, string $type): bool {
		return match ($type) {
			'object' => is_array($value) && (!array_is_list($value) || $value === []),
			'array' => is_array($value) && array_is_list($value),
			'string' => is_string($value),
			'number' => is_int($value) || is_float($value),
			'integer' => is_int($value),
			'boolean' => is_bool($value),
			'null' => $value === null,
			default => true,
		};
	}
}
