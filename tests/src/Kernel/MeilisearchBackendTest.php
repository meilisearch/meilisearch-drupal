<?php

declare(strict_types=1);

namespace Drupal\Tests\meilisearch\Kernel;

use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Http\ClientFactory;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\KernelTests\KernelTestBase;
use Drupal\meilisearch\Api\MeilisearchApiFactory;
use Drupal\meilisearch\Plugin\search_api\backend\MeilisearchBackend;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Entity\Server;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\SearchApiException;
use Drupal\Tests\search_api\Functional\ExampleContentTrait;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Meilisearch\Contracts\TasksQuery;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

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

    try {
      $server->getBackend()->search($query);
      $this->fail('Searching an unreachable server must throw.');
    }
    catch (SearchApiException) {
      // A failed search must not be cached as "no results".
      $this->assertInstanceOf(RefinableCacheableDependencyInterface::class, $query);
      $this->assertSame(0, $query->getCacheMaxAge());
    }
  }

  /**
   * A task that fails in Meilisearch must fail indexing, not mark items done.
   */
  public function testFailedIndexingTaskKeepsItemsPending(): void {
    $index = Index::load($this->indexId);
    $uid = $this->meilisearchBackend()->getIndexUid($index);
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
   * With keywords, sorts placed after relevance must not outrank it.
   *
   * Sorts are strict in the indexes this module creates, so a sort sent after
   * the position of relevance would order the results before relevance does.
   */
  public function testSortsAfterRelevanceAreNotSentWithKeywords(): void {
    $sorts = function (?string $keys, array $fields): array {
      $query = Index::load($this->indexId)->query();
      if ($keys !== NULL) {
        $query->keys($keys);
      }
      foreach ($fields as $field => $direction) {
        $query->sort($field, $direction);
      }
      $build = new \ReflectionMethod($this->meilisearchBackend(), 'buildSorts');
      return $build->invoke($this->meilisearchBackend(), $query);
    };

    $this->assertSame(['id:desc'], $sorts('foo', [
      'id' => QueryInterface::SORT_DESC,
      'search_api_relevance' => QueryInterface::SORT_DESC,
      'created' => QueryInterface::SORT_ASC,
    ]), 'Sorts after relevance are dropped.');
    $this->assertSame([], $sorts('foo', [
      'search_api_relevance' => QueryInterface::SORT_DESC,
      'created' => QueryInterface::SORT_ASC,
    ]), 'Relevance first means relevance order.');
    $this->assertSame(['id:desc', 'created:asc'], $sorts(NULL, [
      'id' => QueryInterface::SORT_DESC,
      'search_api_relevance' => QueryInterface::SORT_DESC,
      'created' => QueryInterface::SORT_ASC,
    ]), 'Without keywords relevance orders nothing and is skipped.');
    $this->assertSame(['id:desc', 'created:asc'], $sorts('foo', [
      'id' => QueryInterface::SORT_DESC,
      'search_api_random' => QueryInterface::SORT_ASC,
      'created' => QueryInterface::SORT_ASC,
    ]), 'An unsupported random sort is skipped.');
  }

  /**
   * Submitting the server form with an empty key keeps the stored key.
   */
  public function testEmptyApiKeyKeepsStoredKey(): void {
    // Let the form manage the key: drop its settings.php override.
    unset($GLOBALS['config']['search_api.server.meilisearch_test_server']['backend_config']['api_key']);
    \Drupal::configFactory()->getEditable('search_api.server.meilisearch_test_server')
      ->set('backend_config.api_key', 'stored-secret')
      ->save();
    \Drupal::configFactory()->reset();
    \Drupal::entityTypeManager()->getStorage('search_api_server')->resetCache();
    $backend = $this->meilisearchBackend();

    $form_state = new FormState();
    $form = $backend->buildConfigurationForm([], $form_state);
    $this->assertSame('password', $form['api_key']['#type']);
    $this->assertArrayNotHasKey('#default_value', $form['api_key'], 'The key is never sent to the browser.');

    $form_state->setValues(['api_key' => ''] + $backend->getConfiguration());
    $backend->submitConfigurationForm($form, $form_state);
    $this->assertSame('stored-secret', $backend->getConfiguration()['api_key']);
  }

  /**
   * A stored key is only kept for the host it was entered for.
   *
   * Otherwise anyone allowed to edit the server could send the key to a host
   * of their choice by changing the URL and leaving the key field empty.
   */
  public function testChangingTheUrlHostRequiresTheStoredKeyAgain(): void {
    // Let the form manage the URL and the key: drop their overrides.
    $server_config = &$GLOBALS['config']['search_api.server.meilisearch_test_server']['backend_config'];
    unset($server_config['url'], $server_config['api_key']);
    $reset = function (): void {
      \Drupal::configFactory()->reset();
      \Drupal::entityTypeManager()->getStorage('search_api_server')->resetCache();
    };
    $requests = $this->interceptRequestsTo(['search.example', 'other.example']);
    \Drupal::configFactory()->getEditable('search_api.server.meilisearch_test_server')
      ->set('backend_config.url', 'http://search.example/meilisearch')
      ->set('backend_config.api_key', 'stored-secret')
      ->save();
    $reset();

    $validate = function (array $values): FormState {
      $backend = $this->meilisearchBackend();
      $form_state = new FormState();
      $form = $backend->buildConfigurationForm([], $form_state);
      $form_state->setValues($values + ['api_key' => ''] + $backend->getConfiguration());
      $backend->validateConfigurationForm($form, $form_state);
      return $form_state;
    };

    $form_state = $validate(['url' => 'https://other.example']);
    $this->assertArrayHasKey('api_key', $form_state->getErrors(), 'A new host needs the key again.');
    $this->assertSame([], $requests->getArrayCopy(), 'The stored key is not sent to the new host.');

    foreach (['http://search.example', 'HTTP://Search.Example:80/meilisearch/'] as $url) {
      $requests->exchangeArray([]);
      $form_state = $validate(['url' => $url]);
      $this->assertSame([], $form_state->getErrors(), "$url keeps the stored key.");
      $this->assertSame(['http://search.example:80 Bearer stored-secret'], $requests->getArrayCopy());
    }

    $requests->exchangeArray([]);
    $form_state = $validate(['url' => 'https://other.example', 'api_key' => 'new-secret']);
    $this->assertSame([], $form_state->getErrors(), 'A key entered with the new URL is accepted.');
    $this->assertSame(['https://other.example:443 Bearer new-secret'], $requests->getArrayCopy());

    // A key set in settings.php cannot be entered in the form: the URL change
    // is accepted, and the documentation asks to override both together.
    $server_config['api_key'] = 'overridden-secret';
    $reset();
    $requests->exchangeArray([]);
    $form_state = $validate(['url' => 'https://other.example']);
    $this->assertSame([], $form_state->getErrors());
  }

  /**
   * A URL set in settings.php cannot be changed in the form: keep the key.
   *
   * The server form loads the server without overrides, so the stored URL
   * differs from the one in settings.php that the form submits.
   */
  public function testOverriddenUrlKeepsTheStoredKey(): void {
    // Let the form manage the key, but keep the URL in settings.php.
    unset($GLOBALS['config']['search_api.server.meilisearch_test_server']['backend_config']['api_key']);
    \Drupal::configFactory()->getEditable('search_api.server.meilisearch_test_server')
      ->set('backend_config.url', 'http://stored.example:7700')
      ->set('backend_config.api_key', (string) getenv('MEILISEARCH_TEST_KEY'))
      ->save();
    $requests = $this->interceptRequestsTo(['stored.example']);
    \Drupal::configFactory()->reset();
    $storage = \Drupal::entityTypeManager()->getStorage('search_api_server');
    $storage->resetCache();
    $server = $storage->loadOverrideFree('meilisearch_test_server');
    $this->assertInstanceOf(Server::class, $server);
    $backend = $server->getBackend();
    $this->assertInstanceOf(MeilisearchBackend::class, $backend);
    $this->assertSame('http://stored.example:7700', $backend->getConfiguration()['url'], 'The form sees the stored URL.');

    $form_state = new FormState();
    $form = $backend->buildConfigurationForm([], $form_state);
    $form_state->setValues(['api_key' => ''] + $backend->getConfiguration());
    $backend->validateConfigurationForm($form, $form_state);
    $this->assertSame([], $form_state->getErrors(), 'The overridden URL keeps the stored key.');
    $this->assertSame(getenv('MEILISEARCH_TEST_URL'), $form_state->getValue('url'));
    $this->assertSame([], $requests->getArrayCopy(), 'Nothing is sent to the stored URL.');
  }

  /**
   * The server edit form keeps the stored key for its host only.
   *
   * The form rebuilds the backend from the submitted values before it is
   * validated and submitted, so this goes through the whole form.
   */
  public function testServerFormKeepsTheStoredKeyForItsHost(): void {
    // Let the form manage the URL and the key: drop their overrides.
    $server_config = &$GLOBALS['config']['search_api.server.meilisearch_test_server']['backend_config'];
    unset($server_config['url'], $server_config['api_key']);
    // Submits the form over the stored settings, and returns the form errors,
    // the saved URL and key, and the intercepted requests.
    $submit = function (string $url, string $key = ''): array {
      \Drupal::configFactory()->getEditable('search_api.server.meilisearch_test_server')
        ->set('backend_config.url', 'http://search.example/meilisearch')
        ->set('backend_config.api_key', 'stored-secret')
        ->save();
      $requests = $this->interceptRequestsTo(['search.example', 'other.example']);
      $form_state = $this->submitServerForm(['url' => $url, 'api_key' => $key]);
      $saved = \Drupal::configFactory()->getEditable('search_api.server.meilisearch_test_server')->get('backend_config');
      return [$form_state->getErrors(), [$saved['url'], $saved['api_key']], array_unique($requests->getArrayCopy())];
    };

    [$errors, $saved, $requests] = $submit('https://other.example');
    $this->assertArrayHasKey('backend_config][api_key', $errors, 'A new host needs the key again.');
    $this->assertSame(['http://search.example/meilisearch', 'stored-secret'], $saved, 'Nothing is saved.');
    $this->assertSame([], $requests, 'Nothing is sent to the new host.');

    foreach (['http://search.example', 'http://SEARCH.example:80/meilisearch/', 'http://search.example/other'] as $url) {
      [$errors, $saved, $requests] = $submit($url);
      $this->assertSame([], $errors, "$url keeps the stored key.");
      $this->assertSame('stored-secret', $saved[1], "$url keeps the stored key.");
      $this->assertSame(['http://search.example:80 Bearer stored-secret'], $requests);
    }

    [$errors, $saved, $requests] = $submit('https://other.example', 'new-secret');
    $this->assertSame([], $errors, 'A key entered with the new URL is accepted.');
    $this->assertSame(['https://other.example', 'new-secret'], $saved);
    $this->assertSame(['https://other.example:443 Bearer new-secret'], $requests);

    // A URL set in settings.php is the one the stored key is used with.
    $server_config['url'] = getenv('MEILISEARCH_TEST_URL');
    [$errors, $saved, $requests] = $submit('https://other.example');
    $this->assertSame([], $errors, 'The overridden URL keeps the stored key.');
    $this->assertSame(['http://search.example/meilisearch', 'stored-secret'], $saved);
    $this->assertSame([], $requests);
  }

  /**
   * A new server has no stored key to keep or to protect.
   */
  public function testServerFormCreatesServer(): void {
    $requests = $this->interceptRequestsTo(['search.example']);
    $form_object = \Drupal::entityTypeManager()->getFormObject('search_api_server', 'default');
    $form_object->setEntity(Server::create(['backend' => 'meilisearch']));
    $form_state = (new FormState())->setValues([
      'name' => 'New server',
      'id' => 'new_server',
      'backend' => 'meilisearch',
      'backend_config' => ['url' => 'http://search.example', 'api_key' => 'new-secret'],
      'op' => 'Save',
    ]);
    \Drupal::formBuilder()->submitForm($form_object, $form_state);
    $this->assertSame([], $form_state->getErrors());
    $config = \Drupal::configFactory()->getEditable('search_api.server.new_server')->get('backend_config');
    $this->assertSame(['http://search.example', 'new-secret'], [$config['url'], $config['api_key']]);
    $this->assertContains('http://search.example:80 Bearer new-secret', $requests->getArrayCopy());
  }

  /**
   * Submits the edit form of the test server, as loaded by its route.
   *
   * @param array<string, string> $backend_config
   *   The submitted backend settings; the others keep their form defaults.
   */
  protected function submitServerForm(array $backend_config): FormStateInterface {
    \Drupal::configFactory()->reset();
    $storage = \Drupal::entityTypeManager()->getStorage('search_api_server');
    $storage->resetCache();
    $server = $storage->loadOverrideFree('meilisearch_test_server');
    $this->assertInstanceOf(Server::class, $server);
    $form_object = \Drupal::entityTypeManager()->getFormObject('search_api_server', 'edit');
    $form_object->setEntity($server);
    $form_state = (new FormState())->setValues(['backend_config' => $backend_config, 'op' => 'Save']);
    \Drupal::formBuilder()->submitForm($form_object, $form_state);
    return $form_state;
  }

  /**
   * Answers the requests sent to some hosts and records them.
   *
   * Applies to the servers loaded afterwards.
   *
   * @param string[] $hosts
   *   The hosts whose requests never leave the test.
   *
   * @return \ArrayObject<int, string>
   *   Receives the origin and Authorization header of each intercepted request.
   */
  protected function interceptRequestsTo(array $hosts): \ArrayObject {
    /** @var \ArrayObject<int, string> $requests */
    $requests = new \ArrayObject();
    $stack = HandlerStack::create();
    $stack->push(fn(callable $handler) => function (RequestInterface $request, array $options) use ($handler, $hosts, $requests) {
      $uri = $request->getUri();
      if (!in_array($uri->getHost(), $hosts, TRUE)) {
        return $handler($request, $options);
      }
      $port = $uri->getPort() ?? ($uri->getScheme() === 'https' ? 443 : 80);
      $requests[] = sprintf('%s://%s:%d %s', $uri->getScheme(), $uri->getHost(), $port, $request->getHeaderLine('Authorization'));
      if (!str_ends_with($uri->getPath(), '/version')) {
        // Saving a server sets up its indexes: fail as an unavailable host.
        return Create::promiseFor(new Response(503, ['Content-Type' => 'application/json'], '{"message":"Intercepted.","code":"intercepted","type":"system","link":""}'));
      }
      return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], '{"pkgVersion":"1.54.0"}'));
    });
    $this->container->set('meilisearch.api_factory', new MeilisearchApiFactory(new ClientFactory($stack)));
    return $requests;
  }

  /**
   * Settings from settings.php are read-only in the form and never saved.
   */
  public function testOverriddenSettingsAreReadOnly(): void {
    $backend = $this->meilisearchBackend();
    $stored = \Drupal::configFactory()->getEditable('search_api.server.meilisearch_test_server')->get('backend_config');

    $form_state = new FormState();
    $form = $backend->buildConfigurationForm([], $form_state);
    foreach (['url', 'api_key', 'index_prefix'] as $key) {
      $this->assertTrue($form[$key]['#disabled'], "$key is read-only.");
      $this->assertStringContainsString('settings.php', (string) $form[$key]['#description']);
    }
    $this->assertSame(getenv('MEILISEARCH_TEST_URL'), $form['url']['#default_value'], 'The effective URL is shown.');
    $this->assertArrayNotHasKey('#default_value', $form['api_key'], 'The key is never sent to the browser.');
    $this->assertArrayNotHasKey('#disabled', $form['search_mode']);

    $form_state->setValues([
      'url' => 'http://ignored.example:7700',
      'api_key' => 'ignored-key',
      'index_prefix' => 'ignored_',
    ] + $backend->getConfiguration());
    $backend->submitConfigurationForm($form, $form_state);
    $configuration = $backend->getConfiguration();
    foreach (['url', 'api_key', 'index_prefix'] as $key) {
      $this->assertSame($stored[$key], $configuration[$key], "$key keeps its stored value.");
    }
  }

  /**
   * The server page shows the connection status and document counts.
   */
  public function testViewSettingsShowsStatusAndDocumentCounts(): void {
    $info = $this->viewSettingsByLabel();
    $this->assertSame('Connected', (string) $info['Connection']['info']);
    $this->assertStringContainsString('0 documents', (string) $info['Index <em class="placeholder">Test index</em>']['info']);

    $count = $this->indexItems($this->indexId);
    $info = $this->viewSettingsByLabel();
    $this->assertStringContainsString("$count documents", (string) $info['Index <em class="placeholder">Test index</em>']['info']);
    $this->assertSame('url, api_key, index_prefix', $info['Set in settings.php']['info']);
  }

  /**
   * Returns the server page rows keyed by label.
   *
   * @return array<string, array<string, mixed>>
   *   The rows.
   */
  protected function viewSettingsByLabel(): array {
    \Drupal::entityTypeManager()->getStorage('search_api_server')->resetCache();
    $rows = [];
    foreach ($this->meilisearchBackend()->viewSettings() as $row) {
      $rows[(string) $row['label']] = $row;
    }
    return $rows;
  }

  /**
   * A key with the actions the server form asks for is enough for everything.
   */
  public function testDocumentedKeyActionsAreSufficient(): void {
    $server_config = &$GLOBALS['config']['search_api.server.meilisearch_test_server']['backend_config'];
    $reset = function (): void {
      \Drupal::configFactory()->reset();
      \Drupal::entityTypeManager()->getStorage('search_api_server')->resetCache();
      \Drupal::entityTypeManager()->getStorage('search_api_index')->resetCache();
    };
    // The form only describes the key when settings.php does not set it.
    $master_key = $server_config['api_key'];
    unset($server_config['api_key']);
    $reset();
    $form = $this->meilisearchBackend()->buildConfigurationForm([], new FormState());
    preg_match_all('~<em>([^<]+)</em>~', (string) $form['api_key']['#description'], $matches);
    $actions = $matches[1];
    $this->assertContains('search', $actions);

    $index = Index::load($this->indexId);
    $uid = $this->meilisearchBackend()->getIndexUid($index);
    $client = $this->meilisearchClient();
    // Start without the index, so the key also has to create it.
    $client->waitForTask($client->deleteIndex($uid)['taskUid']);
    // Not scoped to the test prefix: recent Meilisearch versions refuse an
    // index-scoped key with the global "version" action.
    $key = $client->createKey(['actions' => $actions, 'indexes' => ['*'], 'expiresAt' => NULL]);

    try {
      $server_config['api_key'] = (string) $key->getKey();
      $reset();

      $count = $this->indexItems($this->indexId);
      $this->assertGreaterThan(0, $count);
      $this->assertGreaterThan(0, Index::load($this->indexId)->query()->keys('foo')->execute()->getResultCount());

      $info = $this->viewSettingsByLabel();
      $this->assertSame('Connected', (string) $info['Connection']['info']);
      $row = $info['Index <em class="placeholder">Test index</em>'];
      $this->assertSame("$uid: $count documents", (string) $row['info']);
      $this->assertArrayNotHasKey('status', $row, 'The index row is not a warning.');
    }
    finally {
      $client->deleteKey((string) $key->getUid());
      $server_config['api_key'] = $master_key;
    }
  }

  /**
   * Results expose how long Meilisearch took.
   */
  public function testResultsExposeProcessingTime(): void {
    $this->indexItems($this->indexId);
    $results = Index::load($this->indexId)->query()->keys('foo')->execute();
    $this->assertIsInt($results->getExtraData('meilisearch_processing_time_ms'));
  }

  /**
   * Changing the index prefix sets up the new indexes and re-indexes.
   */
  public function testChangingThePrefixSetsUpNewIndexes(): void {
    $this->indexItems($this->indexId);
    // Let the form change the prefix: drop its override and cached copies.
    unset($GLOBALS['config']['search_api.server.meilisearch_test_server']['backend_config']['index_prefix']);
    \Drupal::configFactory()->reset();
    \Drupal::entityTypeManager()->getStorage('search_api_server')->resetCache();
    $server = Server::load('meilisearch_test_server');
    $server->setBackendConfig(['index_prefix' => $this->meilisearchPrefix . 'moved_'] + $server->getBackendConfig());
    $server->save();

    $uid = $this->meilisearchPrefix . 'moved_' . $this->indexId;
    $settings = $this->meilisearchClient()->index($uid)->getSettings();
    $this->assertContains('search_api_language', $settings['filterableAttributes']);
    $this->assertSame(0, Index::load($this->indexId)->getTrackerInstance()->getIndexedItemsCount(), 'Items are queued for re-indexing.');
  }

  /**
   * Saving a new URL that is not reachable yet still queues the re-index.
   */
  public function testSavingAnUnreachableUrlQueuesReindexing(): void {
    $this->indexItems($this->indexId);
    $tracker = Index::load($this->indexId)->getTrackerInstance();
    $this->assertSame(0, $tracker->getRemainingItemsCount());
    // Let the form change the URL: drop its override and cached copies.
    unset($GLOBALS['config']['search_api.server.meilisearch_test_server']['backend_config']['url']);
    \Drupal::configFactory()->reset();
    \Drupal::entityTypeManager()->getStorage('search_api_server')->resetCache();
    $server = Server::load('meilisearch_test_server');
    $server->setBackendConfig(['url' => 'http://127.0.0.1:1'] + $server->getBackendConfig());
    $server->save();

    $tracker = Index::load($this->indexId)->getTrackerInstance();
    $this->assertSame($tracker->getTotalItemsCount(), $tracker->getRemainingItemsCount(), 'Items are queued for re-indexing.');
    $this->assertSame(1, $this->countServerTasks('addIndex'), 'Setting up the index is retried later.');
  }

  /**
   * Saving a new result limit while Meilisearch is down retries it later.
   */
  public function testSavingTheResultLimitWhileUnreachableIsRetried(): void {
    // The instance went away; the stored connection settings do not change.
    $GLOBALS['config']['search_api.server.meilisearch_test_server']['backend_config']['url'] = 'http://127.0.0.1:1';
    \Drupal::configFactory()->reset();
    \Drupal::entityTypeManager()->getStorage('search_api_server')->resetCache();
    $server = Server::load('meilisearch_test_server');
    $server->setBackendConfig(['max_total_hits' => 500] + $server->getBackendConfig());
    $server->save();

    $this->assertSame(1, $this->countServerTasks('updateIndex'), 'Pushing the settings is retried later.');
  }

  /**
   * A new API key reaches the same indexes: nothing has to be re-indexed.
   */
  public function testRotatingTheApiKeyDoesNotReindex(): void {
    $this->indexItems($this->indexId);
    $uid = $this->meilisearchBackend()->getIndexUid(Index::load($this->indexId));
    $settings_updates = $this->countSettingsUpdates($uid);
    $client = $this->meilisearchClient();
    $key = $client->createKey(['actions' => ['*'], 'indexes' => ['*'], 'expiresAt' => NULL]);

    try {
      // Let the form change the key: drop its override and cached copies.
      unset($GLOBALS['config']['search_api.server.meilisearch_test_server']['backend_config']['api_key']);
      \Drupal::configFactory()->reset();
      \Drupal::entityTypeManager()->getStorage('search_api_server')->resetCache();
      $server = Server::load('meilisearch_test_server');
      $server->setBackendConfig(['api_key' => (string) $key->getKey()] + $server->getBackendConfig());
      $server->save();

      $tracker = Index::load($this->indexId)->getTrackerInstance();
      $this->assertGreaterThan(0, $tracker->getIndexedItemsCount());
      $this->assertSame(0, $tracker->getRemainingItemsCount(), 'Items are not queued for re-indexing.');
      $this->assertSame($settings_updates + 1, $this->countSettingsUpdates($uid), 'The settings are sent with the new key.');
      $this->assertSame(0, $this->countServerTasks('updateIndex'), 'Nothing failed with the new key.');
    }
    finally {
      $client->deleteKey((string) $key->getUid());
    }
  }

  /**
   * Returns how many Search API tasks of a type wait for the test server.
   */
  protected function countServerTasks(string $type): int {
    return (int) \Drupal::service('search_api.task_manager')->getTasksCount([
      'type' => $type,
      'server_id' => 'meilisearch_test_server',
      'index_id' => $this->indexId,
    ]);
  }

  /**
   * Indexing recreates a Meilisearch index deleted behind Drupal's back.
   *
   * This happens when an environment's URL is overridden in settings.php and
   * points at a fresh instance.
   */
  public function testIndexingSetsUpMissingIndexes(): void {
    $index = Index::load($this->indexId);
    $uid = $this->meilisearchBackend()->getIndexUid($index);
    $client = $this->meilisearchClient();
    $client->waitForTask($client->deleteIndex($uid)['taskUid']);

    $this->indexItems($this->indexId);
    $settings = $client->index($uid)->getSettings();
    $this->assertContains('search_api_language', $settings['filterableAttributes']);
    $this->assertSame(2, Index::load($this->indexId)->query()->addCondition('type', 'article')->execute()->getResultCount(), 'Filters work on the recreated index.');
  }

  /**
   * Saving an index sets up a missing Meilisearch index like indexing does.
   *
   * A settings update alone makes Meilisearch create the index with its
   * default ranking rules, where "sort" comes after the relevancy rules.
   */
  public function testSavingAnIndexSetsUpMissingIndexes(): void {
    $index = Index::load($this->indexId);
    $uid = $this->meilisearchBackend()->getIndexUid($index);
    $client = $this->meilisearchClient();
    $client->waitForTask($client->deleteIndex($uid)['taskUid']);
    $settings_updates = $this->countSettingsUpdates($uid);

    $index->set('description', 'Saved before anything was indexed.');
    $index->save();

    $settings = $client->index($uid)->getSettings();
    $this->assertSame('sort', $settings['rankingRules'][0], 'Explicit sorts are strict on the recreated index.');
    $this->assertContains('search_api_language', $settings['filterableAttributes']);
    $this->assertSame($settings_updates + 1, $this->countSettingsUpdates($uid), 'The settings are sent once.');
  }

  /**
   * A new result limit sets up a missing Meilisearch index before pushing it.
   */
  public function testChangingTheResultLimitSetsUpMissingIndexes(): void {
    $uid = $this->meilisearchBackend()->getIndexUid(Index::load($this->indexId));
    $client = $this->meilisearchClient();
    $client->waitForTask($client->deleteIndex($uid)['taskUid']);

    $server = Server::load('meilisearch_test_server');
    $server->setBackendConfig(['max_total_hits' => 500] + $server->getBackendConfig());
    $server->save();

    $settings = $client->index($uid)->getSettings();
    $this->assertSame('sort', $settings['rankingRules'][0], 'Explicit sorts are strict on the recreated index.');
    $this->assertSame(500, $settings['pagination']['maxTotalHits']);
  }

  /**
   * Setting up an index that a failed attempt left bare puts "sort" first.
   *
   * Creating the index can succeed and sending its settings fail; the retry
   * then finds the index already there, with the server's default rules.
   */
  public function testSettingUpAnEmptyIndexWithDefaultRulesPutsSortFirst(): void {
    $index = Index::load($this->indexId);
    $uid = $this->meilisearchBackend()->getIndexUid($index);
    $client = $this->meilisearchClient();
    $client->waitForTask($client->deleteIndex($uid)['taskUid']);
    $client->waitForTask($client->createIndex($uid, ['primaryKey' => 'search_api_id'])['taskUid']);

    $this->meilisearchBackend()->addIndex($index);
    $settings = $client->index($uid)->getSettings();
    $this->assertSame('sort', $settings['rankingRules'][0], 'Explicit sorts are strict on the bare index.');
    $this->assertContains('search_api_language', $settings['filterableAttributes']);
    $this->assertSame([], array_diff($this->testIndexUids(), [$uid]), 'No other index is left behind.');
  }

  /**
   * An existing index keeps ranking rules customised in Meilisearch.
   */
  public function testSettingUpAnIndexKeepsCustomRankingRules(): void {
    $index = Index::load($this->indexId);
    $uid = $this->meilisearchBackend()->getIndexUid($index);
    $client = $this->meilisearchClient();
    $custom = ['words', 'sort', 'typo', 'proximity', 'exactness'];
    $client->waitForTask($client->index($uid)->updateRankingRules($custom)['taskUid']);

    $this->meilisearchBackend()->addIndex($index);
    $this->assertSame($custom, $client->index($uid)->getRankingRules());
    $this->assertSame([$uid], $this->testIndexUids(), 'The scratch index is deleted.');
  }

  /**
   * An existing index with documents keeps its rules, even the default ones.
   */
  public function testSettingUpAnIndexWithDocumentsKeepsItsRankingRules(): void {
    $index = Index::load($this->indexId);
    $uid = $this->meilisearchBackend()->getIndexUid($index);
    $client = $this->meilisearchClient();
    $client->waitForTask($client->deleteIndex($uid)['taskUid']);
    $client->waitForTask($client->createIndex($uid, ['primaryKey' => 'search_api_id'])['taskUid']);
    $defaults = $client->index($uid)->getRankingRules();
    $client->waitForTask($client->index($uid)->addDocuments([['search_api_id' => 'someone_else']])['taskUid']);
    $requests = $this->recordRequests();

    $this->meilisearchBackend()->addIndex($index);
    $this->assertSame($defaults, $client->index($uid)->getRankingRules());
    $this->assertSame([], $this->scratchIndexRequests($requests, $uid), 'No scratch index is created.');
  }

  /**
   * Creating an index, or setting up one with the module's rules, is cheap.
   *
   * The server's default rules are only read for an existing, empty index
   * whose rules are not the module's.
   */
  public function testSettingUpAnIndexOnlyReadsTheDefaultsWhenNeeded(): void {
    $index = Index::load($this->indexId);
    $uid = $this->meilisearchBackend()->getIndexUid($index);
    $client = $this->meilisearchClient();
    $requests = $this->recordRequests();

    // The index set up by the test already has the module's rules.
    $this->meilisearchBackend()->addIndex($index);
    $this->assertSame('sort', $client->index($uid)->getRankingRules()[0]);
    $this->assertSame([], $this->scratchIndexRequests($requests, $uid));

    $client->waitForTask($client->deleteIndex($uid)['taskUid']);
    $requests->exchangeArray([]);
    $this->meilisearchBackend()->addIndex($index);
    $this->assertSame('sort', $client->index($uid)->getRankingRules()[0]);
    $this->assertSame([], array_values(preg_grep('~^GET /indexes/~', $requests->getArrayCopy())), 'A new index is not inspected.');
    $this->assertSame([], $this->scratchIndexRequests($requests, $uid));
  }

  /**
   * A failure while reading the default rules leaves the index's alone.
   *
   * Setting up the index still succeeds, and the scratch index is deleted.
   */
  public function testFailingToReadTheDefaultRankingRulesLeavesThemUnchanged(): void {
    $index = Index::load($this->indexId);
    $uid = $this->meilisearchBackend()->getIndexUid($index);
    $client = $this->meilisearchClient();
    $client->waitForTask($client->deleteIndex($uid)['taskUid']);
    $client->waitForTask($client->createIndex($uid, ['primaryKey' => 'search_api_id'])['taskUid']);
    $defaults = $client->index($uid)->getRankingRules();
    $log = $this->captureMeilisearchLog();
    $requests = $this->recordRequests(fn(RequestInterface $request) => $request->getMethod() === 'GET'
      && preg_match('~^/indexes/[^/]+__defaults_[0-9a-f]+/settings$~', $request->getUri()->getPath()));

    $this->meilisearchBackend()->addIndex($index);
    $settings = $client->index($uid)->getSettings();
    $this->assertSame($defaults, $settings['rankingRules']);
    $this->assertContains('search_api_language', $settings['filterableAttributes'], 'The other settings are sent.');
    $this->assertNotEmpty(preg_grep('~^DELETE /indexes/~', $this->scratchIndexRequests($requests, $uid)), 'The scratch index was created.');
    $this->assertSame([$uid], $this->testIndexUids(), 'The scratch index is deleted.');
    $this->assertStringContainsString("Could not compare the ranking rules of Meilisearch index $uid", implode("\n", $log[RfcLogLevel::WARNING] ?? []));
  }

  /**
   * The scratch index is deleted even when waiting for its creation fails.
   */
  public function testScratchIndexIsDeletedWhenWaitingForItFails(): void {
    $index = Index::load($this->indexId);
    $uid = $this->meilisearchBackend()->getIndexUid($index);
    $client = $this->meilisearchClient();
    $client->waitForTask($client->deleteIndex($uid)['taskUid']);
    $client->waitForTask($client->createIndex($uid, ['primaryKey' => 'search_api_id'])['taskUid']);
    $defaults = $client->index($uid)->getRankingRules();
    // Fails the first poll of a task after the scratch index is requested.
    $scratch_requested = FALSE;
    $requests = $this->recordRequests(function (RequestInterface $request) use (&$scratch_requested): bool {
      if ($request->getMethod() === 'POST' && $request->getUri()->getPath() === '/indexes' && str_contains((string) $request->getBody(), '__defaults_')) {
        $scratch_requested = TRUE;
        return FALSE;
      }
      if ($scratch_requested && $request->getMethod() === 'GET' && str_starts_with($request->getUri()->getPath(), '/tasks/')) {
        $scratch_requested = FALSE;
        return TRUE;
      }
      return FALSE;
    });

    $this->meilisearchBackend()->addIndex($index);
    $this->assertSame($defaults, $client->index($uid)->getRankingRules());
    $this->assertNotEmpty(preg_grep('~^DELETE /indexes/~', $this->scratchIndexRequests($requests, $uid)));
    $this->assertSame([$uid], $this->testIndexUids(), 'The scratch index is deleted.');
  }

  /**
   * A key limited to the index prefix can read the server's default rules.
   */
  public function testDefaultRulesAreReadWithKeyScopedToThePrefix(): void {
    $index = Index::load($this->indexId);
    $uid = $this->meilisearchBackend()->getIndexUid($index);
    $client = $this->meilisearchClient();
    $client->waitForTask($client->deleteIndex($uid)['taskUid']);
    $client->waitForTask($client->createIndex($uid, ['primaryKey' => 'search_api_id'])['taskUid']);

    $this->withKeyFor([$this->meilisearchPrefix . '*'], function () use ($index, $uid, $client): void {
      $this->meilisearchBackend()->addIndex($index);
      $this->assertSame('sort', $client->index($uid)->getRankingRules()[0]);
    });
    $this->assertSame([$uid], $this->testIndexUids(), 'The scratch index is deleted.');
  }

  /**
   * A key limited to the index itself cannot read the default rules.
   *
   * The rules are then left alone and setting up the index succeeds.
   */
  public function testDefaultRulesCannotBeReadWithKeyScopedToTheIndex(): void {
    $index = Index::load($this->indexId);
    $uid = $this->meilisearchBackend()->getIndexUid($index);
    $client = $this->meilisearchClient();
    $client->waitForTask($client->deleteIndex($uid)['taskUid']);
    $client->waitForTask($client->createIndex($uid, ['primaryKey' => 'search_api_id'])['taskUid']);
    $defaults = $client->index($uid)->getRankingRules();
    $log = $this->captureMeilisearchLog();

    $this->withKeyFor([$uid], function () use ($index): void {
      $this->meilisearchBackend()->addIndex($index);
    });
    $settings = $client->index($uid)->getSettings();
    $this->assertSame($defaults, $settings['rankingRules']);
    $this->assertContains('search_api_language', $settings['filterableAttributes']);
    $this->assertSame([$uid], $this->testIndexUids());
    $this->assertStringContainsString("Could not compare the ranking rules of Meilisearch index $uid", implode("\n", $log[RfcLogLevel::WARNING] ?? []));
  }

  /**
   * Runs code with a key that has the module's actions on some indexes.
   *
   * @param string[] $indexes
   *   The index patterns of the key.
   * @param callable $callback
   *   The code to run.
   */
  protected function withKeyFor(array $indexes, callable $callback): void {
    $server_config = &$GLOBALS['config']['search_api.server.meilisearch_test_server']['backend_config'];
    $master_key = $server_config['api_key'];
    $reset = function (): void {
      \Drupal::configFactory()->reset();
      \Drupal::entityTypeManager()->getStorage('search_api_server')->resetCache();
      \Drupal::entityTypeManager()->getStorage('search_api_index')->resetCache();
    };
    // Without "version" and "search", which are not needed here: recent
    // Meilisearch versions refuse an index-scoped key with global actions.
    $key = $this->meilisearchClient()->createKey([
      'actions' => ['documents.*', 'indexes.*', 'settings.*', 'stats.get', 'tasks.get'],
      'indexes' => $indexes,
      'expiresAt' => NULL,
    ]);
    try {
      $server_config['api_key'] = (string) $key->getKey();
      $reset();
      $callback();
    }
    finally {
      $this->meilisearchClient()->deleteKey((string) $key->getUid());
      $server_config['api_key'] = $master_key;
      $reset();
    }
  }

  /**
   * Records the requests sent to Meilisearch, and fails some of them.
   *
   * Applies to the servers loaded afterwards.
   *
   * @param callable|null $fail
   *   Receives each request and returns TRUE to answer it with an error.
   *
   * @return \ArrayObject<int, string>
   *   Receives the method and path of each request.
   */
  protected function recordRequests(?callable $fail = NULL): \ArrayObject {
    /** @var \ArrayObject<int, string> $requests */
    $requests = new \ArrayObject();
    $stack = HandlerStack::create();
    $stack->push(fn(callable $handler) => function (RequestInterface $request, array $options) use ($handler, $fail, $requests) {
      $requests[] = $request->getMethod() . ' ' . $request->getUri()->getPath();
      if ($fail !== NULL && $fail($request)) {
        return Create::promiseFor(new Response(500, ['Content-Type' => 'application/json'], '{"message":"Injected failure.","code":"internal","type":"internal","link":""}'));
      }
      return $handler($request, $options);
    });
    $this->container->set('meilisearch.api_factory', new MeilisearchApiFactory(new ClientFactory($stack)));
    \Drupal::entityTypeManager()->getStorage('search_api_server')->resetCache();
    return $requests;
  }

  /**
   * Returns the recorded requests about a scratch index of an index.
   *
   * @param \ArrayObject<int, string> $requests
   *   The requests recorded by recordRequests().
   * @param string $uid
   *   The index UID.
   *
   * @return string[]
   *   The matching requests.
   */
  protected function scratchIndexRequests(\ArrayObject $requests, string $uid): array {
    return array_values(preg_grep('~^\w+ /indexes/' . preg_quote($uid, '~') . '__defaults_~', $requests->getArrayCopy()));
  }

  /**
   * Collects the messages logged on the meilisearch channel.
   *
   * @return \ArrayObject<int, string[]>
   *   Receives the messages, with placeholders replaced, keyed by level.
   */
  protected function captureMeilisearchLog(): \ArrayObject {
    /** @var \ArrayObject<int, string[]> $messages */
    $messages = new \ArrayObject();
    $logger = new class($messages) implements LoggerInterface {
      use LoggerTrait;

      public function __construct(protected \ArrayObject $messages) {}

      /**
       * {@inheritdoc}
       */
      public function log($level, string|\Stringable $message, array $context = []): void {
        if (($context['channel'] ?? NULL) === 'meilisearch') {
          $list = $this->messages[$level] ?? [];
          $list[] = strtr((string) $message, array_filter($context, fn($value, $key) => str_starts_with((string) $key, '@') && is_scalar($value), ARRAY_FILTER_USE_BOTH));
          $this->messages[$level] = $list;
        }
      }

    };
    \Drupal::service('logger.factory')->addLogger($logger);
    return $messages;
  }

  /**
   * Returns how many settings updates Meilisearch received for an index.
   */
  protected function countSettingsUpdates(string $uid): int {
    $query = (new TasksQuery())->setIndexUids([$uid])->setTypes(['settingsUpdate'])->setLimit(1);
    return $this->meilisearchClient()->getTasks($query)->getTotal();
  }

  /**
   * Saving a read-only index leaves the Meilisearch settings alone.
   */
  public function testReadOnlyIndexesKeepTheirSettings(): void {
    $index = Index::load($this->indexId);
    $uid = $this->meilisearchBackend()->getIndexUid($index);
    $client = $this->meilisearchClient();
    $client->waitForTask($client->index($uid)->updateFilterableAttributes(['owned_elsewhere'])['taskUid']);

    $index->set('read_only', TRUE);
    $index->save();
    $this->assertSame(['owned_elsewhere'], $client->index($uid)->getFilterableAttributes());
  }

  /**
   * Meilisearch must rank facet values by count before truncating them.
   */
  public function testFacetValuesAreRankedByCount(): void {
    $uid = $this->meilisearchBackend()->getIndexUid(Index::load($this->indexId));
    $faceting = $this->meilisearchClient()->index($uid)->getSettings()['faceting'];
    $this->assertSame(['*' => 'count'], $faceting['sortFacetValuesBy']);
  }

  /**
   * The search parameters hook also applies to facet count queries.
   */
  public function testSearchParamsHookAppliesToFacetQueries(): void {
    $this->indexItems($this->indexId);
    \Drupal::state()->set('meilisearch_test.extra_filter', 'type = "item"');

    $query = Index::load($this->indexId)->query();
    $query->createAndAddConditionGroup('OR', ['facet:category'])->addCondition('category', 'item_category');
    $query->setOption('search_api_facets', [
      'category' => ['field' => 'category', 'limit' => 0, 'min_count' => 1, 'missing' => FALSE, 'operator' => 'or'],
    ]);
    $results = $query->execute();

    $this->assertSame(2, $results->getResultCount());
    $this->assertSame([['count' => 2, 'filter' => '"item_category"']], $results->getExtraData('search_api_facets')['category']);
  }

}
