<?php

declare(strict_types=1);

namespace Drupal\meilisearch\Client;

use Meilisearch\Client;

/**
 * Factory for Meilisearch Client instances.
 */
class MeilisearchClientFactory implements MeilisearchClientFactoryInterface {

  /**
   * {@inheritdoc}
   */
  public function getInstance(string $url, string $apiKey): Client {
    return new Client($url, $apiKey);
  }

}
