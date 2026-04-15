<?php

declare(strict_types=1);

namespace Drupal\meilisearch\Api;

use Drupal\meilisearch\Client\MeilisearchClientFactoryInterface;
use Meilisearch\Client;
use Meilisearch\Contracts\FacetSearchQuery;
use Meilisearch\Contracts\IndexesResults;
use Meilisearch\Endpoints\Indexes;
use Meilisearch\Exceptions\ApiException;
use Meilisearch\Search\FacetSearchResult;
use Meilisearch\Search\SearchResult;

/**
 * Service wrapping the Meilisearch PHP client.
 */
class MeilisearchApiService implements MeilisearchApiServiceInterface {

  protected MeilisearchClientFactoryInterface $clientFactory;
  protected string $url = '';
  protected string $apiKey = '';
  protected ?Client $client = NULL;

  public function __construct(MeilisearchClientFactoryInterface $clientFactory) {
    $this->clientFactory = $clientFactory;
  }

  public function setUrl(string $url): void {
    // Strip trailing slash.
    $this->url = rtrim($url, '/');
    // Invalidate cached client.
    $this->client = NULL;
  }

  public function setApiKey(string $key): void {
    $this->apiKey = $key;
    $this->client = NULL;
  }

  public function connection(): Client {
    if ($this->client === NULL) {
      $this->client = $this->clientFactory->getInstance($this->url, $this->apiKey);
    }
    return $this->client;
  }

  public function ping(): bool {
    try {
      $this->connection()->health();
      return TRUE;
    }
    catch (\Throwable $e) {
      return FALSE;
    }
  }

  public function version(): array {
    try {
      return $this->connection()->version();
    }
    catch (ApiException $e) {
      throw new MeilisearchApiException($e->getMessage(), $e->getCode(), $e);
    }
  }

  public function isCloud(): bool {
    return (bool) preg_match('/(?:^|\.)meilisearch\.io$/i', parse_url($this->url, PHP_URL_HOST) ?? '');
  }

  public function createIndex(string $indexUid): array {
    try {
      return $this->connection()->createIndex($indexUid);
    }
    catch (ApiException $e) {
      throw new MeilisearchApiException($e->getMessage(), $e->getCode(), $e);
    }
  }

  public function getIndex(string $indexUid): Indexes {
    try {
      return $this->connection()->index($indexUid);
    }
    catch (ApiException $e) {
      throw new MeilisearchApiException($e->getMessage(), $e->getCode(), $e);
    }
  }

  public function listIndexes(): IndexesResults {
    try {
      return $this->connection()->getIndexes();
    }
    catch (ApiException $e) {
      throw new MeilisearchApiException($e->getMessage(), $e->getCode(), $e);
    }
  }

  public function deleteIndex(string $indexUid): array {
    try {
      return $this->connection()->deleteIndex($indexUid);
    }
    catch (ApiException $e) {
      throw new MeilisearchApiException($e->getMessage(), $e->getCode(), $e);
    }
  }

  public function addDocuments(string $indexUid, array $documents): array {
    try {
      return $this->connection()->index($indexUid)->addDocuments($documents, 'id');
    }
    catch (ApiException $e) {
      throw new MeilisearchApiException($e->getMessage(), $e->getCode(), $e);
    }
  }

  public function deleteDocuments(string $indexUid, array $ids): array {
    try {
      return $this->connection()->index($indexUid)->deleteDocuments($ids);
    }
    catch (ApiException $e) {
      throw new MeilisearchApiException($e->getMessage(), $e->getCode(), $e);
    }
  }

  public function deleteAllDocuments(string $indexUid): array {
    try {
      return $this->connection()->index($indexUid)->deleteAllDocuments();
    }
    catch (ApiException $e) {
      throw new MeilisearchApiException($e->getMessage(), $e->getCode(), $e);
    }
  }

  public function search(string $indexUid, string $query, array $options = []): SearchResult {
    try {
      return $this->connection()->index($indexUid)->search($query, $options);
    }
    catch (ApiException $e) {
      throw new MeilisearchApiException($e->getMessage(), $e->getCode(), $e);
    }
  }

  public function searchFacets(string $indexUid, string $facetName, ?string $facetQuery = NULL, ?array $filter = NULL, ?string $query = NULL): FacetSearchResult {
    try {
      $queryObj = (new FacetSearchQuery())
        ->setFacetName($facetName);
      if ($facetQuery !== NULL) {
        $queryObj->setFacetQuery($facetQuery);
      }
      if ($filter !== NULL) {
        $queryObj->setFilter($filter);
      }
      if ($query !== NULL) {
        $queryObj->setQuery($query);
      }
      return $this->connection()->index($indexUid)->facetSearch($queryObj);
    }
    catch (ApiException $e) {
      throw new MeilisearchApiException($e->getMessage(), $e->getCode(), $e);
    }
  }

  public function getSettings(string $indexUid): array {
    try {
      return $this->connection()->index($indexUid)->getSettings();
    }
    catch (ApiException $e) {
      throw new MeilisearchApiException($e->getMessage(), $e->getCode(), $e);
    }
  }

  public function updateSettings(string $indexUid, array $settings): array {
    try {
      return $this->connection()->index($indexUid)->updateSettings($settings);
    }
    catch (ApiException $e) {
      throw new MeilisearchApiException($e->getMessage(), $e->getCode(), $e);
    }
  }

  public function waitForTask(int $taskUid): array {
    try {
      return $this->connection()->waitForTask($taskUid);
    }
    catch (ApiException $e) {
      throw new MeilisearchApiException($e->getMessage(), $e->getCode(), $e);
    }
  }

}
