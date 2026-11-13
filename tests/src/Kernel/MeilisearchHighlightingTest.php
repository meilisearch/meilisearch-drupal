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
        'fields' => ['name', 'body'],
        'pre_tag' => '<strong>',
        'post_tag' => '</strong>',
        'crop_length' => 6,
        'crop_marker' => '…',
      ]);
    $index->addProcessor($processor)->save();

    $this->addTestEntity(1, [
      'name' => 'Wombat stew',
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

    $this->assertStringStartsWith('<strong>Wombat</strong> stew … ', $excerpt, 'Snippets of different fields are separated by the crop marker.');
    $this->assertStringContainsString('<strong>wombat</strong> end', $excerpt);
    $this->assertStringNotContainsString('… …', $excerpt);
    $this->assertSame(['name', 'body'], array_keys($item->getExtraData('highlighted_fields')));

    $results = Index::load('meilisearch_test_index')->query()->keys('script')->execute();
    $excerpt = (string) current($results->getResultItems())->getExcerpt();
    $this->assertStringNotContainsString('<script>', $excerpt);
    $this->assertStringContainsString('&lt;script&gt;', $excerpt);
  }

  /**
   * Private-use characters in content are not taken for highlight markers.
   */
  public function testContentCannotForgeHighlightMarkers(): void {
    $this->addTestEntity(2, [
      'name' => 'Offer',
      'type' => 'item',
      'body' => "Great \u{E000}deal here",
    ]);
    $this->addTestEntity(3, [
      'name' => "Zebra \u{E000}title\u{E001}",
      'type' => 'item',
      'body' => 'nothing great',
    ]);
    $this->indexItems('meilisearch_test_index');

    $items = Index::load('meilisearch_test_index')->query()->keys('great')->execute()->getResultItems();
    $ids = $this->getItemIds([2, 3]);

    $this->assertSame("<strong>Great</strong> \u{E000}deal here", (string) $items[$ids[0]]->getExcerpt());
    $this->assertSame(['body'], array_keys($items[$ids[0]]->getExtraData('highlighted_fields')));

    $this->assertSame('nothing <strong>great</strong>', (string) $items[$ids[1]]->getExcerpt());
    $this->assertSame(['body'], array_keys($items[$ids[1]]->getExtraData('highlighted_fields')), 'A field without a match is not reported as highlighted.');
  }

  /**
   * Every search uses its own markers.
   */
  public function testMarkersDifferBetweenSearches(): void {
    $index = Index::load('meilisearch_test_index');
    $markers = [];
    foreach ([0, 1] as $i) {
      $query = $index->query()->keys('wombat');
      $query->execute();
      $markers[$i] = $query->getOption('meilisearch_highlighting');
      $this->assertNotSame($markers[$i]['pre_tag'], $markers[$i]['post_tag']);
    }
    $this->assertNotSame($markers[0]['pre_tag'], $markers[1]['pre_tag']);
    $this->assertNotSame($markers[0]['post_tag'], $markers[1]['post_tag']);
  }

  /**
   * Results of a query the processor did not prepare are left alone.
   */
  public function testResultsWithoutMarkersAreLeftAlone(): void {
    $index = Index::load('meilisearch_test_index');
    $results = $index->query()->getResults();
    $item = \Drupal::getContainer()->get('search_api.fields_helper')->createItem($index, $this->getItemIds([1])[0]);
    $item->setExtraData('meilisearch_formatted', ['name' => "\u{E000}Wombat\u{E001} stew"]);
    $results->addResultItem($item);

    $index->getProcessor('meilisearch_highlighting')->postprocessSearchResults($results);

    $this->assertNull($item->getExcerpt());
    $this->assertNull($item->getExtraData('highlighted_fields'));
  }

}
