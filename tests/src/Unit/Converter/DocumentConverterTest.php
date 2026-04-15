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
