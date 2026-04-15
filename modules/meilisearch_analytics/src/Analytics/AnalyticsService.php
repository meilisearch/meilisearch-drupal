<?php

declare(strict_types=1);

namespace Drupal\meilisearch_analytics\Analytics;

use Drupal\Core\Session\AccountInterface;
use Drupal\meilisearch\Api\MeilisearchApiException;
use Drupal\meilisearch\Api\MeilisearchApiServiceInterface;
use Meilisearch\Client;
use Psr\Log\LoggerInterface;

/**
 * Sends click and conversion events to Meilisearch /events endpoint.
 */
class AnalyticsService {

  protected MeilisearchApiServiceInterface $api;
  protected AccountInterface $currentUser;
  protected LoggerInterface $logger;

  public function __construct(
    MeilisearchApiServiceInterface $api,
    AccountInterface $currentUser,
    LoggerInterface $logger,
  ) {
    $this->api = $api;
    $this->currentUser = $currentUser;
    $this->logger = $logger;
  }

  /**
   * Records a click event.
   *
   * @param array $payload
   *   ['indexUid', 'queryUid', 'objectId', 'position', 'eventName'].
   */
  public function recordClick(array $payload): bool {
    return $this->sendEvent('click', $payload);
  }

  /**
   * Records a conversion event.
   */
  public function recordConversion(array $payload): bool {
    return $this->sendEvent('conversion', $payload);
  }

  /**
   * Sends an event to Meilisearch.
   */
  protected function sendEvent(string $type, array $payload): bool {
    try {
      $body = array_filter([
        'eventType' => $type,
        'eventName' => $payload['eventName'] ?? ucfirst($type),
        'indexUid' => $payload['indexUid'] ?? NULL,
        'userId' => $payload['userId'] ?? $this->resolveUserId(),
        'queryUid' => $payload['queryUid'] ?? NULL,
        'objectId' => $payload['objectId'] ?? NULL,
        'position' => $payload['position'] ?? NULL,
      ], static fn($v) => $v !== NULL);

      // meilisearch-php doesn't ship a dedicated events method yet; use raw HTTP.
      /** @var Client $client */
      $client = $this->api->connection();
      $reflection = new \ReflectionClass($client);
      $httpProperty = $reflection->getProperty('http');
      $httpProperty->setAccessible(TRUE);
      $http = $httpProperty->getValue($client);
      $http->post('/events', $body);
      return TRUE;
    }
    catch (MeilisearchApiException | \Throwable $e) {
      $this->logger->error('Meilisearch analytics event failed: @msg', ['@msg' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * Resolves a user ID for anonymous or authenticated users.
   */
  protected function resolveUserId(): string {
    if ($this->currentUser->isAuthenticated()) {
      return 'user-' . $this->currentUser->id();
    }
    // Anonymous — hash the session ID.
    $sid = session_id() ?: 'anonymous';
    return 'anon-' . substr(hash('sha256', $sid), 0, 16);
  }

}
