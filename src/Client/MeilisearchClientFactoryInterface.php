<?php

declare(strict_types=1);

namespace Drupal\meilisearch\Client;

use Meilisearch\Client;

/**
 * Interface for creating Meilisearch Client instances.
 */
interface MeilisearchClientFactoryInterface {

  /**
   * Returns a configured Meilisearch client.
   *
   * @param string $url
   *   The Meilisearch server URL (with scheme and port).
   * @param string $apiKey
   *   The master or API key.
   *
   * @return \Meilisearch\Client
   *   A configured client instance.
   */
  public function getInstance(string $url, string $apiKey): Client;

}
