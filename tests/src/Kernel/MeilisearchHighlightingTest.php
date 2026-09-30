<?php

declare(strict_types=1);

namespace Drupal\Tests\meilisearch\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\search_api\Entity\Index;
use Drupal\Tests\search_api\Functional\ExampleContentTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Meilisearch highlighting processor.
 *
 * @group meilisearch
 */
#[RunTestsInSeparateProcesses]
class MeilisearchHighlightingTest extends KernelTestBase {

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

    $index = Index::load('meilisearch_test_index');
    $processor = \Drupal::getContainer()->get('search_api.plugin_helper')
      ->createProcessorPlugin($index, 'meilisearch_highlighting', [
        'fields' => ['body'],
        'pre_tag' => '<strong>',
        'post_tag' => '</strong>',
        'crop_length' => 6,
        'crop_marker' => '…',
      ]);
    $index->addProcessor($processor)->save();

    $this->addTestEntity(1, [
      'name' => 'Item',
      'type' => 'item',
      'body' => 'one two three <script>alert(1)</script> four five six seven eight nine ten eleven twelve wombat end',
    ]);
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
   * Results get a cropped, highlighted and escaped excerpt.
   */
  public function testExcerptIsHighlightedAndEscaped(): void {
    $results = Index::load('meilisearch_test_index')->query()->keys('wombat')->execute();
    $item = current($results->getResultItems());
    $excerpt = (string) $item->getExcerpt();

    $this->assertStringContainsString('<strong>wombat</strong>', $excerpt);
    $this->assertStringStartsWith('…', $excerpt, 'The body is cropped around the match.');
    $this->assertSame(['body' => [$excerpt]], $item->getExtraData('highlighted_fields'));

    $results = Index::load('meilisearch_test_index')->query()->keys('script')->execute();
    $excerpt = (string) current($results->getResultItems())->getExcerpt();
    $this->assertStringNotContainsString('<script>', $excerpt);
    $this->assertStringContainsString('&lt;script&gt;', $excerpt);
  }

}
