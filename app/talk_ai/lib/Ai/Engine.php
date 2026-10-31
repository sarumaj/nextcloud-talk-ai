<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Ai;

/**
 * One AI provider: one model call per turn(). An engine never runs tools; it
 * returns the calls the model asked for, and Loop runs them. Nothing in lib/Ai/
 * knows about Talk, so the layer can become an app of its own.
 */
interface Engine {
	/** What this engine can do now, and why not when it is not ready. Cheap: no model call. */
	public function status(): Status;

	/** One model call. Errors come back in Turn::$error (Errors), never as exceptions. */
	public function turn(Request $request): Turn;

	/** @return list<string> the models the provider offers, from its API where it has one; [] when unknown */
	public function models(): array;
}
