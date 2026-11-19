<?php

declare(strict_types=1);

namespace Drupal\Tests\meilisearch\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\ItemInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests boolean conditions on string and text fields against Meilisearch.
 *
 * A PHP boolean indexed in a string field is stored as "1" or "".
 *
 * @group meilisearch
 */
#[RunTestsInSeparateProcesses]
class MeilisearchBooleanFilterTest extends KernelTestBase {

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

    // Index the values directly: entity fields drop empty strings, but a
    // processor or a custom property can provide a PHP boolean.
    $index = Index::load('meilisearch_test_index');
    $items = [
      $this->createItem($index, 1, FALSE),
      $this->createItem($index, 2, TRUE),
      $this->createItem($index, 3, NULL),
    ];
    $items = array_combine(array_map(fn(ItemInterface $item) => $item->getId(), $items), $items);
    $index->getServerInstance()->indexItems($index, $items);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->tearDownMeilisearch();
    parent::tearDown();
  }

  /**
   * TRUE and FALSE match the items that store them, on string and text fields.
   *
   * @dataProvider fieldProvider
   */
  public function testBooleanConditions(string $field): void {
    $this->assertSame([1], $this->search($field, FALSE, '='));
    $this->assertSame([2], $this->search($field, TRUE, '='));
    // Like any "<>" condition, these also match the item without a value.
    $this->assertSame([2, 3], $this->search($field, FALSE, '<>'));
    $this->assertSame([1, 3], $this->search($field, TRUE, '<>'));
  }

  /**
   * Provides a string and a text field.
   */
  public static function fieldProvider(): array {
    return [
      'string' => ['category'],
      'text' => ['name'],
    ];
  }

  /**
   * Returns the entity IDs of the items matching a condition, in order.
   *
   * @return int[]
   *   Entity IDs.
   */
  protected function search(string $field, bool $value, string $operator): array {
    $query = Index::load('meilisearch_test_index')->query();
    $query->addCondition($field, $value, $operator);
    $ids = [];
    foreach (array_keys($query->execute()->getResultItems()) as $item_id) {
      preg_match('/\/(\d+):/', $item_id, $matches);
      $ids[] = (int) $matches[1];
    }
    sort($ids);
    return $ids;
  }

  /**
   * Creates an item whose "category" and "name" fields hold a value.
   *
   * A NULL value leaves both fields without values.
   */
  protected function createItem(IndexInterface $index, int $id, ?bool $value): ItemInterface {
    $item = \Drupal::getContainer()->get('search_api.fields_helper')
      ->createItem($index, "entity:entity_test_mulrev_changed/$id:en");
    $item->setLanguage('en');
    foreach (['category', 'name'] as $field_id) {
      $field = clone $index->getField($field_id);
      $field->setValues($value === NULL ? [] : [$value]);
      $item->setField($field_id, $field);
    }
    $item->setFieldsExtracted(TRUE);
    return $item;
  }

}
