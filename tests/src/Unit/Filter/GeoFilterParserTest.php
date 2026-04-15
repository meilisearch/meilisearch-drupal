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
