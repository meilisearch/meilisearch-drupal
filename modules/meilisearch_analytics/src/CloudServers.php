<?php

declare(strict_types=1);

namespace Drupal\meilisearch_analytics;

use Drupal\meilisearch\Api\MeilisearchApiFactory;
use Drupal\meilisearch\Plugin\search_api\backend\MeilisearchBackend;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\ServerInterface;

/**
 * Tells which Search API servers are Meilisearch Cloud projects.
 *
 * Only Meilisearch Cloud has analytics, so every part of this module that
 * tracks searches asks here first and leaves other servers alone.
 */
class CloudServers {

  /**
   * Constructs a CloudServers service.
   */
  public function __construct(protected MeilisearchApiFactory $apiFactory) {}

  /**
   * Returns TRUE if the server is a Meilisearch Cloud project.
   *
   * The answer comes from an API service made for the configured URL, not
   * from MeilisearchBackend::getApi(): this also runs while the backend is
   * creating that service, in hook_meilisearch_request_headers_alter().
   *
   * @param \Drupal\search_api\ServerInterface|null $server
   *   The server, if known.
   */
  public function isCloudServer(?ServerInterface $server): bool {
    $backend = $server?->hasValidBackend() ? $server->getBackend() : NULL;
    if (!$backend instanceof MeilisearchBackend) {
      return FALSE;
    }
    $url = (string) ($backend->getConfiguration()['url'] ?? '');
    return $url !== '' && $this->apiFactory->create($url, '')->isCloud();
  }

  /**
   * Returns TRUE if the index is on a Meilisearch Cloud project.
   */
  public function isCloudIndex(IndexInterface $index): bool {
    return $index->hasValidServer() && $this->isCloudServer($index->getServerInstance());
  }

}
