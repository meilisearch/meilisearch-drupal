<?php

declare(strict_types=1);

namespace Drupal\Tests\meilisearch\Unit\Filter;

use Drupal\meilisearch\Filter\BetweenOperatorParser;
use Drupal\meilisearch\Filter\InOperatorParser;
use Drupal\meilisearch\Filter\NotBetweenOperatorParser;
use Drupal\meilisearch\Filter\NotInOperatorParser;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\Condition;
use PHPUnit\Framework\TestCase;

class OperatorParsersTest extends TestCase {

  public function testInOperator(): void {
    $parser = new InOperatorParser();
    $condition = new Condition('genre', ['action', 'comedy'], 'IN');
    $index = $this->createMock(IndexInterface::class);

    $this->assertTrue($parser->supports($condition, $index));
    $this->assertSame('genre IN ["action", "comedy"]', $parser->parse($condition, $index));
  }

  public function testNotInOperator(): void {
    $parser = new NotInOperatorParser();
    $condition = new Condition('status', ['draft', 'archived'], 'NOT IN');
    $index = $this->createMock(IndexInterface::class);

    $this->assertTrue($parser->supports($condition, $index));
    $this->assertSame('status NOT IN ["draft", "archived"]', $parser->parse($condition, $index));
  }

  public function testBetweenOperator(): void {
    $parser = new BetweenOperatorParser();
    $condition = new Condition('price', [10, 100], 'BETWEEN');
    $index = $this->createMock(IndexInterface::class);

    $this->assertTrue($parser->supports($condition, $index));
    $this->assertSame('price 10 TO 100', $parser->parse($condition, $index));
  }

  public function testNotBetweenOperator(): void {
    $parser = new NotBetweenOperatorParser();
    $condition = new Condition('price', [10, 100], 'NOT BETWEEN');
    $index = $this->createMock(IndexInterface::class);

    $this->assertTrue($parser->supports($condition, $index));
    $this->assertSame('NOT price 10 TO 100', $parser->parse($condition, $index));
  }

}
