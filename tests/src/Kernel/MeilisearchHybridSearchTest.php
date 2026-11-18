<?php

declare(strict_types=1);

namespace Drupal\Tests\meilisearch\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\IndexInterface;
use Drupal\Tests\search_api\Functional\ExampleContentTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the keyword, hybrid and semantic search modes.
 *
 * The index gets a "userProvided" embedder, which needs no external service:
 * documents and queries bring their own vectors. The backend never sends a
 * query vector, so the test module adds it through
 * hook_meilisearch_search_params_alter(). Meilisearch rejects a vector on a
 * query without the "hybrid" parameter, which makes it a check of its own.
 *
 * @group meilisearch
 */
#[RunTestsInSeparateProcesses]
class MeilisearchHybridSearchTest extends KernelTestBase {

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

    // Only the first item contains the keyword. Its vector is the furthest
    // from the query vector [0, 1] used by the tests.
    $this->addTestEntity(1, ['name' => 'Great deal', 'type' => 'item']);
    $this->addTestEntity(2, ['name' => 'Other thing', 'type' => 'item']);
    $this->addTestEntity(3, ['name' => 'Third thing', 'type' => 'article']);
    $this->indexItems('meilisearch_test_index');
    $vectors = array_combine($this->getItemIds([1, 2, 3]), [[1, 0], [0, 1], [0.6, 0.8]]);

    // With a "userProvided" embedder every document must bring its vector, so
    // the vectors are added before the embedder.
    $client = $this->meilisearchClient();
    $meilisearch_index = $client->index($this->meilisearchBackend()->getIndexUid('meilisearch_test_index'));
    $documents = [];
    foreach ($meilisearch_index->getDocuments()->getResults() as $document) {
      $documents[] = ['_vectors' => ['manual' => $vectors[$document['search_api_id']]]] + $document;
    }
    $tasks = [
      $meilisearch_index->addDocuments($documents),
      $meilisearch_index->updateSettings(['embedders' => ['manual' => ['source' => 'userProvided', 'dimensions' => 2]]]),
    ];
    foreach ($tasks as $task) {
      $this->assertSame('succeeded', $client->waitForTask($task['taskUid'])['status']);
    }

    \Drupal::state()->set('meilisearch_test.record_params', TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->tearDownMeilisearch();
    parent::tearDown();
  }

  /**
   * Returns the test index, its server searching in the given mode.
   *
   * @param string $mode
   *   The search mode: "keyword", "hybrid" or "semantic".
   * @param array $configuration
   *   More backend configuration.
   */
  protected function indexInMode(string $mode, array $configuration = []): IndexInterface {
    $index = Index::load('meilisearch_test_index');
    $backend = $index->getServerInstance()->getBackend();
    $backend->setConfiguration($configuration + ['search_mode' => $mode, 'embedder' => 'manual'] + $backend->getConfiguration());
    if ($mode !== 'keyword') {
      \Drupal::state()->set('meilisearch_test.vector', [0, 1]);
    }
    return $index;
  }

  /**
   * Returns the search parameters seen by the hook, keyed by kind of query.
   *
   * @return array[]
   *   Search parameters keyed by "main", "facet_values", "facet_all_values"
   *   or "facet_missing".
   */
  protected function recordedParams(): array {
    \Drupal::state()->resetCache();
    $params = [];
    foreach (\Drupal::state()->get('meilisearch_test.recorded_params', []) as $record) {
      $params[$record['context']['query']] = $record['params'];
    }
    return $params;
  }

  /**
   * Keyword mode sends no "hybrid" parameter, even with an embedder set.
   */
  public function testKeywordMode(): void {
    $results = $this->indexInMode('keyword')->query()->keys('great')->execute();

    $this->assertSame($this->getItemIds([1]), array_keys($results->getResultItems()));
    $this->assertArrayNotHasKey('hybrid', $this->recordedParams()['main']);
  }

  /**
   * Hybrid mode sends the embedder and the configured ratio, as a number.
   */
  public function testHybridMode(): void {
    // Settings overridden from the environment in settings.php are strings.
    $results = $this->indexInMode('hybrid', ['semantic_ratio' => '0.3'])->query()->keys('great')->execute();

    $this->assertSame(['embedder' => 'manual', 'semanticRatio' => 0.3], $this->recordedParams()['main']['hybrid']);
    $ids = array_keys($results->getResultItems());
    $this->assertContains($this->getItemIds([1])[0], $ids, 'The keyword match is found.');
    $this->assertContains($this->getItemIds([2])[0], $ids, 'The semantic match is found.');
  }

  /**
   * Semantic mode ignores the configured ratio and ranks by vector distance.
   */
  public function testSemanticMode(): void {
    $results = $this->indexInMode('semantic', ['semantic_ratio' => 0.3])->query()->keys('great')->execute();

    $this->assertSame(['embedder' => 'manual', 'semanticRatio' => 1.0], $this->recordedParams()['main']['hybrid']);
    $this->assertSame($this->getItemIds([2, 3, 1]), array_keys($results->getResultItems()));
  }

  /**
   * Without an embedder, the search stays a keyword search.
   */
  public function testModeWithoutEmbedder(): void {
    $index = $this->indexInMode('semantic', ['embedder' => '']);
    \Drupal::state()->delete('meilisearch_test.vector');
    $results = $index->query()->keys('great')->execute();

    $this->assertSame($this->getItemIds([1]), array_keys($results->getResultItems()));
    $this->assertArrayNotHasKey('hybrid', $this->recordedParams()['main']);
  }

  /**
   * Facet counts come from the same kind of search as the results.
   */
  public function testFacetQueriesAreHybrid(): void {
    $query = $this->indexInMode('semantic')->query()->keys('great');
    $query->createAndAddConditionGroup('OR', ['facet:type'])->addCondition('type', 'item');
    $query->setOption('search_api_facets', [
      'type' => ['field' => 'type', 'limit' => 0, 'min_count' => 0, 'missing' => TRUE, 'operator' => 'or'],
    ]);
    $results = $query->execute();

    $this->assertSame($this->getItemIds([2, 1]), array_keys($results->getResultItems()));
    // A keyword search for "great" would only count the first item.
    $this->assertSame([
      ['count' => 2, 'filter' => '"item"'],
      ['count' => 1, 'filter' => '"article"'],
      ['count' => 0, 'filter' => '!'],
    ], $results->getExtraData('search_api_facets')['type']);

    $params = $this->recordedParams();
    $hybrid = ['embedder' => 'manual', 'semanticRatio' => 1.0];
    $this->assertSame($hybrid, $params['main']['hybrid']);
    $this->assertSame($hybrid, $params['facet_values']['hybrid']);
    $this->assertSame($hybrid, $params['facet_missing']['hybrid']);
    $this->assertSame('great', $params['facet_values']['q']);
    $this->assertSame('great', $params['facet_missing']['q']);
    $this->assertArrayNotHasKey('hybrid', $params['facet_all_values'], 'Listing all the values of a field is not a search.');
    $this->assertArrayNotHasKey('q', $params['facet_all_values']);
  }

  /**
   * The server form refuses hybrid and semantic modes without an embedder.
   */
  public function testFormRequiresAnEmbedder(): void {
    $backend = $this->meilisearchBackend();
    $errors = function (string $mode, string $embedder) use ($backend): array {
      $form = [];
      $form_state = new FormState();
      $form_state->setValues(['search_mode' => $mode, 'embedder' => $embedder] + $backend->getConfiguration());
      $backend->validateConfigurationForm($form, $form_state);
      return array_keys($form_state->getErrors());
    };

    $this->assertSame(['embedder'], $errors('hybrid', ''));
    $this->assertSame(['embedder'], $errors('semantic', ' '));
    $this->assertSame([], $errors('keyword', ''));
    $this->assertSame([], $errors('hybrid', 'manual'));
  }

}
