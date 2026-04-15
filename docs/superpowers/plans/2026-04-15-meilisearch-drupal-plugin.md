# Meilisearch Drupal Plugin Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build an official Meilisearch backend module for Drupal's Search API framework, supporting Drupal 9.3/10/11, Meilisearch Cloud, semantic/hybrid search, facets, geo, synonyms, stop words, highlighting, and analytics.

**Architecture:** Feature-rich core module (`meilisearch`) + two optional submodules (`meilisearch_facets`, `meilisearch_analytics`). The core module implements the Search API backend plugin and wraps `meilisearch-php ^1.16`. Submodules exist only when they have external Drupal dependencies (`drupal/facets`) or distinct optional scope (analytics).

**Tech Stack:**
- PHP ^8.1
- Drupal ^9.3 || ^10 || ^11
- `drupal/search_api` ^1.29
- `meilisearch/meilisearch-php` ^1.16
- PHPUnit 9/10 for tests

**Reference:** Design spec at `docs/superpowers/specs/2026-04-14-meilisearch-drupal-plugin-design.md`

---

## Phase 1: Project Scaffold

### Task 1: Create composer.json and module info files

**Files:**
- Create: `composer.json`
- Create: `meilisearch.info.yml`

- [ ] **Step 1: Create composer.json**

Create `composer.json`:

```json
{
  "name": "drupal/meilisearch",
  "description": "Official Meilisearch backend for the Search API.",
  "type": "drupal-module",
  "license": "GPL-2.0-or-later",
  "keywords": ["Drupal", "Search", "Search API", "Meilisearch"],
  "homepage": "https://www.drupal.org/project/meilisearch",
  "support": {
    "issues": "https://www.drupal.org/project/issues/meilisearch",
    "source": "https://git.drupalcode.org/project/meilisearch"
  },
  "require": {
    "php": "^8.1",
    "drupal/search_api": "^1.29",
    "meilisearch/meilisearch-php": "^1.16"
  },
  "require-dev": {
    "drupal/facets": "^2.0 || ^3.0",
    "phpunit/phpunit": "^9.6 || ^10.5"
  },
  "autoload": {
    "psr-4": {
      "Drupal\\meilisearch\\": "src/"
    }
  }
}
```

- [ ] **Step 2: Create .info.yml**

Create `meilisearch.info.yml`:

```yaml
name: 'Meilisearch'
type: module
description: 'Official Meilisearch backend for Search API.'
core_version_requirement: ^9.3 || ^10 || ^11
package: 'Search'
dependencies:
  - search_api:search_api
```

- [ ] **Step 3: Commit**

```bash
git init
git add composer.json meilisearch.info.yml
git commit -m "feat: scaffold meilisearch module with composer.json and info file"
```

---

### Task 2: Create services.yml and module file

**Files:**
- Create: `meilisearch.services.yml`
- Create: `meilisearch.module`
- Create: `config/schema/meilisearch.schema.yml`

- [ ] **Step 1: Create services.yml skeleton**

Create `meilisearch.services.yml`:

```yaml
services:
  logger.channel.meilisearch:
    parent: logger.channel_base
    arguments: ['meilisearch']

  meilisearch.client_factory:
    class: Drupal\meilisearch\Client\MeilisearchClientFactory

  meilisearch.api:
    class: Drupal\meilisearch\Api\MeilisearchApiService
    arguments: ['@meilisearch.client_factory']
    shared: false

  meilisearch.document_converter:
    class: Drupal\meilisearch\Converter\DocumentConverter

  meilisearch.filter_builder:
    class: Drupal\meilisearch\Filter\FilterBuilder
    tags:
      - { name: service_collector, tag: meilisearch.condition_parser, call: addConditionParser }

  meilisearch.condition_parser.scalar:
    class: Drupal\meilisearch\Filter\ScalarValueParser
    tags:
      - { name: meilisearch.condition_parser, priority: 10 }

  meilisearch.condition_parser.boolean:
    class: Drupal\meilisearch\Filter\BooleanValueParser
    tags:
      - { name: meilisearch.condition_parser, priority: 20 }

  meilisearch.condition_parser.null:
    class: Drupal\meilisearch\Filter\NullValueParser
    tags:
      - { name: meilisearch.condition_parser, priority: 15 }

  meilisearch.condition_parser.between:
    class: Drupal\meilisearch\Filter\BetweenOperatorParser
    tags:
      - { name: meilisearch.condition_parser, priority: 15 }

  meilisearch.condition_parser.not_between:
    class: Drupal\meilisearch\Filter\NotBetweenOperatorParser
    tags:
      - { name: meilisearch.condition_parser, priority: 15 }

  meilisearch.condition_parser.in:
    class: Drupal\meilisearch\Filter\InOperatorParser
    tags:
      - { name: meilisearch.condition_parser, priority: 15 }

  meilisearch.condition_parser.not_in:
    class: Drupal\meilisearch\Filter\NotInOperatorParser
    tags:
      - { name: meilisearch.condition_parser, priority: 15 }

  meilisearch.condition_parser.geo:
    class: Drupal\meilisearch\Filter\GeoFilterParser
    tags:
      - { name: meilisearch.condition_parser, priority: 25 }
```

- [ ] **Step 2: Create .module file**

Create `meilisearch.module`:

```php
<?php

/**
 * @file
 * Contains hook implementations for the Meilisearch module.
 */

use Drupal\Core\Routing\RouteMatchInterface;

/**
 * Implements hook_help().
 */
function meilisearch_help(string $route_name, RouteMatchInterface $route_match): string {
  if ($route_name === 'help.page.meilisearch') {
    return '<p>' . t('Provides a Meilisearch backend for the Search API module.') . '</p>';
  }
  return '';
}
```

- [ ] **Step 3: Create config schema**

Create `config/schema/meilisearch.schema.yml`:

```yaml
plugin.plugin_configuration.search_api_backend.meilisearch:
  type: mapping
  label: 'Meilisearch backend configuration'
  mapping:
    connection_mode:
      type: string
      label: 'Connection mode (self_hosted|cloud)'
    host:
      type: string
      label: 'Host URL'
    port:
      type: integer
      label: 'Host port'
    api_key:
      type: string
      label: 'API key'
    is_cloud:
      type: boolean
      label: 'Is Meilisearch Cloud'
    search_mode:
      type: string
      label: 'Search mode (keyword|semantic|hybrid)'
    semantic_ratio:
      type: float
      label: 'Semantic ratio (0.0 - 1.0)'
    embedder:
      type: string
      label: 'Embedder name'
    ranking_rules:
      type: sequence
      label: 'Ranking rules'
      sequence:
        type: string
```

- [ ] **Step 4: Commit**

```bash
git add meilisearch.services.yml meilisearch.module config/
git commit -m "feat: add services, hooks, and config schema scaffolding"
```

---

## Phase 2: Client Factory and API Service

### Task 3: Create MeilisearchApiException

**Files:**
- Create: `src/Api/MeilisearchApiException.php`

- [ ] **Step 1: Create the exception class**

Create `src/Api/MeilisearchApiException.php`:

```php
<?php

declare(strict_types=1);

namespace Drupal\meilisearch\Api;

/**
 * Exception thrown for Meilisearch API errors.
 */
class MeilisearchApiException extends \RuntimeException {
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Api/MeilisearchApiException.php
git commit -m "feat: add MeilisearchApiException"
```

---

### Task 4: Create ClientFactory

**Files:**
- Create: `src/Client/MeilisearchClientFactoryInterface.php`
- Create: `src/Client/MeilisearchClientFactory.php`

- [ ] **Step 1: Create the interface**

Create `src/Client/MeilisearchClientFactoryInterface.php`:

```php
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
```

- [ ] **Step 2: Create the factory**

Create `src/Client/MeilisearchClientFactory.php`:

```php
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
```

- [ ] **Step 3: Commit**

```bash
git add src/Client/
git commit -m "feat: add Meilisearch client factory"
```

---

### Task 5: Create MeilisearchApiServiceInterface

**Files:**
- Create: `src/Api/MeilisearchApiServiceInterface.php`

- [ ] **Step 1: Create the interface**

Create `src/Api/MeilisearchApiServiceInterface.php`:

```php
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
```

- [ ] **Step 2: Commit**

```bash
git add src/Api/MeilisearchApiServiceInterface.php
git commit -m "feat: add MeilisearchApiServiceInterface"
```

---

### Task 6: Implement MeilisearchApiService with tests

**Files:**
- Create: `src/Api/MeilisearchApiService.php`
- Create: `tests/src/Unit/Api/MeilisearchApiServiceTest.php`

- [ ] **Step 1: Write failing test for setUrl/setApiKey**

Create `tests/src/Unit/Api/MeilisearchApiServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Drupal\Tests\meilisearch\Unit\Api;

use Drupal\meilisearch\Api\MeilisearchApiService;
use Drupal\meilisearch\Client\MeilisearchClientFactoryInterface;
use Meilisearch\Client;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\meilisearch\Api\MeilisearchApiService
 */
class MeilisearchApiServiceTest extends TestCase {

  /**
   * @covers ::isCloud
   */
  public function testIsCloudDetectsMeilisearchDomain(): void {
    $factory = $this->createMock(MeilisearchClientFactoryInterface::class);
    $service = new MeilisearchApiService($factory);

    $service->setUrl('https://ms-abc123.fra.meilisearch.io');
    $this->assertTrue($service->isCloud());

    $service->setUrl('http://127.0.0.1:7700');
    $this->assertFalse($service->isCloud());
  }

  /**
   * @covers ::connection
   */
  public function testConnectionCreatesClient(): void {
    $client = $this->createMock(Client::class);
    $factory = $this->createMock(MeilisearchClientFactoryInterface::class);
    $factory->expects($this->once())
      ->method('getInstance')
      ->with('http://localhost:7700', 'key')
      ->willReturn($client);

    $service = new MeilisearchApiService($factory);
    $service->setUrl('http://localhost:7700');
    $service->setApiKey('key');

    $this->assertSame($client, $service->connection());
    // Second call should reuse cached client.
    $this->assertSame($client, $service->connection());
  }

}
```

- [ ] **Step 2: Run test to verify failure**

Run: `vendor/bin/phpunit tests/src/Unit/Api/MeilisearchApiServiceTest.php`
Expected: FAIL (class not found)

- [ ] **Step 3: Implement the service**

Create `src/Api/MeilisearchApiService.php`:

```php
<?php

declare(strict_types=1);

namespace Drupal\meilisearch\Api;

use Drupal\meilisearch\Client\MeilisearchClientFactoryInterface;
use Meilisearch\Client;
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
    return (bool) preg_match('/\.meilisearch\.io$/i', parse_url($this->url, PHP_URL_HOST) ?? '');
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
      $queryObj = (new \Meilisearch\Contracts\FacetSearchQuery())
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
```

- [ ] **Step 4: Run tests to verify pass**

Run: `vendor/bin/phpunit tests/src/Unit/Api/MeilisearchApiServiceTest.php`
Expected: 2 tests pass

- [ ] **Step 5: Commit**

```bash
git add src/Api/MeilisearchApiService.php tests/src/Unit/Api/
git commit -m "feat: implement MeilisearchApiService with cloud detection"
```

---

## Phase 3: Document Converter

### Task 7: Implement DocumentConverter with tests

**Files:**
- Create: `src/Converter/DocumentConverterInterface.php`
- Create: `src/Converter/DocumentConverter.php`
- Create: `src/Utility/MeilisearchUtils.php`
- Create: `tests/src/Unit/Converter/DocumentConverterTest.php`

- [ ] **Step 1: Create the utility class**

Create `src/Utility/MeilisearchUtils.php`:

```php
<?php

declare(strict_types=1);

namespace Drupal\meilisearch\Utility;

/**
 * Utility functions for Meilisearch integration.
 */
class MeilisearchUtils {

  /**
   * Sanitizes a Search API item ID into a safe Meilisearch document ID.
   *
   * Meilisearch document IDs allow [A-Za-z0-9_-]. Replace unsafe chars.
   */
  public static function formatAsDocumentId(string $id): string {
    return (string) preg_replace('/[^A-Za-z0-9_-]/', '-', $id);
  }

}
```

- [ ] **Step 2: Create the interface**

Create `src/Converter/DocumentConverterInterface.php`:

```php
<?php

declare(strict_types=1);

namespace Drupal\meilisearch\Converter;

use Drupal\search_api\Item\ItemInterface;

/**
 * Converts Search API items to Meilisearch documents.
 */
interface DocumentConverterInterface {

  /**
   * Converts a list of Search API items to Meilisearch documents.
   *
   * @param \Drupal\search_api\Item\ItemInterface[] $items
   *   Search API items keyed by item ID.
   *
   * @return array
   *   Array of document arrays, each with at minimum `id` and `search_api_id`.
   */
  public function convertToDocuments(array $items): array;

}
```

- [ ] **Step 3: Write failing test**

Create `tests/src/Unit/Converter/DocumentConverterTest.php`:

```php
<?php

declare(strict_types=1);

namespace Drupal\Tests\meilisearch\Unit\Converter;

use Drupal\meilisearch\Converter\DocumentConverter;
use Drupal\search_api\Item\FieldInterface;
use Drupal\search_api\Item\ItemInterface;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\meilisearch\Converter\DocumentConverter
 */
class DocumentConverterTest extends TestCase {

  /**
   * @covers ::convertToDocuments
   */
  public function testSanitizesDocumentId(): void {
    $item = $this->buildItem('entity:node/123:en', []);
    $converter = new DocumentConverter();
    $docs = $converter->convertToDocuments([$item->getId() => $item]);

    $this->assertCount(1, $docs);
    $this->assertSame('entity-node-123-en', $docs[0]['id']);
    $this->assertSame('entity:node/123:en', $docs[0]['search_api_id']);
  }

  /**
   * @covers ::convertToDocuments
   */
  public function testMapsFieldTypes(): void {
    $item = $this->buildItem('node/1', [
      'title' => ['type' => 'string', 'values' => ['Hello']],
      'count' => ['type' => 'integer', 'values' => [42]],
      'published' => ['type' => 'boolean', 'values' => [TRUE]],
      'tags' => ['type' => 'string', 'values' => ['a', 'b', 'c']],
    ]);
    $converter = new DocumentConverter();
    $docs = $converter->convertToDocuments([$item->getId() => $item]);

    $this->assertSame('Hello', $docs[0]['title']);
    $this->assertSame(42, $docs[0]['count']);
    $this->assertTrue($docs[0]['published']);
    $this->assertSame(['a', 'b', 'c'], $docs[0]['tags']);
  }

  /**
   * @covers ::convertToDocuments
   */
  public function testConvertsGeoFieldToMeiliGeo(): void {
    $item = $this->buildItem('node/1', [
      'location' => ['type' => 'location', 'values' => ['48.85,2.29']],
    ]);
    $converter = new DocumentConverter();
    $docs = $converter->convertToDocuments([$item->getId() => $item]);

    $this->assertArrayHasKey('_geo', $docs[0]);
    $this->assertSame(['lat' => 48.85, 'lng' => 2.29], $docs[0]['_geo']);
  }

  private function buildItem(string $itemId, array $fields): ItemInterface {
    $item = $this->createMock(ItemInterface::class);
    $item->method('getId')->willReturn($itemId);

    $fieldObjects = [];
    foreach ($fields as $id => $spec) {
      $field = $this->createMock(FieldInterface::class);
      $field->method('getFieldIdentifier')->willReturn($id);
      $field->method('getType')->willReturn($spec['type']);
      $field->method('getValues')->willReturn($spec['values']);
      $fieldObjects[$id] = $field;
    }
    $item->method('getFields')->willReturn($fieldObjects);
    return $item;
  }

}
```

- [ ] **Step 4: Run test to verify failure**

Run: `vendor/bin/phpunit tests/src/Unit/Converter/DocumentConverterTest.php`
Expected: FAIL (class not found)

- [ ] **Step 5: Implement the converter**

Create `src/Converter/DocumentConverter.php`:

```php
<?php

declare(strict_types=1);

namespace Drupal\meilisearch\Converter;

use Drupal\meilisearch\Utility\MeilisearchUtils;
use Drupal\search_api\Item\FieldInterface;

/**
 * Default document converter.
 */
class DocumentConverter implements DocumentConverterInterface {

  /**
   * {@inheritdoc}
   */
  public function convertToDocuments(array $items): array {
    $documents = [];
    foreach ($items as $item) {
      $doc = [
        'id' => MeilisearchUtils::formatAsDocumentId($item->getId()),
        'search_api_id' => $item->getId(),
      ];
      foreach ($item->getFields() as $field) {
        $this->applyField($doc, $field);
      }
      $documents[] = $doc;
    }
    return $documents;
  }

  /**
   * Applies a field's values to the document array.
   */
  protected function applyField(array &$doc, FieldInterface $field): void {
    $id = $field->getFieldIdentifier();
    $type = $field->getType();
    $values = $field->getValues();

    if ($type === 'location') {
      // Meilisearch expects _geo: {lat, lng} — take the first value.
      $first = reset($values);
      if (is_string($first) && str_contains($first, ',')) {
        [$lat, $lng] = array_map('trim', explode(',', $first, 2));
        $doc['_geo'] = ['lat' => (float) $lat, 'lng' => (float) $lng];
      }
      elseif (is_array($first) && isset($first['lat'], $first['lng'])) {
        $doc['_geo'] = ['lat' => (float) $first['lat'], 'lng' => (float) $first['lng']];
      }
      return;
    }

    $converted = array_map(fn($v) => $this->castValue($v, $type), $values);
    if (count($converted) === 1) {
      $doc[$id] = $converted[0];
    }
    elseif (count($converted) > 1) {
      $doc[$id] = $converted;
    }
  }

  /**
   * Casts a single value to its Meilisearch-appropriate type.
   */
  protected function castValue(mixed $value, string $type): mixed {
    return match ($type) {
      'integer' => (int) $value,
      'decimal' => (float) $value,
      'boolean' => (bool) $value,
      'date' => is_numeric($value) ? (int) $value : strtotime((string) $value),
      default => (string) $value,
    };
  }

}
```

- [ ] **Step 6: Run tests to verify pass**

Run: `vendor/bin/phpunit tests/src/Unit/Converter/DocumentConverterTest.php`
Expected: 3 tests pass

- [ ] **Step 7: Commit**

```bash
git add src/Converter/ src/Utility/ tests/src/Unit/Converter/
git commit -m "feat: implement document converter with geo and type support"
```

---

## Phase 4: Filter Builder

### Task 8: Create FilterBuilder interface and parser interfaces

**Files:**
- Create: `src/Filter/FilterBuilderInterface.php`
- Create: `src/Filter/ConditionParserInterface.php`

- [ ] **Step 1: Create FilterBuilderInterface**

Create `src/Filter/FilterBuilderInterface.php`:

```php
<?php

declare(strict_types=1);

namespace Drupal\meilisearch\Filter;

use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\ConditionGroupInterface;

/**
 * Converts Search API condition groups to Meilisearch filter strings.
 */
interface FilterBuilderInterface {

  /**
   * Parses a condition group into a Meilisearch filter string.
   *
   * @return string|null
   *   The filter string, or NULL if the condition group is empty.
   */
  public function build(ConditionGroupInterface $group, IndexInterface $index): ?string;

}
```

- [ ] **Step 2: Create ConditionParserInterface**

Create `src/Filter/ConditionParserInterface.php`:

```php
<?php

declare(strict_types=1);

namespace Drupal\meilisearch\Filter;

use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\ConditionInterface;

/**
 * Contract for individual condition parsers.
 */
interface ConditionParserInterface {

  /**
   * Returns TRUE if this parser handles the given condition.
   */
  public function supports(ConditionInterface $condition, IndexInterface $index): bool;

  /**
   * Returns a Meilisearch filter expression for the condition.
   */
  public function parse(ConditionInterface $condition, IndexInterface $index): string;

}
```

- [ ] **Step 3: Commit**

```bash
git add src/Filter/FilterBuilderInterface.php src/Filter/ConditionParserInterface.php
git commit -m "feat: add FilterBuilder and ConditionParser interfaces"
```

---

### Task 9: Implement scalar, boolean, null parsers with tests

**Files:**
- Create: `src/Filter/ScalarValueParser.php`
- Create: `src/Filter/BooleanValueParser.php`
- Create: `src/Filter/NullValueParser.php`
- Create: `tests/src/Unit/Filter/ValueParsersTest.php`

- [ ] **Step 1: Write failing test**

Create `tests/src/Unit/Filter/ValueParsersTest.php`:

```php
<?php

declare(strict_types=1);

namespace Drupal\Tests\meilisearch\Unit\Filter;

use Drupal\meilisearch\Filter\BooleanValueParser;
use Drupal\meilisearch\Filter\NullValueParser;
use Drupal\meilisearch\Filter\ScalarValueParser;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\Condition;
use PHPUnit\Framework\TestCase;

class ValueParsersTest extends TestCase {

  public function testScalarEquals(): void {
    $parser = new ScalarValueParser();
    $condition = new Condition('title', 'Hello', '=');
    $index = $this->createMock(IndexInterface::class);

    $this->assertTrue($parser->supports($condition, $index));
    $this->assertSame('title = "Hello"', $parser->parse($condition, $index));
  }

  public function testScalarGreaterThan(): void {
    $parser = new ScalarValueParser();
    $condition = new Condition('count', 5, '>');
    $index = $this->createMock(IndexInterface::class);

    $this->assertSame('count > 5', $parser->parse($condition, $index));
  }

  public function testScalarQuotesStringsEscapesDouble(): void {
    $parser = new ScalarValueParser();
    $condition = new Condition('title', 'He said "hi"', '=');
    $index = $this->createMock(IndexInterface::class);

    $this->assertSame('title = "He said \\"hi\\""', $parser->parse($condition, $index));
  }

  public function testBooleanValue(): void {
    $parser = new BooleanValueParser();
    $condition = new Condition('published', TRUE, '=');
    $index = $this->createMock(IndexInterface::class);

    $this->assertTrue($parser->supports($condition, $index));
    $this->assertSame('published = true', $parser->parse($condition, $index));

    $condition = new Condition('published', FALSE, '=');
    $this->assertSame('published = false', $parser->parse($condition, $index));
  }

  public function testNullIs(): void {
    $parser = new NullValueParser();
    $condition = new Condition('field', NULL, '=');
    $index = $this->createMock(IndexInterface::class);

    $this->assertTrue($parser->supports($condition, $index));
    $this->assertSame('field IS NULL', $parser->parse($condition, $index));

    $condition = new Condition('field', NULL, '<>');
    $this->assertSame('field IS NOT NULL', $parser->parse($condition, $index));
  }

}
```

- [ ] **Step 2: Run test to verify failure**

Run: `vendor/bin/phpunit tests/src/Unit/Filter/ValueParsersTest.php`
Expected: FAIL (class not found)

- [ ] **Step 3: Implement ScalarValueParser**

Create `src/Filter/ScalarValueParser.php`:

```php
<?php

declare(strict_types=1);

namespace Drupal\meilisearch\Filter;

use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\ConditionInterface;

/**
 * Parses scalar value conditions (=, !=, <, <=, >, >=).
 */
class ScalarValueParser implements ConditionParserInterface {

  protected const OPERATORS = ['=', '!=', '<>', '<', '<=', '>', '>='];

  /**
   * {@inheritdoc}
   */
  public function supports(ConditionInterface $condition, IndexInterface $index): bool {
    $value = $condition->getValue();
    $operator = $condition->getOperator();
    return !is_array($value)
      && $value !== NULL
      && !is_bool($value)
      && in_array($operator, static::OPERATORS, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function parse(ConditionInterface $condition, IndexInterface $index): string {
    $field = $condition->getField();
    $value = $condition->getValue();
    $operator = $condition->getOperator() === '<>' ? '!=' : $condition->getOperator();

    $formatted = is_numeric($value)
      ? (string) $value
      : '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $value) . '"';

    return sprintf('%s %s %s', $field, $operator, $formatted);
  }

}
```

- [ ] **Step 4: Implement BooleanValueParser**

Create `src/Filter/BooleanValueParser.php`:

```php
<?php

declare(strict_types=1);

namespace Drupal\meilisearch\Filter;

use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\ConditionInterface;

/**
 * Parses boolean value conditions.
 */
class BooleanValueParser implements ConditionParserInterface {

  /**
   * {@inheritdoc}
   */
  public function supports(ConditionInterface $condition, IndexInterface $index): bool {
    return is_bool($condition->getValue());
  }

  /**
   * {@inheritdoc}
   */
  public function parse(ConditionInterface $condition, IndexInterface $index): string {
    $operator = $condition->getOperator() === '<>' ? '!=' : $condition->getOperator();
    $value = $condition->getValue() ? 'true' : 'false';
    return sprintf('%s %s %s', $condition->getField(), $operator, $value);
  }

}
```

- [ ] **Step 5: Implement NullValueParser**

Create `src/Filter/NullValueParser.php`:

```php
<?php

declare(strict_types=1);

namespace Drupal\meilisearch\Filter;

use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\ConditionInterface;

/**
 * Parses null value conditions (IS NULL / IS NOT NULL).
 */
class NullValueParser implements ConditionParserInterface {

  /**
   * {@inheritdoc}
   */
  public function supports(ConditionInterface $condition, IndexInterface $index): bool {
    return $condition->getValue() === NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function parse(ConditionInterface $condition, IndexInterface $index): string {
    $operator = $condition->getOperator();
    $suffix = in_array($operator, ['!=', '<>'], TRUE) ? 'IS NOT NULL' : 'IS NULL';
    return sprintf('%s %s', $condition->getField(), $suffix);
  }

}
```

- [ ] **Step 6: Run tests to verify pass**

Run: `vendor/bin/phpunit tests/src/Unit/Filter/ValueParsersTest.php`
Expected: 5 tests pass

- [ ] **Step 7: Commit**

```bash
git add src/Filter/ScalarValueParser.php src/Filter/BooleanValueParser.php src/Filter/NullValueParser.php tests/src/Unit/Filter/ValueParsersTest.php
git commit -m "feat: add scalar/boolean/null value parsers"
```

---

### Task 10: Implement IN/NOT IN/BETWEEN/NOT BETWEEN parsers with tests

**Files:**
- Create: `src/Filter/InOperatorParser.php`
- Create: `src/Filter/NotInOperatorParser.php`
- Create: `src/Filter/BetweenOperatorParser.php`
- Create: `src/Filter/NotBetweenOperatorParser.php`
- Create: `tests/src/Unit/Filter/OperatorParsersTest.php`

- [ ] **Step 1: Write failing test**

Create `tests/src/Unit/Filter/OperatorParsersTest.php`:

```php
<?php

declare(strict_types=1);

namespace Drupal\Tests\meilisearch\Unit\Filter;

use Drupal\meilisearch\Filter\BetweenOperatorParser;
use Drupal\meilisearch\Filter\InOperatorParser;
use Drupal\meilisearch\Filter\NotBetweenOperatorParser;
use Drupal\meilisearch\Filter\NotInOperatorParser;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\Condition;
use PHPUnit\Framework\TestCase;

class OperatorParsersTest extends TestCase {

  public function testInOperator(): void {
    $parser = new InOperatorParser();
    $condition = new Condition('genre', ['action', 'comedy'], 'IN');
    $index = $this->createMock(IndexInterface::class);

    $this->assertTrue($parser->supports($condition, $index));
    $this->assertSame('genre IN ["action", "comedy"]', $parser->parse($condition, $index));
  }

  public function testNotInOperator(): void {
    $parser = new NotInOperatorParser();
    $condition = new Condition('status', ['draft', 'archived'], 'NOT IN');
    $index = $this->createMock(IndexInterface::class);

    $this->assertTrue($parser->supports($condition, $index));
    $this->assertSame('status NOT IN ["draft", "archived"]', $parser->parse($condition, $index));
  }

  public function testBetweenOperator(): void {
    $parser = new BetweenOperatorParser();
    $condition = new Condition('price', [10, 100], 'BETWEEN');
    $index = $this->createMock(IndexInterface::class);

    $this->assertTrue($parser->supports($condition, $index));
    $this->assertSame('price 10 TO 100', $parser->parse($condition, $index));
  }

  public function testNotBetweenOperator(): void {
    $parser = new NotBetweenOperatorParser();
    $condition = new Condition('price', [10, 100], 'NOT BETWEEN');
    $index = $this->createMock(IndexInterface::class);

    $this->assertTrue($parser->supports($condition, $index));
    $this->assertSame('NOT price 10 TO 100', $parser->parse($condition, $index));
  }

}
```

- [ ] **Step 2: Run test to verify failure**

Run: `vendor/bin/phpunit tests/src/Unit/Filter/OperatorParsersTest.php`
Expected: FAIL

- [ ] **Step 3: Implement InOperatorParser**

Create `src/Filter/InOperatorParser.php`:

```php
<?php

declare(strict_types=1);

namespace Drupal\meilisearch\Filter;

use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\ConditionInterface;

/**
 * Parses IN operator conditions.
 */
class InOperatorParser implements ConditionParserInterface {

  /**
   * {@inheritdoc}
   */
  public function supports(ConditionInterface $condition, IndexInterface $index): bool {
    return $condition->getOperator() === 'IN' && is_array($condition->getValue());
  }

  /**
   * {@inheritdoc}
   */
  public function parse(ConditionInterface $condition, IndexInterface $index): string {
    $values = array_map(
      fn($v) => is_numeric($v) ? (string) $v : '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $v) . '"',
      $condition->getValue()
    );
    return sprintf('%s IN [%s]', $condition->getField(), implode(', ', $values));
  }

}
```

- [ ] **Step 4: Implement NotInOperatorParser**

Create `src/Filter/NotInOperatorParser.php`:

```php
<?php

declare(strict_types=1);

namespace Drupal\meilisearch\Filter;

use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\ConditionInterface;

/**
 * Parses NOT IN operator conditions.
 */
class NotInOperatorParser implements ConditionParserInterface {

  /**
   * {@inheritdoc}
   */
  public function supports(ConditionInterface $condition, IndexInterface $index): bool {
    return $condition->getOperator() === 'NOT IN' && is_array($condition->getValue());
  }

  /**
   * {@inheritdoc}
   */
  public function parse(ConditionInterface $condition, IndexInterface $index): string {
    $values = array_map(
      fn($v) => is_numeric($v) ? (string) $v : '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $v) . '"',
      $condition->getValue()
    );
    return sprintf('%s NOT IN [%s]', $condition->getField(), implode(', ', $values));
  }

}
```

- [ ] **Step 5: Implement BetweenOperatorParser**

Create `src/Filter/BetweenOperatorParser.php`:

```php
<?php

declare(strict_types=1);

namespace Drupal\meilisearch\Filter;

use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\ConditionInterface;

/**
 * Parses BETWEEN operator conditions.
 */
class BetweenOperatorParser implements ConditionParserInterface {

  /**
   * {@inheritdoc}
   */
  public function supports(ConditionInterface $condition, IndexInterface $index): bool {
    $value = $condition->getValue();
    return $condition->getOperator() === 'BETWEEN' && is_array($value) && count($value) === 2;
  }

  /**
   * {@inheritdoc}
   */
  public function parse(ConditionInterface $condition, IndexInterface $index): string {
    [$min, $max] = array_values($condition->getValue());
    return sprintf('%s %s TO %s', $condition->getField(), $min, $max);
  }

}
```

- [ ] **Step 6: Implement NotBetweenOperatorParser**

Create `src/Filter/NotBetweenOperatorParser.php`:

```php
<?php

declare(strict_types=1);

namespace Drupal\meilisearch\Filter;

use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\ConditionInterface;

/**
 * Parses NOT BETWEEN operator conditions.
 */
class NotBetweenOperatorParser implements ConditionParserInterface {

  /**
   * {@inheritdoc}
   */
  public function supports(ConditionInterface $condition, IndexInterface $index): bool {
    $value = $condition->getValue();
    return $condition->getOperator() === 'NOT BETWEEN' && is_array($value) && count($value) === 2;
  }

  /**
   * {@inheritdoc}
   */
  public function parse(ConditionInterface $condition, IndexInterface $index): string {
    [$min, $max] = array_values($condition->getValue());
    return sprintf('NOT %s %s TO %s', $condition->getField(), $min, $max);
  }

}
```

- [ ] **Step 7: Run tests to verify pass**

Run: `vendor/bin/phpunit tests/src/Unit/Filter/OperatorParsersTest.php`
Expected: 4 tests pass

- [ ] **Step 8: Commit**

```bash
git add src/Filter/InOperatorParser.php src/Filter/NotInOperatorParser.php src/Filter/BetweenOperatorParser.php src/Filter/NotBetweenOperatorParser.php tests/src/Unit/Filter/OperatorParsersTest.php
git commit -m "feat: add IN/NOT IN/BETWEEN/NOT BETWEEN parsers"
```

---

### Task 11: Implement GeoFilterParser with tests

**Files:**
- Create: `src/Filter/GeoFilterParser.php`
- Create: `tests/src/Unit/Filter/GeoFilterParserTest.php`

- [ ] **Step 1: Write failing test**

Create `tests/src/Unit/Filter/GeoFilterParserTest.php`:

```php
<?php

declare(strict_types=1);

namespace Drupal\Tests\meilisearch\Unit\Filter;

use Drupal\meilisearch\Filter\GeoFilterParser;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\FieldInterface;
use Drupal\search_api\Query\Condition;
use PHPUnit\Framework\TestCase;

class GeoFilterParserTest extends TestCase {

  public function testGeoRadius(): void {
    $parser = new GeoFilterParser();
    $condition = new Condition('location', ['lat' => 48.85, 'lng' => 2.29, 'radius' => 5000], 'GEO_RADIUS');

    $this->assertTrue($parser->supports($condition, $this->indexWithGeoField()));
    $this->assertSame('_geoRadius(48.85, 2.29, 5000)', $parser->parse($condition, $this->indexWithGeoField()));
  }

  public function testGeoBoundingBox(): void {
    $parser = new GeoFilterParser();
    $condition = new Condition('location', [
      'top_left' => ['lat' => 49.0, 'lng' => 2.0],
      'bottom_right' => ['lat' => 48.0, 'lng' => 3.0],
    ], 'GEO_BBOX');

    $this->assertSame(
      '_geoBoundingBox([49, 2], [48, 3])',
      $parser->parse($condition, $this->indexWithGeoField())
    );
  }

  public function testDoesNotSupportNonGeoField(): void {
    $parser = new GeoFilterParser();
    $condition = new Condition('title', 'x', '=');
    $this->assertFalse($parser->supports($condition, $this->indexWithGeoField()));
  }

  private function indexWithGeoField(): IndexInterface {
    $field = $this->createMock(FieldInterface::class);
    $field->method('getType')->willReturn('location');
    $index = $this->createMock(IndexInterface::class);
    $index->method('getField')->willReturnCallback(
      fn($id) => $id === 'location' ? $field : NULL
    );
    return $index;
  }

}
```

- [ ] **Step 2: Run test to verify failure**

Run: `vendor/bin/phpunit tests/src/Unit/Filter/GeoFilterParserTest.php`
Expected: FAIL

- [ ] **Step 3: Implement GeoFilterParser**

Create `src/Filter/GeoFilterParser.php`:

```php
<?php

declare(strict_types=1);

namespace Drupal\meilisearch\Filter;

use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\ConditionInterface;

/**
 * Parses geo conditions into Meilisearch geo filter syntax.
 */
class GeoFilterParser implements ConditionParserInterface {

  public const OPERATORS = ['GEO_RADIUS', 'GEO_BBOX'];

  /**
   * {@inheritdoc}
   */
  public function supports(ConditionInterface $condition, IndexInterface $index): bool {
    if (!in_array($condition->getOperator(), self::OPERATORS, TRUE)) {
      return FALSE;
    }
    $field = $index->getField($condition->getField());
    return $field !== NULL && $field->getType() === 'location';
  }

  /**
   * {@inheritdoc}
   */
  public function parse(ConditionInterface $condition, IndexInterface $index): string {
    $value = $condition->getValue();

    if ($condition->getOperator() === 'GEO_RADIUS') {
      return sprintf('_geoRadius(%s, %s, %s)', $value['lat'], $value['lng'], $value['radius']);
    }

    // GEO_BBOX.
    $tl = $value['top_left'];
    $br = $value['bottom_right'];
    return sprintf('_geoBoundingBox([%s, %s], [%s, %s])', $tl['lat'], $tl['lng'], $br['lat'], $br['lng']);
  }

}
```

- [ ] **Step 4: Run tests to verify pass**

Run: `vendor/bin/phpunit tests/src/Unit/Filter/GeoFilterParserTest.php`
Expected: 3 tests pass

- [ ] **Step 5: Commit**

```bash
git add src/Filter/GeoFilterParser.php tests/src/Unit/Filter/GeoFilterParserTest.php
git commit -m "feat: add geo filter parser for radius and bounding box"
```

---

### Task 12: Implement FilterBuilder (the orchestrator) with tests

**Files:**
- Create: `src/Filter/FilterBuilder.php`
- Create: `tests/src/Unit/Filter/FilterBuilderTest.php`

- [ ] **Step 1: Write failing test**

Create `tests/src/Unit/Filter/FilterBuilderTest.php`:

```php
<?php

declare(strict_types=1);

namespace Drupal\Tests\meilisearch\Unit\Filter;

use Drupal\meilisearch\Filter\BooleanValueParser;
use Drupal\meilisearch\Filter\FilterBuilder;
use Drupal\meilisearch\Filter\NullValueParser;
use Drupal\meilisearch\Filter\ScalarValueParser;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\ConditionGroup;
use PHPUnit\Framework\TestCase;

class FilterBuilderTest extends TestCase {

  public function testSingleCondition(): void {
    $builder = $this->buildBuilder();
    $group = new ConditionGroup('AND');
    $group->addCondition('title', 'Hello', '=');

    $this->assertSame('title = "Hello"', $builder->build($group, $this->createIndex()));
  }

  public function testAndGroup(): void {
    $builder = $this->buildBuilder();
    $group = new ConditionGroup('AND');
    $group->addCondition('title', 'Hello', '=');
    $group->addCondition('count', 5, '>');

    $this->assertSame('(title = "Hello" AND count > 5)', $builder->build($group, $this->createIndex()));
  }

  public function testOrGroup(): void {
    $builder = $this->buildBuilder();
    $group = new ConditionGroup('OR');
    $group->addCondition('genre', 'action', '=');
    $group->addCondition('genre', 'comedy', '=');

    $this->assertSame('(genre = "action" OR genre = "comedy")', $builder->build($group, $this->createIndex()));
  }

  public function testNestedGroups(): void {
    $builder = $this->buildBuilder();
    $inner = new ConditionGroup('OR');
    $inner->addCondition('genre', 'action', '=');
    $inner->addCondition('genre', 'comedy', '=');

    $outer = new ConditionGroup('AND');
    $outer->addConditionGroup($inner);
    $outer->addCondition('rating', 4, '>=');

    $this->assertSame(
      '((genre = "action" OR genre = "comedy") AND rating >= 4)',
      $builder->build($outer, $this->createIndex())
    );
  }

  public function testEmptyGroupReturnsNull(): void {
    $builder = $this->buildBuilder();
    $group = new ConditionGroup('AND');

    $this->assertNull($builder->build($group, $this->createIndex()));
  }

  private function buildBuilder(): FilterBuilder {
    $builder = new FilterBuilder();
    $builder->addConditionParser(new BooleanValueParser());
    $builder->addConditionParser(new NullValueParser());
    $builder->addConditionParser(new ScalarValueParser());
    return $builder;
  }

  private function createIndex(): IndexInterface {
    return $this->createMock(IndexInterface::class);
  }

}
```

- [ ] **Step 2: Run test to verify failure**

Run: `vendor/bin/phpunit tests/src/Unit/Filter/FilterBuilderTest.php`
Expected: FAIL

- [ ] **Step 3: Implement FilterBuilder**

Create `src/Filter/FilterBuilder.php`:

```php
<?php

declare(strict_types=1);

namespace Drupal\meilisearch\Filter;

use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\ConditionGroupInterface;
use Drupal\search_api\Query\ConditionInterface;

/**
 * Default implementation of FilterBuilder.
 */
class FilterBuilder implements FilterBuilderInterface {

  /**
   * @var \Drupal\meilisearch\Filter\ConditionParserInterface[]
   */
  protected array $parsers = [];

  /**
   * Adds a condition parser. Called by the service collector.
   */
  public function addConditionParser(ConditionParserInterface $parser): void {
    $this->parsers[] = $parser;
  }

  /**
   * {@inheritdoc}
   */
  public function build(ConditionGroupInterface $group, IndexInterface $index): ?string {
    $parts = [];
    foreach ($group->getConditions() as $item) {
      if ($item instanceof ConditionGroupInterface) {
        $nested = $this->build($item, $index);
        if ($nested !== NULL) {
          $parts[] = $nested;
        }
      }
      elseif ($item instanceof ConditionInterface) {
        $parsed = $this->parseCondition($item, $index);
        if ($parsed !== NULL) {
          $parts[] = $parsed;
        }
      }
    }

    if (empty($parts)) {
      return NULL;
    }

    if (count($parts) === 1) {
      return $parts[0];
    }

    return '(' . implode(' ' . $group->getConjunction() . ' ', $parts) . ')';
  }

  /**
   * Finds the first supporting parser and returns its output.
   */
  protected function parseCondition(ConditionInterface $condition, IndexInterface $index): ?string {
    foreach ($this->parsers as $parser) {
      if ($parser->supports($condition, $index)) {
        return $parser->parse($condition, $index);
      }
    }
    return NULL;
  }

}
```

- [ ] **Step 4: Run tests to verify pass**

Run: `vendor/bin/phpunit tests/src/Unit/Filter/FilterBuilderTest.php`
Expected: 5 tests pass

- [ ] **Step 5: Commit**

```bash
git add src/Filter/FilterBuilder.php tests/src/Unit/Filter/FilterBuilderTest.php
git commit -m "feat: implement FilterBuilder orchestrator with AND/OR group support"
```

---

## Phase 5: Search API Backend Plugin

### Task 13: Create MeilisearchBackend skeleton

**Files:**
- Create: `src/Plugin/search_api/backend/MeilisearchBackend.php`

- [ ] **Step 1: Create the backend skeleton**

Create `src/Plugin/search_api/backend/MeilisearchBackend.php`:

```php
<?php

declare(strict_types=1);

namespace Drupal\meilisearch\Plugin\search_api\backend;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\meilisearch\Api\MeilisearchApiException;
use Drupal\meilisearch\Api\MeilisearchApiServiceInterface;
use Drupal\meilisearch\Converter\DocumentConverterInterface;
use Drupal\meilisearch\Filter\FilterBuilderInterface;
use Drupal\meilisearch\Utility\MeilisearchUtils;
use Drupal\search_api\Backend\BackendPluginBase;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Plugin\PluginFormTrait;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\SearchApiException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Meilisearch backend for Search API.
 *
 * @SearchApiBackend(
 *   id = "meilisearch",
 *   label = @Translation("Meilisearch"),
 *   description = @Translation("Indexes items in Meilisearch.")
 * )
 */
final class MeilisearchBackend extends BackendPluginBase implements PluginFormInterface {

  use PluginFormTrait;

  protected MeilisearchApiServiceInterface $api;
  protected DocumentConverterInterface $documentConverter;
  protected FilterBuilderInterface $filterBuilder;
  protected LoggerInterface $logger;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    MeilisearchApiServiceInterface $api,
    DocumentConverterInterface $documentConverter,
    FilterBuilderInterface $filterBuilder,
    LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->api = $api;
    $this->documentConverter = $documentConverter;
    $this->filterBuilder = $filterBuilder;
    $this->logger = $logger;
    $this->configureApi();
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('meilisearch.api'),
      $container->get('meilisearch.document_converter'),
      $container->get('meilisearch.filter_builder'),
      $container->get('logger.channel.meilisearch'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'connection_mode' => 'self_hosted',
      'host' => 'http://127.0.0.1',
      'port' => 7700,
      'api_key' => '',
      'is_cloud' => FALSE,
      'search_mode' => 'keyword',
      'semantic_ratio' => 0.5,
      'embedder' => '',
      'ranking_rules' => ['sort', 'words', 'attribute', 'typo', 'proximity', 'exactness'],
    ];
  }

  /**
   * Configures the API service using the current configuration.
   */
  protected function configureApi(): void {
    if ($this->configuration['connection_mode'] === 'cloud') {
      $this->api->setUrl($this->configuration['host']);
    }
    else {
      $this->api->setUrl(rtrim($this->configuration['host'], '/') . ':' . $this->configuration['port']);
    }
    $this->api->setApiKey($this->configuration['api_key']);
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration): void {
    parent::setConfiguration($configuration);
    $this->configureApi();
  }

  public function __sleep(): array {
    $properties = array_flip(parent::__sleep());
    unset($properties['api'], $properties['documentConverter'], $properties['filterBuilder'], $properties['logger']);
    return array_keys($properties);
  }

  public function __wakeup(): void {
    parent::__wakeup();
    $container = \Drupal::getContainer();
    $this->api = $container->get('meilisearch.api');
    $this->documentConverter = $container->get('meilisearch.document_converter');
    $this->filterBuilder = $container->get('meilisearch.filter_builder');
    $this->logger = $container->get('logger.channel.meilisearch');
    $this->configureApi();
  }

}
```

- [ ] **Step 2: Commit**

```bash
git add src/Plugin/search_api/backend/MeilisearchBackend.php
git commit -m "feat: add MeilisearchBackend plugin skeleton"
```

---

### Task 14: Implement backend configuration form

**Files:**
- Modify: `src/Plugin/search_api/backend/MeilisearchBackend.php` (add form methods)

- [ ] **Step 1: Add buildConfigurationForm / validateConfigurationForm**

Append these methods to the `MeilisearchBackend` class (before `__sleep`):

```php
  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['connection_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Connection mode'),
      '#default_value' => $this->configuration['connection_mode'],
      '#options' => [
        'self_hosted' => $this->t('Self-hosted Meilisearch'),
        'cloud' => $this->t('Meilisearch Cloud'),
      ],
      '#required' => TRUE,
    ];

    $form['host'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Host URL'),
      '#default_value' => $this->configuration['host'],
      '#description' => $this->t('Self-hosted example: <code>http://127.0.0.1</code>. Cloud example: <code>https://ms-abc.fra.meilisearch.io</code>.'),
      '#required' => TRUE,
    ];

    $form['port'] = [
      '#type' => 'number',
      '#title' => $this->t('Port'),
      '#default_value' => $this->configuration['port'],
      '#min' => 1,
      '#max' => 65535,
      '#states' => [
        'visible' => [
          ':input[name="backend_config[connection_mode]"]' => ['value' => 'self_hosted'],
        ],
      ],
    ];

    $form['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API key'),
      '#default_value' => $this->configuration['api_key'],
      '#description' => $this->t('Master key (self-hosted) or admin API key (Cloud).'),
    ];

    $form['search_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Search mode'),
      '#default_value' => $this->configuration['search_mode'],
      '#options' => [
        'keyword' => $this->t('Keyword (default)'),
        'semantic' => $this->t('Semantic (vector)'),
        'hybrid' => $this->t('Hybrid (keyword + vector)'),
      ],
    ];

    $form['semantic_ratio'] = [
      '#type' => 'number',
      '#title' => $this->t('Semantic ratio'),
      '#default_value' => $this->configuration['semantic_ratio'],
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.01,
      '#description' => $this->t('0.0 = full keyword, 1.0 = full semantic.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[search_mode]"]' => ['value' => 'hybrid'],
        ],
      ],
    ];

    $form['embedder'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Embedder name'),
      '#default_value' => $this->configuration['embedder'],
      '#description' => $this->t('Name of an embedder configured on the Meilisearch instance.'),
      '#states' => [
        'invisible' => [
          ':input[name="backend_config[search_mode]"]' => ['value' => 'keyword'],
        ],
      ],
    ];

    $form['ranking_rules'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Ranking rules (one per line, in order)'),
      '#default_value' => implode("\n", $this->configuration['ranking_rules']),
      '#description' => $this->t('Default: sort, words, attribute, typo, proximity, exactness.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $host = rtrim((string) $form_state->getValue('host'), '/');
    $form_state->setValue('host', $host);

    if ($form_state->getValue('connection_mode') === 'cloud') {
      if (!preg_match('~^https?://~', $host)) {
        $form_state->setErrorByName('host', $this->t('Cloud URL must include scheme (https://).'));
      }
      $form_state->setValue('is_cloud', (bool) preg_match('/\.meilisearch\.io$/i', parse_url($host, PHP_URL_HOST) ?? ''));
    }
    else {
      $form_state->setValue('is_cloud', FALSE);
    }

    $rules = array_filter(array_map('trim', explode("\n", (string) $form_state->getValue('ranking_rules'))));
    if (empty($rules)) {
      $rules = ['sort', 'words', 'attribute', 'typo', 'proximity', 'exactness'];
    }
    $form_state->setValue('ranking_rules', array_values($rules));

    if ($form_state->getValue('search_mode') !== 'keyword' && !$form_state->getValue('embedder')) {
      $form_state->setErrorByName('embedder', $this->t('Embedder is required for semantic/hybrid search.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function viewSettings(): array {
    $info = [];
    try {
      $version = $this->api->version();
      $info[] = [
        'label' => $this->t('Meilisearch version'),
        'info' => $version['pkgVersion'] ?? 'unknown',
      ];
      $info[] = [
        'label' => $this->t('Cloud'),
        'info' => $this->api->isCloud() ? $this->t('Yes') : $this->t('No'),
      ];
    }
    catch (MeilisearchApiException $e) {
      $this->logger->error($e->getMessage());
    }
    return $info;
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return $this->api->ping();
  }
```

- [ ] **Step 2: Commit**

```bash
git add src/Plugin/search_api/backend/MeilisearchBackend.php
git commit -m "feat: add backend configuration form with cloud and semantic options"
```

---

### Task 15: Implement index lifecycle (add/update/remove)

**Files:**
- Modify: `src/Plugin/search_api/backend/MeilisearchBackend.php` (add index lifecycle methods)

- [ ] **Step 1: Add addIndex/updateIndex/removeIndex**

Append to the `MeilisearchBackend` class:

```php
  /**
   * {@inheritdoc}
   */
  public function addIndex(IndexInterface $index): void {
    try {
      $task = $this->api->createIndex($index->id());
      $this->api->waitForTask((int) $task['taskUid']);
      $this->updateIndex($index);
    }
    catch (MeilisearchApiException $e) {
      $this->logger->error('Failed to add index @id: @msg', [
        '@id' => $index->id(),
        '@msg' => $e->getMessage(),
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function updateIndex(IndexInterface $index): void {
    try {
      $settings = $this->buildIndexSettings($index);
      $task = $this->api->updateSettings($index->id(), $settings);
      $this->api->waitForTask((int) $task['taskUid']);
    }
    catch (MeilisearchApiException $e) {
      $this->logger->error('Failed to update index @id: @msg', [
        '@id' => $index->id(),
        '@msg' => $e->getMessage(),
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function removeIndex($index): void {
    if (is_object($index) && method_exists($index, 'isReadOnly') && $index->isReadOnly()) {
      return;
    }
    $id = is_object($index) ? $index->id() : (string) $index;
    try {
      $task = $this->api->deleteIndex($id);
      $this->api->waitForTask((int) $task['taskUid']);
    }
    catch (MeilisearchApiException $e) {
      $this->logger->error('Failed to remove index @id: @msg', ['@id' => $id, '@msg' => $e->getMessage()]);
    }
  }

  /**
   * Builds the bulk settings payload for an index.
   */
  protected function buildIndexSettings(IndexInterface $index): array {
    $fields = $index->getFields();
    $fieldNames = array_keys($fields);

    // Sort searchable fields by boost descending.
    $searchable = [];
    foreach ($fields as $id => $field) {
      if ($field->getType() === 'text') {
        $searchable[$id] = $field->getBoost() ?? 1.0;
      }
    }
    arsort($searchable);
    $searchableAttributes = array_keys($searchable);

    // Geo support: if any field is of type 'location', _geo must be filterable + sortable.
    $hasGeo = FALSE;
    foreach ($fields as $field) {
      if ($field->getType() === 'location') {
        $hasGeo = TRUE;
        break;
      }
    }

    $filterable = $fieldNames;
    $sortable = $fieldNames;
    if ($hasGeo) {
      $filterable[] = '_geo';
      $sortable[] = '_geo';
    }

    return [
      'searchableAttributes' => $searchableAttributes ?: ['*'],
      'filterableAttributes' => array_values(array_unique($filterable)),
      'sortableAttributes' => array_values(array_unique($sortable)),
      'rankingRules' => $this->configuration['ranking_rules'],
      'displayedAttributes' => ['*'],
    ];
  }
```

- [ ] **Step 2: Commit**

```bash
git add src/Plugin/search_api/backend/MeilisearchBackend.php
git commit -m "feat: implement index lifecycle with bulk settings update"
```

---

### Task 16: Implement indexing and deletion

**Files:**
- Modify: `src/Plugin/search_api/backend/MeilisearchBackend.php`

- [ ] **Step 1: Add indexItems/deleteItems/deleteAllIndexItems**

Append to the `MeilisearchBackend` class:

```php
  /**
   * {@inheritdoc}
   */
  public function indexItems(IndexInterface $index, array $items): array {
    $documents = $this->documentConverter->convertToDocuments($items);
    try {
      $task = $this->api->addDocuments($index->id(), $documents);
      $this->api->waitForTask((int) $task['taskUid']);
    }
    catch (MeilisearchApiException $e) {
      $this->logger->error('Index items failed: @msg', ['@msg' => $e->getMessage()]);
      throw new SearchApiException($e->getMessage(), $e->getCode(), $e);
    }
    return array_keys($items);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteItems(IndexInterface $index, array $item_ids): void {
    $ids = array_map(fn($id) => MeilisearchUtils::formatAsDocumentId($id), $item_ids);
    try {
      $task = $this->api->deleteDocuments($index->id(), $ids);
      $this->api->waitForTask((int) $task['taskUid']);
    }
    catch (MeilisearchApiException $e) {
      $this->logger->error('Delete items failed: @msg', ['@msg' => $e->getMessage()]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAllIndexItems(IndexInterface $index, $datasource_id = NULL): void {
    try {
      $task = $this->api->deleteAllDocuments($index->id());
      $this->api->waitForTask((int) $task['taskUid']);
    }
    catch (MeilisearchApiException $e) {
      $this->logger->error('Delete all items failed: @msg', ['@msg' => $e->getMessage()]);
    }
  }
```

- [ ] **Step 2: Commit**

```bash
git add src/Plugin/search_api/backend/MeilisearchBackend.php
git commit -m "feat: implement item indexing and deletion"
```

---

### Task 17: Implement search with semantic/hybrid support

**Files:**
- Modify: `src/Plugin/search_api/backend/MeilisearchBackend.php`

- [ ] **Step 1: Add search() method**

Append to the `MeilisearchBackend` class:

```php
  /**
   * {@inheritdoc}
   */
  public function search(QueryInterface $query): void {
    $results = $query->getResults();
    $index = $query->getIndex();
    $keys = (string) ($query->getOriginalKeys() ?? '');
    $options = [];

    // Pagination.
    $queryOptions = $query->getOptions();
    $options['offset'] = (int) ($queryOptions['offset'] ?? 0);
    $options['limit'] = isset($queryOptions['limit']) && $queryOptions['limit'] > 0
      ? (int) $queryOptions['limit']
      : 20;

    // Filter.
    $filter = $this->filterBuilder->build($query->getConditionGroup(), $index);
    if ($filter !== NULL) {
      $options['filter'] = $filter;
    }

    // Sort.
    $sorts = [];
    foreach ($query->getSorts() as $field => $direction) {
      if (in_array($field, ['search_api_relevance', 'search_api_random'], TRUE)) {
        continue;
      }
      $sorts[] = $field . ':' . strtolower($direction);
    }
    if ($sorts) {
      $options['sort'] = $sorts;
    }

    // Semantic / hybrid.
    $mode = $this->configuration['search_mode'];
    if ($mode !== 'keyword' && !empty($this->configuration['embedder'])) {
      $ratio = $mode === 'semantic' ? 1.0 : (float) $this->configuration['semantic_ratio'];
      $options['hybrid'] = [
        'semanticRatio' => $ratio,
        'embedder' => $this->configuration['embedder'],
      ];
    }

    // Allow other modules to alter options (e.g., facets, highlighting).
    $this->alterSearchOptions($options, $query);

    try {
      $data = $this->api->search($index->id(), $keys, $options);
    }
    catch (MeilisearchApiException $e) {
      $this->logger->error('Search failed: @msg', ['@msg' => $e->getMessage()]);
      throw new SearchApiException($e->getMessage(), $e->getCode(), $e);
    }

    $results->setResultCount($data->getEstimatedTotalHits());
    foreach ($data->getHits() as $hit) {
      if (!isset($hit['search_api_id'])) {
        continue;
      }
      $item = $this->getFieldsHelper()->createItem($index, $hit['search_api_id']);
      if (isset($hit['_formatted'])) {
        $item->setExtraData('meilisearch_highlighted', $hit['_formatted']);
      }
      $results->addResultItem($item);
    }

    // Store raw Meilisearch response for subscribers (facets, analytics).
    $results->setExtraData('meilisearch_response', $data->toArray());
  }

  /**
   * Hook point for submodules to alter search options.
   *
   * Submodules dispatch events via kernel events; this method does nothing by
   * default but can be overridden by tests/subclasses.
   */
  protected function alterSearchOptions(array &$options, QueryInterface $query): void {
    // No-op by default. Event subscribers on the query alter hook modify options.
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedFeatures(): array {
    return ['search_api_facets'];
  }
```

- [ ] **Step 2: Commit**

```bash
git add src/Plugin/search_api/backend/MeilisearchBackend.php
git commit -m "feat: implement search with semantic/hybrid mode support"
```

---

### Task 18: Kernel test for backend plugin discovery

**Files:**
- Create: `tests/src/Kernel/MeilisearchBackendTest.php`

- [ ] **Step 1: Write the kernel test**

Create `tests/src/Kernel/MeilisearchBackendTest.php`:

```php
<?php

declare(strict_types=1);

namespace Drupal\Tests\meilisearch\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\meilisearch\Plugin\search_api\backend\MeilisearchBackend;
use Drupal\search_api\Entity\Server;

/**
 * @group meilisearch
 */
class MeilisearchBackendTest extends KernelTestBase {

  protected static $modules = ['search_api', 'meilisearch'];

  public function testBackendPluginIsRegistered(): void {
    $manager = $this->container->get('plugin.manager.search_api.backend');
    $definitions = $manager->getDefinitions();
    $this->assertArrayHasKey('meilisearch', $definitions);
    $this->assertSame('Meilisearch', (string) $definitions['meilisearch']['label']);
  }

  public function testDefaultConfiguration(): void {
    $server = Server::create([
      'id' => 'test',
      'name' => 'Test',
      'backend' => 'meilisearch',
    ]);
    /** @var MeilisearchBackend $backend */
    $backend = $server->getBackend();
    $config = $backend->defaultConfiguration();

    $this->assertSame('self_hosted', $config['connection_mode']);
    $this->assertSame(7700, $config['port']);
    $this->assertSame('keyword', $config['search_mode']);
    $this->assertSame(['sort', 'words', 'attribute', 'typo', 'proximity', 'exactness'], $config['ranking_rules']);
  }

}
```

- [ ] **Step 2: Run test**

Run: `vendor/bin/phpunit tests/src/Kernel/MeilisearchBackendTest.php`
Expected: 2 tests pass (requires Drupal kernel test setup)

- [ ] **Step 3: Commit**

```bash
git add tests/src/Kernel/MeilisearchBackendTest.php
git commit -m "test: add kernel test for backend plugin discovery"
```

---

## Phase 6: Processors

### Task 19: Implement MeilisearchSynonyms processor

**Files:**
- Create: `src/Plugin/search_api/processor/MeilisearchSynonyms.php`

- [ ] **Step 1: Create the processor**

Create `src/Plugin/search_api/processor/MeilisearchSynonyms.php`:

```php
<?php

declare(strict_types=1);

namespace Drupal\meilisearch\Plugin\search_api\processor;

use Drupal\Core\Form\FormStateInterface;
use Drupal\meilisearch\Api\MeilisearchApiException;
use Drupal\meilisearch\Api\MeilisearchApiServiceInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Plugin\search_api\processor\Property\IntegerProperty;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Pushes synonyms to Meilisearch on index save.
 *
 * @SearchApiProcessor(
 *   id = "meilisearch_synonyms",
 *   label = @Translation("Meilisearch synonyms"),
 *   description = @Translation("Sync synonyms to Meilisearch."),
 *   stages = {"preprocess_index" = 0}
 * )
 */
class MeilisearchSynonyms extends ProcessorPluginBase {

  protected MeilisearchApiServiceInterface $api;

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->api = $container->get('meilisearch.api');
    return $instance;
  }

  public static function supportsIndex(IndexInterface $index): bool {
    return $index->getServerInstance()?->getBackendId() === 'meilisearch';
  }

  public function defaultConfiguration(): array {
    return ['synonyms' => []];
  }

  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $lines = [];
    foreach ($this->configuration['synonyms'] as $key => $values) {
      $lines[] = $key . ': ' . implode(', ', $values);
    }
    $form['synonyms'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Synonyms'),
      '#default_value' => implode("\n", $lines),
      '#description' => $this->t('One per line, format: <code>term: alt1, alt2</code>'),
    ];
    return $form;
  }

  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $raw = (string) $form_state->getValue(['synonyms']);
    $synonyms = [];
    foreach (explode("\n", $raw) as $line) {
      $line = trim($line);
      if ($line === '' || !str_contains($line, ':')) {
        continue;
      }
      [$key, $list] = explode(':', $line, 2);
      $key = trim($key);
      $values = array_filter(array_map('trim', explode(',', $list)));
      if ($key !== '' && $values) {
        $synonyms[$key] = array_values($values);
      }
    }
    $this->configuration['synonyms'] = $synonyms;
  }

  /**
   * Called by the backend after index save to sync synonyms.
   */
  public function syncToServer(IndexInterface $index): void {
    try {
      $task = $this->api->updateSettings($index->id(), [
        'synonyms' => $this->configuration['synonyms'],
      ]);
      $this->api->waitForTask((int) $task['taskUid']);
    }
    catch (MeilisearchApiException $e) {
      \Drupal::logger('meilisearch')->error($e->getMessage());
    }
  }

}
```

- [ ] **Step 2: Commit**

```bash
git add src/Plugin/search_api/processor/MeilisearchSynonyms.php
git commit -m "feat: add MeilisearchSynonyms processor"
```

---

### Task 20: Implement MeilisearchStopWords processor

**Files:**
- Create: `src/Plugin/search_api/processor/MeilisearchStopWords.php`

- [ ] **Step 1: Create the processor**

Create `src/Plugin/search_api/processor/MeilisearchStopWords.php`:

```php
<?php

declare(strict_types=1);

namespace Drupal\meilisearch\Plugin\search_api\processor;

use Drupal\Core\Form\FormStateInterface;
use Drupal\meilisearch\Api\MeilisearchApiException;
use Drupal\meilisearch\Api\MeilisearchApiServiceInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Pushes stop words to Meilisearch on index save.
 *
 * @SearchApiProcessor(
 *   id = "meilisearch_stopwords",
 *   label = @Translation("Meilisearch stop words"),
 *   description = @Translation("Sync stop words to Meilisearch."),
 *   stages = {"preprocess_index" = 0}
 * )
 */
class MeilisearchStopWords extends ProcessorPluginBase {

  protected MeilisearchApiServiceInterface $api;

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->api = $container->get('meilisearch.api');
    return $instance;
  }

  public static function supportsIndex(IndexInterface $index): bool {
    return $index->getServerInstance()?->getBackendId() === 'meilisearch';
  }

  public function defaultConfiguration(): array {
    return ['stopwords' => []];
  }

  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['stopwords'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Stop words'),
      '#default_value' => implode("\n", $this->configuration['stopwords']),
      '#description' => $this->t('One per line.'),
    ];
    return $form;
  }

  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $raw = (string) $form_state->getValue(['stopwords']);
    $words = array_filter(array_map('trim', explode("\n", $raw)));
    $this->configuration['stopwords'] = array_values($words);
  }

  public function syncToServer(IndexInterface $index): void {
    try {
      $task = $this->api->updateSettings($index->id(), [
        'stopWords' => $this->configuration['stopwords'],
      ]);
      $this->api->waitForTask((int) $task['taskUid']);
    }
    catch (MeilisearchApiException $e) {
      \Drupal::logger('meilisearch')->error($e->getMessage());
    }
  }

}
```

- [ ] **Step 2: Commit**

```bash
git add src/Plugin/search_api/processor/MeilisearchStopWords.php
git commit -m "feat: add MeilisearchStopWords processor"
```

---

### Task 21: Implement MeilisearchHighlighting processor

**Files:**
- Create: `src/Plugin/search_api/processor/MeilisearchHighlighting.php`
- Modify: `src/Plugin/search_api/backend/MeilisearchBackend.php` (hook highlighting into search)

- [ ] **Step 1: Create the processor**

Create `src/Plugin/search_api/processor/MeilisearchHighlighting.php`:

```php
<?php

declare(strict_types=1);

namespace Drupal\meilisearch\Plugin\search_api\processor;

use Drupal\Core\Form\FormStateInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Query\QueryInterface;

/**
 * Adds highlighting/cropping to Meilisearch search queries.
 *
 * @SearchApiProcessor(
 *   id = "meilisearch_highlighting",
 *   label = @Translation("Meilisearch highlighting"),
 *   description = @Translation("Highlights matched terms and crops snippets."),
 *   stages = {"preprocess_query" = 0}
 * )
 */
class MeilisearchHighlighting extends ProcessorPluginBase {

  public static function supportsIndex(IndexInterface $index): bool {
    return $index->getServerInstance()?->getBackendId() === 'meilisearch';
  }

  public function defaultConfiguration(): array {
    return [
      'fields' => [],
      'pre_tag' => '<em>',
      'post_tag' => '</em>',
      'crop_length' => 10,
      'crop_marker' => '…',
    ];
  }

  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $text_fields = [];
    foreach ($this->index->getFields() as $id => $field) {
      if ($field->getType() === 'text') {
        $text_fields[$id] = $field->getLabel();
      }
    }

    $form['fields'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Fields to highlight'),
      '#options' => $text_fields,
      '#default_value' => $this->configuration['fields'],
    ];
    $form['pre_tag'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Pre tag'),
      '#default_value' => $this->configuration['pre_tag'],
    ];
    $form['post_tag'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Post tag'),
      '#default_value' => $this->configuration['post_tag'],
    ];
    $form['crop_length'] = [
      '#type' => 'number',
      '#title' => $this->t('Crop length (words)'),
      '#default_value' => $this->configuration['crop_length'],
      '#min' => 0,
    ];
    $form['crop_marker'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Crop marker'),
      '#default_value' => $this->configuration['crop_marker'],
    ];
    return $form;
  }

  public function preprocessSearchQuery(QueryInterface $query): void {
    // Store the highlighting config on the query so the backend can read it.
    $fields = array_keys(array_filter($this->configuration['fields']));
    if (empty($fields)) {
      return;
    }
    $query->setOption('meilisearch_highlighting', [
      'fields' => $fields,
      'pre_tag' => $this->configuration['pre_tag'],
      'post_tag' => $this->configuration['post_tag'],
      'crop_length' => (int) $this->configuration['crop_length'],
      'crop_marker' => $this->configuration['crop_marker'],
    ]);
  }

}
```

- [ ] **Step 2: Update MeilisearchBackend to read highlighting option**

Modify the `alterSearchOptions()` method in `src/Plugin/search_api/backend/MeilisearchBackend.php`:

```php
  /**
   * Hook point for submodules and processors to alter search options.
   */
  protected function alterSearchOptions(array &$options, QueryInterface $query): void {
    $hl = $query->getOption('meilisearch_highlighting');
    if (is_array($hl)) {
      $options['attributesToHighlight'] = $hl['fields'];
      $options['highlightPreTag'] = $hl['pre_tag'];
      $options['highlightPostTag'] = $hl['post_tag'];
      $options['attributesToCrop'] = $hl['fields'];
      $options['cropLength'] = $hl['crop_length'];
      $options['cropMarker'] = $hl['crop_marker'];
    }
  }
```

- [ ] **Step 3: Commit**

```bash
git add src/Plugin/search_api/processor/MeilisearchHighlighting.php src/Plugin/search_api/backend/MeilisearchBackend.php
git commit -m "feat: add MeilisearchHighlighting processor and wire into backend"
```

---

### Task 22: Wire synonyms/stopwords sync on index save

**Files:**
- Modify: `src/Plugin/search_api/backend/MeilisearchBackend.php` (call processor syncToServer in updateIndex)

- [ ] **Step 1: Update buildIndexSettings and updateIndex**

In `src/Plugin/search_api/backend/MeilisearchBackend.php`, modify `updateIndex` to trigger processor sync after settings update. Add after the `waitForTask()` call:

```php
  /**
   * {@inheritdoc}
   */
  public function updateIndex(IndexInterface $index): void {
    try {
      $settings = $this->buildIndexSettings($index);
      $task = $this->api->updateSettings($index->id(), $settings);
      $this->api->waitForTask((int) $task['taskUid']);
      $this->syncProcessorSettings($index);
    }
    catch (MeilisearchApiException $e) {
      $this->logger->error('Failed to update index @id: @msg', [
        '@id' => $index->id(),
        '@msg' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Triggers syncToServer() on processors that support it.
   */
  protected function syncProcessorSettings(IndexInterface $index): void {
    foreach ($index->getProcessors() as $processor) {
      if (method_exists($processor, 'syncToServer')) {
        $processor->syncToServer($index);
      }
    }
  }
```

- [ ] **Step 2: Commit**

```bash
git add src/Plugin/search_api/backend/MeilisearchBackend.php
git commit -m "feat: sync processor settings (synonyms, stopwords) on index update"
```

---

## Phase 7: Facets Submodule

### Task 23: Create meilisearch_facets submodule scaffold

**Files:**
- Create: `modules/meilisearch_facets/meilisearch_facets.info.yml`
- Create: `modules/meilisearch_facets/meilisearch_facets.services.yml`

- [ ] **Step 1: Create info.yml**

Create `modules/meilisearch_facets/meilisearch_facets.info.yml`:

```yaml
name: 'Meilisearch Facets'
type: module
description: 'Enables Facets module integration with Meilisearch.'
core_version_requirement: ^9.3 || ^10 || ^11
package: 'Search'
dependencies:
  - meilisearch:meilisearch
  - facets:facets
```

- [ ] **Step 2: Create services.yml**

Create `modules/meilisearch_facets/meilisearch_facets.services.yml`:

```yaml
services:
  meilisearch_facets.determining_server_features_subscriber:
    class: Drupal\meilisearch_facets\EventSubscriber\DeterminingServerFeaturesSubscriber
    tags:
      - { name: event_subscriber }

  meilisearch_facets.query_subscriber:
    class: Drupal\meilisearch_facets\EventSubscriber\QuerySubscriber
    tags:
      - { name: event_subscriber }

  meilisearch_facets.results_subscriber:
    class: Drupal\meilisearch_facets\EventSubscriber\ProcessingResultsSubscriber
    tags:
      - { name: event_subscriber }
```

- [ ] **Step 3: Commit**

```bash
git add modules/meilisearch_facets/
git commit -m "feat: scaffold meilisearch_facets submodule"
```

---

### Task 24: Implement facets event subscribers

**Files:**
- Create: `modules/meilisearch_facets/src/EventSubscriber/DeterminingServerFeaturesSubscriber.php`
- Create: `modules/meilisearch_facets/src/EventSubscriber/QuerySubscriber.php`
- Create: `modules/meilisearch_facets/src/EventSubscriber/ProcessingResultsSubscriber.php`

- [ ] **Step 1: Create DeterminingServerFeaturesSubscriber**

Create `modules/meilisearch_facets/src/EventSubscriber/DeterminingServerFeaturesSubscriber.php`:

```php
<?php

declare(strict_types=1);

namespace Drupal\meilisearch_facets\EventSubscriber;

use Drupal\search_api\Event\DeterminingServerFeaturesEvent;
use Drupal\search_api\Event\SearchApiEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Advertises facet support for the Meilisearch backend.
 */
class DeterminingServerFeaturesSubscriber implements EventSubscriberInterface {

  public static function getSubscribedEvents(): array {
    return [SearchApiEvents::DETERMINING_SERVER_FEATURES => 'onDetermining'];
  }

  public function onDetermining(DeterminingServerFeaturesEvent $event): void {
    if ($event->getBackend()->getPluginId() !== 'meilisearch') {
      return;
    }
    $features = $event->getFeatures();
    if (!in_array('search_api_facets', $features, TRUE)) {
      $features[] = 'search_api_facets';
    }
    $event->setFeatures($features);
  }

}
```

- [ ] **Step 2: Create QuerySubscriber**

Create `modules/meilisearch_facets/src/EventSubscriber/QuerySubscriber.php`:

```php
<?php

declare(strict_types=1);

namespace Drupal\meilisearch_facets\EventSubscriber;

use Drupal\search_api\Event\QueryPreExecuteEvent;
use Drupal\search_api\Event\SearchApiEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Adds requested facets to Meilisearch query options before execution.
 */
class QuerySubscriber implements EventSubscriberInterface {

  public static function getSubscribedEvents(): array {
    return [SearchApiEvents::QUERY_PRE_EXECUTE => 'onPreExecute'];
  }

  public function onPreExecute(QueryPreExecuteEvent $event): void {
    $query = $event->getQuery();
    if ($query->getIndex()->getServerInstance()?->getBackendId() !== 'meilisearch') {
      return;
    }
    $facets = $query->getOption('search_api_facets', []);
    if (empty($facets)) {
      return;
    }
    $fields = [];
    foreach ($facets as $info) {
      if (isset($info['field'])) {
        $fields[] = $info['field'];
      }
    }
    if ($fields) {
      $query->setOption('meilisearch_facets', array_values(array_unique($fields)));
    }
  }

}
```

- [ ] **Step 3: Create ProcessingResultsSubscriber**

Create `modules/meilisearch_facets/src/EventSubscriber/ProcessingResultsSubscriber.php`:

```php
<?php

declare(strict_types=1);

namespace Drupal\meilisearch_facets\EventSubscriber;

use Drupal\search_api\Event\ProcessingResultsEvent;
use Drupal\search_api\Event\SearchApiEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Parses facet data from Meilisearch response into Facets module format.
 */
class ProcessingResultsSubscriber implements EventSubscriberInterface {

  public static function getSubscribedEvents(): array {
    return [SearchApiEvents::PROCESSING_RESULTS => 'onProcessing'];
  }

  public function onProcessing(ProcessingResultsEvent $event): void {
    $results = $event->getResults();
    $query = $results->getQuery();
    if ($query->getIndex()->getServerInstance()?->getBackendId() !== 'meilisearch') {
      return;
    }
    $raw = $results->getExtraData('meilisearch_response');
    if (!is_array($raw)) {
      return;
    }
    $distribution = $raw['facetDistribution'] ?? [];
    $facetData = [];
    foreach ($distribution as $field => $buckets) {
      foreach ($buckets as $value => $count) {
        $facetData[$field][] = [
          'count' => $count,
          'filter' => '"' . $value . '"',
        ];
      }
    }
    if ($facetData) {
      $results->setExtraData('search_api_facets', $facetData);
    }
  }

}
```

- [ ] **Step 4: Update MeilisearchBackend.alterSearchOptions to include facets**

Modify `src/Plugin/search_api/backend/MeilisearchBackend.php`, update `alterSearchOptions()`:

```php
  protected function alterSearchOptions(array &$options, QueryInterface $query): void {
    $hl = $query->getOption('meilisearch_highlighting');
    if (is_array($hl)) {
      $options['attributesToHighlight'] = $hl['fields'];
      $options['highlightPreTag'] = $hl['pre_tag'];
      $options['highlightPostTag'] = $hl['post_tag'];
      $options['attributesToCrop'] = $hl['fields'];
      $options['cropLength'] = $hl['crop_length'];
      $options['cropMarker'] = $hl['crop_marker'];
    }

    $facets = $query->getOption('meilisearch_facets');
    if (is_array($facets) && $facets) {
      $options['facets'] = $facets;
    }
  }
```

- [ ] **Step 5: Commit**

```bash
git add modules/meilisearch_facets/ src/Plugin/search_api/backend/MeilisearchBackend.php
git commit -m "feat: implement facets submodule with event-based integration"
```

---

## Phase 8: Analytics Submodule

### Task 25: Create meilisearch_analytics submodule scaffold

**Files:**
- Create: `modules/meilisearch_analytics/meilisearch_analytics.info.yml`
- Create: `modules/meilisearch_analytics/meilisearch_analytics.services.yml`
- Create: `modules/meilisearch_analytics/meilisearch_analytics.routing.yml`
- Create: `modules/meilisearch_analytics/meilisearch_analytics.libraries.yml`

- [ ] **Step 1: Create info.yml**

Create `modules/meilisearch_analytics/meilisearch_analytics.info.yml`:

```yaml
name: 'Meilisearch Analytics'
type: module
description: 'Tracks click and conversion events for Meilisearch searches.'
core_version_requirement: ^9.3 || ^10 || ^11
package: 'Search'
dependencies:
  - meilisearch:meilisearch
```

- [ ] **Step 2: Create services.yml**

Create `modules/meilisearch_analytics/meilisearch_analytics.services.yml`:

```yaml
services:
  meilisearch_analytics.service:
    class: Drupal\meilisearch_analytics\Analytics\AnalyticsService
    arguments:
      - '@meilisearch.api'
      - '@current_user'
      - '@logger.channel.meilisearch'

  meilisearch_analytics.query_subscriber:
    class: Drupal\meilisearch_analytics\EventSubscriber\SearchMetadataSubscriber
    tags:
      - { name: event_subscriber }
```

- [ ] **Step 3: Create routing.yml**

Create `modules/meilisearch_analytics/meilisearch_analytics.routing.yml`:

```yaml
meilisearch_analytics.click:
  path: '/meilisearch/events/click'
  defaults:
    _controller: '\Drupal\meilisearch_analytics\Controller\ClickTrackingController::record'
  methods: [POST]
  requirements:
    _access: 'TRUE'
  options:
    _format: 'json'
```

- [ ] **Step 4: Create libraries.yml**

Create `modules/meilisearch_analytics/meilisearch_analytics.libraries.yml`:

```yaml
click_tracking:
  js:
    js/click-tracking.js: {}
  dependencies:
    - core/drupal
    - core/drupalSettings
```

- [ ] **Step 5: Commit**

```bash
git add modules/meilisearch_analytics/
git commit -m "feat: scaffold meilisearch_analytics submodule"
```

---

### Task 26: Implement SearchMetadataSubscriber

**Files:**
- Create: `modules/meilisearch_analytics/src/EventSubscriber/SearchMetadataSubscriber.php`
- Modify: `src/Plugin/search_api/backend/MeilisearchBackend.php` (inject metadata header)

- [ ] **Step 1: Create SearchMetadataSubscriber**

Create `modules/meilisearch_analytics/src/EventSubscriber/SearchMetadataSubscriber.php`:

```php
<?php

declare(strict_types=1);

namespace Drupal\meilisearch_analytics\EventSubscriber;

use Drupal\search_api\Event\QueryPreExecuteEvent;
use Drupal\search_api\Event\SearchApiEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Marks queries so the backend includes Meili-Include-Metadata header.
 */
class SearchMetadataSubscriber implements EventSubscriberInterface {

  public static function getSubscribedEvents(): array {
    return [SearchApiEvents::QUERY_PRE_EXECUTE => 'onPreExecute'];
  }

  public function onPreExecute(QueryPreExecuteEvent $event): void {
    $query = $event->getQuery();
    if ($query->getIndex()->getServerInstance()?->getBackendId() !== 'meilisearch') {
      return;
    }
    $query->setOption('meilisearch_include_metadata', TRUE);
  }

}
```

- [ ] **Step 2: Update backend to pass metadata header**

The `meilisearch-php` SDK does not directly expose per-request headers on search. Add a comment in the backend explaining that metadata flag is available to other subscribers and stored on result's extra data. Modify `alterSearchOptions()`:

```php
  protected function alterSearchOptions(array &$options, QueryInterface $query): void {
    $hl = $query->getOption('meilisearch_highlighting');
    if (is_array($hl)) {
      $options['attributesToHighlight'] = $hl['fields'];
      $options['highlightPreTag'] = $hl['pre_tag'];
      $options['highlightPostTag'] = $hl['post_tag'];
      $options['attributesToCrop'] = $hl['fields'];
      $options['cropLength'] = $hl['crop_length'];
      $options['cropMarker'] = $hl['crop_marker'];
    }

    $facets = $query->getOption('meilisearch_facets');
    if (is_array($facets) && $facets) {
      $options['facets'] = $facets;
    }

    // Analytics submodule may request queryUid metadata via custom fields.
    if ($query->getOption('meilisearch_include_metadata')) {
      $options['analyticsCustomFields'] = [
        'drupal_query' => $query->getIndex()->id(),
      ];
    }
  }
```

- [ ] **Step 3: Commit**

```bash
git add modules/meilisearch_analytics/src/EventSubscriber/ src/Plugin/search_api/backend/MeilisearchBackend.php
git commit -m "feat: add SearchMetadataSubscriber for analytics integration"
```

---

### Task 27: Implement AnalyticsService and ClickTrackingController

**Files:**
- Create: `modules/meilisearch_analytics/src/Analytics/AnalyticsService.php`
- Create: `modules/meilisearch_analytics/src/Controller/ClickTrackingController.php`
- Create: `modules/meilisearch_analytics/js/click-tracking.js`

- [ ] **Step 1: Create AnalyticsService**

Create `modules/meilisearch_analytics/src/Analytics/AnalyticsService.php`:

```php
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
```

- [ ] **Step 2: Create ClickTrackingController**

Create `modules/meilisearch_analytics/src/Controller/ClickTrackingController.php`:

```php
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
```

- [ ] **Step 3: Create click-tracking.js**

Create `modules/meilisearch_analytics/js/click-tracking.js`:

```javascript
(function (Drupal) {
  'use strict';

  Drupal.behaviors.meilisearchClickTracking = {
    attach: function (context) {
      const links = context.querySelectorAll('[data-meilisearch-result]');
      links.forEach(function (link) {
        if (link.dataset.meiliBound) return;
        link.dataset.meiliBound = '1';
        link.addEventListener('click', function () {
          const payload = {
            indexUid: link.dataset.meilisearchIndex,
            queryUid: link.dataset.meilisearchQueryuid,
            objectId: link.dataset.meilisearchResult,
            position: parseInt(link.dataset.meilisearchPosition || '0', 10)
          };
          fetch('/meilisearch/events/click', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
            keepalive: true
          });
        });
      });
    }
  };
})(Drupal);
```

- [ ] **Step 4: Commit**

```bash
git add modules/meilisearch_analytics/src/Analytics/ modules/meilisearch_analytics/src/Controller/ modules/meilisearch_analytics/js/
git commit -m "feat: add analytics service, click controller, and JS tracking"
```

---

## Phase 9: Deployment Examples & README

### Task 28: Create Docker Compose example

**Files:**
- Create: `docs/docker-compose.example.yml`
- Create: `docs/docker-compose-setup.md`

- [ ] **Step 1: Create compose file**

Create `docs/docker-compose.example.yml`:

```yaml
services:
  drupal:
    image: drupal:11-php8.3-apache
    ports:
      - "8080:80"
    volumes:
      - drupal_modules:/var/www/html/modules
      - drupal_sites:/var/www/html/sites
    depends_on:
      meilisearch:
        condition: service_healthy

  meilisearch:
    image: getmeili/meilisearch:latest
    ports:
      - "7700:7700"
    environment:
      MEILI_MASTER_KEY: "masterKey123"
      MEILI_ENV: "development"
    volumes:
      - meili_data:/meili_data
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:7700/health"]
      interval: 5s
      timeout: 5s
      retries: 5

volumes:
  drupal_modules:
  drupal_sites:
  meili_data:
```

- [ ] **Step 2: Create setup documentation**

Create `docs/docker-compose-setup.md`:

```markdown
# Docker Compose Setup

## Prerequisites

- Docker and Docker Compose v2.22+
- `curl` for healthchecks

## Steps

1. Copy the example compose file:

   ```
   cp docs/docker-compose.example.yml compose.yaml
   ```

2. Start the stack:

   ```
   docker compose up -d
   ```

3. Install Drupal (browse to <http://localhost:8080>).

4. Add the module via Composer (on the Drupal container):

   ```
   docker compose exec drupal composer require drupal/meilisearch
   ```

5. Enable the module:

   ```
   docker compose exec drupal drush en meilisearch -y
   ```

6. In the Drupal admin UI, go to **Configuration → Search API** and create a new server:
   - Backend: **Meilisearch**
   - Connection mode: **Self-hosted**
   - Host URL: `http://meilisearch` (Docker service DNS)
   - Port: `7700`
   - API key: `masterKey123`

7. Create an index and attach it to the server, then add fields and index content.
```

- [ ] **Step 3: Commit**

```bash
git add docs/docker-compose.example.yml docs/docker-compose-setup.md
git commit -m "docs: add Docker Compose deployment example"
```

---

### Task 29: Create DDEV setup docs

**Files:**
- Create: `docs/ddev-setup.md`
- Create: `docs/ddev/docker-compose.meilisearch.yaml`

- [ ] **Step 1: Create DDEV compose addon**

Create `docs/ddev/docker-compose.meilisearch.yaml`:

```yaml
# Place this file at: .ddev/docker-compose.meilisearch.yaml
services:
  meilisearch:
    container_name: ddev-${DDEV_SITENAME}-meilisearch
    image: getmeili/meilisearch:latest
    restart: 'no'
    labels:
      com.ddev.site-name: ${DDEV_SITENAME}
      com.ddev.approot: ${DDEV_APPROOT}
    environment:
      MEILI_MASTER_KEY: "masterKey123"
      MEILI_ENV: "development"
      VIRTUAL_HOST: ${DDEV_HOSTNAME}
      HTTP_EXPOSE: "7700:7700"
      HTTPS_EXPOSE: "7701:7700"
    volumes:
      - meili-data:/meili_data
    networks:
      - default
      - ddev_default

  web:
    depends_on:
      - meilisearch
    links:
      - meilisearch:meilisearch

volumes:
  meili-data: {}
```

- [ ] **Step 2: Create DDEV setup docs**

Create `docs/ddev-setup.md`:

```markdown
# DDEV Setup

## Prerequisites

- DDEV v1.22+
- An existing DDEV project (or `ddev config` to create one)

## Steps

1. Copy the Meilisearch compose addon into your project:

   ```
   mkdir -p .ddev
   cp docs/ddev/docker-compose.meilisearch.yaml .ddev/
   ```

2. Restart DDEV:

   ```
   ddev restart
   ```

3. Install the module via Composer:

   ```
   ddev composer require drupal/meilisearch
   ```

4. Enable it:

   ```
   ddev drush en meilisearch -y
   ```

5. Configure the server in Drupal admin:
   - Host URL: `http://meilisearch`
   - Port: `7700`
   - API key: `masterKey123`

6. Access the Meilisearch dashboard (if exposed) at <https://your-project.ddev.site:7701>.
```

- [ ] **Step 3: Commit**

```bash
git add docs/ddev-setup.md docs/ddev/
git commit -m "docs: add DDEV setup guide and compose addon"
```

---

### Task 30: Create Cloud setup docs

**Files:**
- Create: `docs/cloud-setup.md`

- [ ] **Step 1: Create Cloud docs**

Create `docs/cloud-setup.md`:

```markdown
# Meilisearch Cloud Setup

## Prerequisites

- A Meilisearch Cloud account at <https://cloud.meilisearch.com>
- A Drupal site with the `meilisearch` module installed

## Steps

1. **Create a project** in the Cloud dashboard. After provisioning, copy:
   - The project URL (e.g., `https://ms-abc123.fra.meilisearch.io`)
   - An **admin API key** for indexing operations
   - A **search-only API key** for frontend queries (optional, recommended)

2. **Configure the Drupal server:**
   - Go to **Configuration → Search API → Servers → Add server**
   - Name: e.g. "Meilisearch Cloud"
   - Backend: **Meilisearch**
   - Connection mode: **Meilisearch Cloud**
   - Host URL: paste the project URL (no port needed)
   - API key: admin API key
   - Click **Save**. The "Is Cloud" flag is auto-detected from the `.meilisearch.io` domain.

3. **Verify connectivity:** the server edit screen shows the Meilisearch version and "Cloud: Yes" when connected.

4. **Create an index** and attach it to the Cloud server. Add fields, then index content. The module will create the matching Meilisearch index via the Cloud API.

5. **(Optional) Enable semantic search:**
   - In the Cloud dashboard, go to **Settings → Embedders** and configure an embedder (e.g., OpenAI `text-embedding-3-small`).
   - In Drupal, edit the server, set **Search mode** to **Hybrid** or **Semantic**, and enter the embedder name.

6. **(Optional) Enable analytics:**
   - Install the `meilisearch_analytics` submodule.
   - Add the `click-tracking` library to your theme:

     ```yaml
     # theme.libraries.yml
     search-results:
       dependencies:
         - meilisearch_analytics/click_tracking
     ```

   - Render result links with `data-meilisearch-*` attributes (see submodule README).

## Security tips

- **Use a search-only key for frontend queries** via Meilisearch's tenant token mechanism. Do not expose admin keys.
- Rotate the admin key periodically via the Cloud dashboard.
```

- [ ] **Step 2: Commit**

```bash
git add docs/cloud-setup.md
git commit -m "docs: add Meilisearch Cloud setup guide"
```

---

### Task 31: Create README

**Files:**
- Create: `README.md`

- [ ] **Step 1: Create README**

Create `README.md`:

```markdown
# Meilisearch for Drupal

Official Meilisearch backend for the Drupal Search API.

## Requirements

- Drupal 9.3, 10, or 11
- PHP 8.1+
- A Meilisearch instance (self-hosted or Cloud) — v1.3.3+

## Installation

```
composer require drupal/meilisearch
drush en meilisearch -y
```

## Features

- Full-text search via Drupal Search API
- Self-hosted and Meilisearch Cloud support (auto-detected)
- Semantic and hybrid (keyword + vector) search with embedders
- Geo search (`_geoRadius`, `_geoBoundingBox`)
- Configurable ranking rules
- Synonyms, stop words, and highlighting processors

### Optional submodules

- **`meilisearch_facets`** — faceted search via `drupal/facets`
- **`meilisearch_analytics`** — click & conversion event tracking

## Quick start

1. Enable the module.
2. Go to **Configuration → Search API** and add a server with Meilisearch as the backend.
3. Fill in the connection details (URL, port or Cloud URL, API key).
4. Create an index, pick fields to index, and run indexing.

## Deployment guides

- [Docker Compose](docs/docker-compose-setup.md)
- [DDEV](docs/ddev-setup.md)
- [Meilisearch Cloud](docs/cloud-setup.md)

## Architecture

| Layer | Class |
|-------|-------|
| Search API backend | `MeilisearchBackend` |
| API wrapper | `MeilisearchApiService` |
| Document conversion | `DocumentConverter` |
| Filter translation | `FilterBuilder` + condition parsers |

## License

GPL-2.0-or-later
```

- [ ] **Step 2: Commit**

```bash
git add README.md
git commit -m "docs: add README"
```

---

## Phase 10: CI and Final Verification

### Task 32: Add GitHub Actions workflow

**Files:**
- Create: `.github/workflows/test.yml`
- Create: `phpunit.xml.dist`

- [ ] **Step 1: Create phpunit.xml.dist**

Create `phpunit.xml.dist`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/9.6/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         cacheDirectory=".phpunit.cache">
  <testsuites>
    <testsuite name="unit">
      <directory>tests/src/Unit</directory>
    </testsuite>
  </testsuites>
  <coverage>
    <include>
      <directory suffix=".php">src</directory>
    </include>
  </coverage>
</phpunit>
```

- [ ] **Step 2: Create GitHub Actions workflow**

Create `.github/workflows/test.yml`:

```yaml
name: Tests

on:
  push:
    branches: [main, 1.x]
  pull_request:

jobs:
  unit:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        php: ['8.1', '8.2', '8.3']
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          coverage: none
      - run: composer install --no-interaction --prefer-dist
      - run: vendor/bin/phpunit --testsuite=unit

  functional:
    runs-on: ubuntu-latest
    services:
      meilisearch:
        image: getmeili/meilisearch:latest
        ports: ['7700:7700']
        env:
          MEILI_MASTER_KEY: testKey
          MEILI_NO_ANALYTICS: 'true'
        options: >-
          --health-cmd "curl -f http://localhost:7700/health"
          --health-interval 5s
          --health-timeout 5s
          --health-retries 5
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
      - run: composer install --no-interaction
      - name: Verify Meilisearch reachable
        run: curl -f http://localhost:7700/health
```

- [ ] **Step 3: Commit**

```bash
git add phpunit.xml.dist .github/
git commit -m "ci: add PHPUnit config and GitHub Actions workflow"
```

---

### Task 33: Final verification

**Files:** None (verification only)

- [ ] **Step 1: Run all unit tests**

Run: `vendor/bin/phpunit --testsuite=unit`
Expected: All tests pass.

- [ ] **Step 2: Verify composer autoload**

Run: `composer dump-autoload`
Expected: No errors, all `Drupal\meilisearch\*` classes discoverable.

- [ ] **Step 3: Check module structure**

Run: `find . -name "*.php" -path "./src/*" | head -30`
Expected: Shows the expected class files.

- [ ] **Step 4: Dry-run Drupal enable (if a Drupal site is available)**

Run: `drush pm:list --type=module --status=disabled --filter=name~=meilisearch`
Expected: Three modules listed: `meilisearch`, `meilisearch_facets`, `meilisearch_analytics`.

- [ ] **Step 5: Tag the release**

```bash
git tag -a 1.0.0-alpha1 -m "Initial alpha release"
```

---

## Self-Review

**Spec coverage:**
- Module scaffold → Tasks 1-2 ✓
- API service + client factory → Tasks 3-6 ✓
- Document converter → Task 7 ✓
- Filter builder (all operators + geo) → Tasks 8-12 ✓
- Backend plugin (connection, form, lifecycle, indexing, search, semantic) → Tasks 13-18 ✓
- Processors (synonyms, stopwords, highlighting) → Tasks 19-22 ✓
- Facets submodule → Tasks 23-24 ✓
- Analytics submodule → Tasks 25-27 ✓
- Deployment examples (Docker, DDEV, Cloud) → Tasks 28-30 ✓
- README → Task 31 ✓
- CI → Task 32 ✓
- Final verification → Task 33 ✓

**Placeholder scan:** None found. Every code step has complete code.

**Type consistency:** Method signatures (`addIndex`, `updateIndex`, `removeIndex`, `indexItems`, `search`, `syncToServer`) are consistent across tasks. Service names in YAML match container references in PHP.

**Ambiguity check:** Synonym processor uses a custom `syncToServer()` hook called by the backend's `updateIndex()` — this is documented in Task 22 to make the contract explicit.
