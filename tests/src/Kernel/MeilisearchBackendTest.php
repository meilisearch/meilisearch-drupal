<?php

declare(strict_types=1);

namespace Drupal\Tests\meilisearch\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Entity\Server;
use Drupal\search_api\SearchApiException;
use Drupal\Tests\search_api\Functional\ExampleContentTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests Meilisearch-specific backend behavior against a real instance.
 *
 * @group meilisearch
 */
#[RunTestsInSeparateProcesses]
class MeilisearchBackendTest extends KernelTestBase {

  use ExampleContentTrait;
  use MeilisearchTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'field',
    'search_api',
    'user',
    'system',
    'entity_test',
    'filter',
    'text',
    'search_api_test_example_content',
    'meilisearch',
    'meilisearch_test',
  ];

  /**
   * The ID of the test index.
   */
  protected string $indexId = 'meilisearch_test_index';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->setUpMeilisearchConnection(['meilisearch_test_server']);
    parent::setUp();
    $this->installSchema('search_api', ['search_api_item']);
    $this->installEntitySchema('entity_test_mulrev_changed');
    $this->installEntitySchema('search_api_task');
    $this->installConfig(['search_api', 'search_api_test_example_content', 'meilisearch_test']);
    $this->setUpExampleStructure();
    $this->insertExampleContent();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->tearDownMeilisearch();
    parent::tearDown();
  }

  /**
   * A second, broken server must not take over the first one's connection.
   */
  public function testServersAreIsolated(): void {
    $this->indexItems($this->indexId);

    $broken = Server::create([
      'id' => 'broken',
      'name' => 'Broken',
      'backend' => 'meilisearch',
      'backend_config' => ['url' => 'http://127.0.0.1:1', 'api_key' => 'nope'],
    ]);
    $this->assertFalse($broken->getBackend()->isAvailable());

    $results = Index::load($this->indexId)->query()->keys('foo')->execute();
    $this->assertGreaterThan(0, $results->getResultCount());
  }

  /**
   * An unreachable Meilisearch must surface as a SearchApiException.
   */
  public function testUnreachableServerThrowsSearchApiException(): void {
    $server = Server::load('meilisearch_test_server');
    $server->getBackend()->setConfiguration(['url' => 'http://127.0.0.1:1'] + $server->getBackendConfig());
    $index = Index::load($this->indexId);
    $query = $index->query()->keys('foo');

    $this->expectException(SearchApiException::class);
    $server->getBackend()->search($query);
  }

  /**
   * A task that fails in Meilisearch must fail indexing, not mark items done.
   */
  public function testFailedIndexingTaskKeepsItemsPending(): void {
    $index = Index::load($this->indexId);
    $uid = $index->getServerInstance()->getBackend()->getIndexUid($index);
    // Recreate the Meilisearch index with a different primary key, so adding
    // documents with ours fails asynchronously.
    $client = $this->meilisearchClient();
    $client->waitForTask($client->deleteIndex($uid)['taskUid']);
    $client->waitForTask($client->createIndex($uid, ['primaryKey' => 'other'])['taskUid']);

    // Search API catches the exception and logs it; nothing is marked done.
    $this->assertSame(0, $index->indexItems());
    $this->assertSame(0, $index->getTrackerInstance()->getIndexedItemsCount());
  }

  /**
   * Clearing one datasource must keep the documents of the others.
   */
  public function testDeleteAllItemsOfOneDatasource(): void {
    $this->indexItems($this->indexId);
    $index = Index::load($this->indexId);
    $backend = $index->getServerInstance()->getBackend();

    $backend->deleteAllIndexItems($index, 'entity:user');
    $this->assertSame(5, $index->query()->execute()->getResultCount());

    $backend->deleteAllIndexItems($index, 'entity:entity_test_mulrev_changed');
    $this->assertSame(0, $index->query()->execute()->getResultCount());
  }

  /**
   * Search results carry the Meilisearch ranking score and an exact count.
   */
  public function testResultsHaveScoresAndExactCounts(): void {
    $this->indexItems($this->indexId);
    $index = Index::load($this->indexId);
    $all = $index->query()->keys('foo')->range(0, 100)->execute();
    $results = $index->query()->keys('foo')->range(0, 1)->execute();

    $this->assertCount(1, $results->getResultItems());
    $this->assertGreaterThan(1, $results->getResultCount());
    $this->assertSame(count($all->getResultItems()), $results->getResultCount(), 'The count covers all pages.');
    $item = current($results->getResultItems());
    $this->assertGreaterThan(0, $item->getScore());
    $this->assertLessThanOrEqual(1, $item->getScore());
  }

  /**
   * Submitting the server form with an empty key keeps the stored key.
   */
  public function testEmptyApiKeyKeepsStoredKey(): void {
    $backend = Server::load('meilisearch_test_server')->getBackend();
    $backend->setConfiguration(['api_key' => 'stored-secret'] + $backend->getConfiguration());

    $form_state = new FormState();
    $form = $backend->buildConfigurationForm([], $form_state);
    $this->assertSame('password', $form['api_key']['#type']);
    $this->assertArrayNotHasKey('#default_value', $form['api_key'], 'The key is never sent to the browser.');

    $form_state->setValues(['api_key' => ''] + $backend->getConfiguration());
    $backend->submitConfigurationForm($form, $form_state);
    $this->assertSame('stored-secret', $backend->getConfiguration()['api_key']);
  }

}
