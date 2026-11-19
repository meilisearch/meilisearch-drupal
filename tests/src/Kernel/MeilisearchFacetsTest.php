<?php

declare(strict_types=1);

namespace Drupal\Tests\meilisearch\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Item\Field;
use Drupal\Tests\search_api\Functional\ExampleContentTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests facets on field types whose values Meilisearch writes its own way.
 *
 * @group meilisearch
 */
#[RunTestsInSeparateProcesses]
class MeilisearchFacetsTest extends KernelTestBase {

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

    FieldStorageConfig::create([
      'field_name' => 'flag',
      'entity_type' => 'entity_test_mulrev_changed',
      'type' => 'boolean',
    ])->save();
    FieldConfig::create([
      'field_name' => 'flag',
      'entity_type' => 'entity_test_mulrev_changed',
      'bundle' => 'item',
    ])->save();

    $index = Index::load('meilisearch_test_index');
    $index->addField((new Field($index, 'flag'))
      ->setType('boolean')
      ->setDatasourceId('entity:entity_test_mulrev_changed')
      ->setPropertyPath('flag')
      ->setLabel('Flag'));
    $index->save();

    $this->addTestEntity(1, ['name' => 'First on', 'type' => 'item', 'flag' => TRUE]);
    $this->addTestEntity(2, ['name' => 'Second on', 'type' => 'item', 'flag' => TRUE]);
    $this->addTestEntity(3, ['name' => 'Off', 'type' => 'item', 'flag' => FALSE]);
    $this->indexItems('meilisearch_test_index');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->tearDownMeilisearch();
    parent::tearDown();
  }

  /**
   * Boolean facet values are "1" and "0", as with other Search API backends.
   */
  public function testBooleanFacetValues(): void {
    $query = Index::load('meilisearch_test_index')->query();
    $query->setOption('search_api_facets', [
      'flag' => ['field' => 'flag', 'limit' => 0, 'min_count' => 1, 'missing' => FALSE],
    ]);
    $facets = $query->execute()->getExtraData('search_api_facets')['flag'];

    $this->assertSame([
      ['count' => 2, 'filter' => '"1"'],
      ['count' => 1, 'filter' => '"0"'],
    ], $facets);
  }

  /**
   * Filtering on a facet value keeps the other value listed, once.
   */
  public function testActiveBooleanFacetWithEmptyValues(): void {
    $query = Index::load('meilisearch_test_index')->query();
    // The value the Facets module reads back from the URL.
    $query->createAndAddConditionGroup('AND', ['facet:flag'])->addCondition('flag', '1');
    $query->setOption('search_api_facets', [
      'flag' => ['field' => 'flag', 'limit' => 0, 'min_count' => 0, 'missing' => FALSE],
    ]);
    $results = $query->execute();

    $this->assertSame($this->getItemIds([1, 2]), array_keys($results->getResultItems()));
    $this->assertSame([
      ['count' => 2, 'filter' => '"1"'],
      ['count' => 0, 'filter' => '"0"'],
    ], $results->getExtraData('search_api_facets')['flag']);
  }

}
