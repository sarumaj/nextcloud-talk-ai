<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TalkAi\Controller;

use OCA\TalkAi\Ai\Engines;
use OCA\TalkAi\Ai\Metrics;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\IAppConfig;
use OCP\IRequest;

/**
 * /apps/talk_ai/metrics: the engines' metrics (Ai\Metrics) for a Prometheus that
 * scrapes over HTTP, with the token of the app setting "metrics_token" as a
 * bearer token. Without that setting the route does not exist (404); a wrong
 * token counts against the caller's address like a wrong password.
 */
class MetricsController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private Metrics $metrics,
		private IAppConfig $appConfig,
	) {
		parent::__construct($appName, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'talk_ai_metrics')]
	public function run(): Response {
		$token = $this->appConfig->getValueString(Engines::APP, 'metrics_token', '', lazy: true);
		if ($token === '') {
			return new DataDisplayResponse('', Http::STATUS_NOT_FOUND);
		}
		$given = preg_match('/^Bearer\s+(\S+)$/i', $this->request->getHeader('Authorization'), $m) === 1 ? $m[1] : '';
		if (!hash_equals($token, $given)) {
			$response = new DataDisplayResponse('', Http::STATUS_UNAUTHORIZED, ['WWW-Authenticate' => 'Bearer']);
			$response->throttle();
			return $response;
		}
		return new DataDisplayResponse($this->metrics->render(), Http::STATUS_OK, ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8']);
	}
}
