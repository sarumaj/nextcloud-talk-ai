<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Ai;

/** What an engine can do now: the callers decide by it (images, PDFs, structured answers). */
final class Status {
	public function __construct(
		public readonly bool $ready,
		/** Why it is not ready (Errors: no-key, no-cli, no-model, not-ready …); '' when ready. */
		public readonly string $reason = '',
		public readonly string $provider = '',
		public readonly string $model = '',
		public readonly bool $images = false,
		public readonly int $maxImages = 0,
		public readonly bool $search = false,
		/** PDFs go to the model as document parts. */
		public readonly bool $documents = false,
		/** Request::$answerSchema is enforced by the provider (else asked for in the prompt). */
		public readonly bool $structured = false,
		public readonly bool $streaming = false,
		public readonly bool $caching = false,
		/** The engine's kind, for logs and settings: anthropic, gemini, aihub, … */
		public readonly string $engine = '',
	) {
	}

	public static function notReady(string $reason, string $engine = '', string $provider = '', string $model = ''): self {
		return new self(false, $reason, $provider, $model, engine: $engine);
	}

	/** The same status with fewer abilities: a scenario's or the admin's switches turn some off. */
	public function without(bool $images = true, bool $search = true, bool $documents = true, bool $streaming = true, bool $caching = true): self {
		return new self($this->ready, $this->reason, $this->provider, $this->model, $this->images && $images, $images ? $this->maxImages : 0,
			$this->search && $search, $this->documents && $documents, $this->structured, $this->streaming && $streaming, $this->caching && $caching, $this->engine);
	}

	/** @return array<string, bool|int|string> for JSON (OCS, occ) */
	public function toArray(): array {
		return get_object_vars($this);
	}
}
