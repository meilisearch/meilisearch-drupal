<?php

declare(strict_types=1);

namespace Drupal\meilisearch\Api;

use Meilisearch\Client;
use Meilisearch\Contracts\IndexesResults;
use Meilisearch\Endpoints\Indexes;
use Meilisearch\Search\FacetSearchResult;
use Meilisearch\Search\SearchResult;

/**
 * Interface for the Meilisearch API service.
 */
interface MeilisearchApiServiceInterface {

  /**
   * Sets the Meilisearch server URL.
   */
  public function setUrl(string $url): void;

  /**
   * Sets the API key.
   */
  public function setApiKey(string $key): void;

  /**
   * Returns the underlying Meilisearch client.
   */
  public function connection(): Client;

  /**
   * Pings the server.
   */
  public function ping(): bool;

  /**
   * Returns server version info.
   */
  public function version(): array;

  /**
   * Returns TRUE if connected to Meilisearch Cloud.
   */
  public function isCloud(): bool;

  /**
   * Creates an index.
   */
  public function createIndex(string $indexUid): array;

  /**
   * Returns an index instance.
   */
  public function getIndex(string $indexUid): Indexes;

  /**
   * Lists all indexes.
   */
  public function listIndexes(): IndexesResults;

  /**
   * Deletes an index.
   */
  public function deleteIndex(string $indexUid): array;

  /**
   * Adds documents to an index.
   *
   * @param string $indexUid
   *   Index UID.
   * @param array $documents
   *   Documents to index.
   *
   * @return array
   *   Task info (includes taskUid).
   */
  public function addDocuments(string $indexUid, array $documents): array;

  /**
   * Deletes specific documents.
   */
  public function deleteDocuments(string $indexUid, array $ids): array;

  /**
   * Deletes all documents from an index.
   */
  public function deleteAllDocuments(string $indexUid): array;

  /**
   * Runs a search.
   */
  public function search(string $indexUid, string $query, array $options = []): SearchResult;

  /**
   * Runs a facet search.
   */
  public function searchFacets(string $indexUid, string $facetName, ?string $facetQuery = NULL, ?array $filter = NULL, ?string $query = NULL): FacetSearchResult;

  /**
   * Gets all settings for an index.
   */
  public function getSettings(string $indexUid): array;

  /**
   * Updates settings on an index (bulk).
   */
  public function updateSettings(string $indexUid, array $settings): array;

  /**
   * Waits for a task to complete.
   *
   * @param int $taskUid
   *   Task UID.
   *
   * @return array
   *   Completed task data.
   */
  public function waitForTask(int $taskUid): array;

}
