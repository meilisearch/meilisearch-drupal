<?php

declare(strict_types=1);

namespace Drupal\meilisearch_analytics\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\meilisearch_analytics\Analytics\AnalyticsService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Proxy endpoint for click tracking (avoids exposing API key in browser).
 */
class ClickTrackingController extends ControllerBase implements ContainerInjectionInterface {

  protected AnalyticsService $analytics;

  public function __construct(AnalyticsService $analytics) {
    $this->analytics = $analytics;
  }

  public static function create(ContainerInterface $container): self {
    return new self($container->get('meilisearch_analytics.service'));
  }

  public function record(Request $request): JsonResponse {
    $data = json_decode($request->getContent(), TRUE) ?: [];
    $ok = $this->analytics->recordClick([
      'indexUid' => (string) ($data['indexUid'] ?? ''),
      'queryUid' => (string) ($data['queryUid'] ?? ''),
      'objectId' => (string) ($data['objectId'] ?? ''),
      'position' => (int) ($data['position'] ?? 0),
      'eventName' => (string) ($data['eventName'] ?? 'Search Result Clicked'),
    ]);
    return new JsonResponse(['success' => $ok]);
  }

}
