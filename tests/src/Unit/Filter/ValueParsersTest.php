<?php

declare(strict_types=1);

namespace Drupal\Tests\meilisearch\Unit\Filter;

use Drupal\meilisearch\Filter\BooleanValueParser;
use Drupal\meilisearch\Filter\NullValueParser;
use Drupal\meilisearch\Filter\ScalarValueParser;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\Condition;
use PHPUnit\Framework\TestCase;

class ValueParsersTest extends TestCase {

  public function testScalarEquals(): void {
    $parser = new ScalarValueParser();
    $condition = new Condition('title', 'Hello', '=');
    $index = $this->createMock(IndexInterface::class);

    $this->assertTrue($parser->supports($condition, $index));
    $this->assertSame('title = "Hello"', $parser->parse($condition, $index));
  }

  public function testScalarGreaterThan(): void {
    $parser = new ScalarValueParser();
    $condition = new Condition('count', 5, '>');
    $index = $this->createMock(IndexInterface::class);

    $this->assertSame('count > 5', $parser->parse($condition, $index));
  }

  public function testScalarQuotesStringsEscapesDouble(): void {
    $parser = new ScalarValueParser();
    $condition = new Condition('title', 'He said "hi"', '=');
    $index = $this->createMock(IndexInterface::class);

    $this->assertSame('title = "He said \\"hi\\""', $parser->parse($condition, $index));
  }

  public function testBooleanValue(): void {
    $parser = new BooleanValueParser();
    $condition = new Condition('published', TRUE, '=');
    $index = $this->createMock(IndexInterface::class);

    $this->assertTrue($parser->supports($condition, $index));
    $this->assertSame('published = true', $parser->parse($condition, $index));

    $condition = new Condition('published', FALSE, '=');
    $this->assertSame('published = false', $parser->parse($condition, $index));
  }

  public function testNullIs(): void {
    $parser = new NullValueParser();
    $condition = new Condition('field', NULL, '=');
    $index = $this->createMock(IndexInterface::class);

    $this->assertTrue($parser->supports($condition, $index));
    $this->assertSame('(field NOT EXISTS OR field IS NULL)', $parser->parse($condition, $index));

    $condition = new Condition('field', NULL, '<>');
    $this->assertSame('(field EXISTS AND NOT field IS NULL)', $parser->parse($condition, $index));
  }

}
