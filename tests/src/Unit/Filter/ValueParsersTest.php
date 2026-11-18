<?php

declare(strict_types=1);

namespace Drupal\Tests\meilisearch\Unit\Filter;

use Drupal\meilisearch\Filter\BooleanValueParser;
use Drupal\meilisearch\Filter\InOperatorParser;
use Drupal\meilisearch\Filter\NullValueParser;
use Drupal\meilisearch\Filter\ScalarValueParser;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\FieldInterface;
use Drupal\search_api\Query\Condition;
use PHPUnit\Framework\TestCase;

/**
 * Tests the value-based condition parsers.
 */
class ValueParsersTest extends TestCase {

  /**
   * Tests scalar equals.
   */
  public function testScalarEquals(): void {
    $parser = new ScalarValueParser();
    $condition = new Condition('title', 'Hello', '=');
    $index = $this->createMock(IndexInterface::class);

    $this->assertTrue($parser->supports($condition, $index));
    $this->assertSame('title = "Hello"', $parser->parse($condition, $index));
  }

  /**
   * Tests scalar greater than.
   */
  public function testScalarGreaterThan(): void {
    $parser = new ScalarValueParser();
    $condition = new Condition('count', 5, '>');
    $index = $this->createMock(IndexInterface::class);

    $this->assertSame('count > 5', $parser->parse($condition, $index));
  }

  /**
   * Tests scalar quotes strings escapes double.
   */
  public function testScalarQuotesStringsEscapesDouble(): void {
    $parser = new ScalarValueParser();
    $condition = new Condition('title', 'He said "hi"', '=');
    $index = $this->createMock(IndexInterface::class);

    $this->assertSame('title = "He said \\"hi\\""', $parser->parse($condition, $index));
  }

  /**
   * Tests boolean value.
   */
  public function testBooleanValue(): void {
    $parser = new BooleanValueParser();
    $condition = new Condition('published', TRUE, '=');
    $index = $this->createIndex(['published' => 'boolean']);

    $this->assertTrue($parser->supports($condition, $index));
    $this->assertSame('published = true', $parser->parse($condition, $index));

    $condition = new Condition('published', FALSE, '=');
    $this->assertSame('published = false', $parser->parse($condition, $index));

    $condition = new Condition('published', TRUE, '<>');
    $this->assertSame('published != true', $parser->parse($condition, $index));
  }

  /**
   * A boolean value is written the way the field type stores it.
   *
   * @dataProvider booleanFieldTypeProvider
   */
  public function testBooleanValueFollowsFieldType(string $type, string $true, string $false): void {
    $parser = new BooleanValueParser();
    $index = $this->createIndex(['flag' => $type]);

    $this->assertSame('flag = ' . $true, $parser->parse(new Condition('flag', TRUE, '='), $index));
    $this->assertSame('flag = ' . $false, $parser->parse(new Condition('flag', FALSE, '='), $index));
    $this->assertSame('flag != ' . $true, $parser->parse(new Condition('flag', TRUE, '<>'), $index));
  }

  /**
   * Provides field types with the literals expected for TRUE and FALSE.
   */
  public static function booleanFieldTypeProvider(): array {
    return [
      'boolean' => ['boolean', 'true', 'false'],
      'integer' => ['integer', '1', '0'],
      'decimal' => ['decimal', '1', '0'],
      'date' => ['date', '1', '0'],
    ];
  }

  /**
   * FALSE on a string field matches the empty string it is stored as.
   *
   * Meilisearch never matches "" with "=", so the parser uses IS EMPTY.
   *
   * @dataProvider stringFieldTypeProvider
   */
  public function testFalseOnStringFieldUsesIsEmpty(string $type): void {
    $parser = new BooleanValueParser();
    $index = $this->createIndex(['flag' => $type]);

    $this->assertSame('flag IS EMPTY', $parser->parse(new Condition('flag', FALSE, '='), $index));
    $this->assertSame('flag IS NOT EMPTY', $parser->parse(new Condition('flag', FALSE, '<>'), $index));
    $this->assertSame('flag IS NOT EMPTY', $parser->parse(new Condition('flag', FALSE, '!='), $index));
    $this->assertSame('flag = "1"', $parser->parse(new Condition('flag', TRUE, '='), $index));
    $this->assertSame('flag != "1"', $parser->parse(new Condition('flag', TRUE, '<>'), $index));
    $this->assertSame('flag > "1"', $parser->parse(new Condition('flag', TRUE, '>'), $index));
  }

  /**
   * FALSE on a string field cannot be used with a range operator.
   *
   * Meilisearch does not compare the empty string: "<=" and ">=" would leave
   * out the items that store it. The condition is not supported, so the
   * filter builder rejects it instead of returning wrong results.
   *
   * @dataProvider stringFieldTypeProvider
   */
  public function testFalseOnStringFieldRejectsRangeOperators(string $type): void {
    $parser = new BooleanValueParser();
    $index = $this->createIndex(['flag' => $type]);

    foreach (['<', '<=', '>', '>='] as $operator) {
      $this->assertFalse($parser->supports(new Condition('flag', FALSE, $operator), $index), $operator);
      $this->assertTrue($parser->supports(new Condition('flag', TRUE, $operator), $index), $operator);
    }
    $this->assertTrue($parser->supports(new Condition('flag', FALSE, '='), $index));
    $this->assertTrue($parser->supports(new Condition('flag', FALSE, '<>'), $index));
  }

  /**
   * Provides field types stored as strings.
   */
  public static function stringFieldTypeProvider(): array {
    return [
      'string' => ['string'],
      'text' => ['text'],
    ];
  }

  /**
   * Boolean values in a list follow the field type too.
   */
  public function testBooleanValuesInList(): void {
    $parser = new InOperatorParser();
    $index = $this->createIndex(['flag' => 'integer', 'published' => 'boolean']);

    $this->assertSame('flag IN [1, 0]', $parser->parse(new Condition('flag', [TRUE, FALSE], 'IN'), $index));
    $this->assertSame('published IN [true, false]', $parser->parse(new Condition('published', [TRUE, FALSE], 'IN'), $index));
  }

  /**
   * Tests null is.
   */
  public function testNullIs(): void {
    $parser = new NullValueParser();
    $condition = new Condition('field', NULL, '=');
    $index = $this->createMock(IndexInterface::class);

    $this->assertTrue($parser->supports($condition, $index));
    $this->assertSame('(field NOT EXISTS OR field IS NULL)', $parser->parse($condition, $index));

    $condition = new Condition('field', NULL, '<>');
    $this->assertSame('(field EXISTS AND NOT field IS NULL)', $parser->parse($condition, $index));
  }

  /**
   * Returns an index mock whose fields have the given types.
   *
   * @param array<string, string> $types
   *   Search API data types, keyed by field ID.
   */
  private function createIndex(array $types): IndexInterface {
    $index = $this->createMock(IndexInterface::class);
    $index->method('getField')->willReturnCallback(function (string $id) use ($types) {
      if (!isset($types[$id])) {
        return NULL;
      }
      $field = $this->createMock(FieldInterface::class);
      $field->method('getType')->willReturn($types[$id]);
      return $field;
    });
    return $index;
  }

}
