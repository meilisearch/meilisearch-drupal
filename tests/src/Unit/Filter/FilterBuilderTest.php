<?php

declare(strict_types=1);

namespace Drupal\Tests\meilisearch\Unit\Filter;

use Drupal\meilisearch\Filter\BooleanValueParser;
use Drupal\meilisearch\Filter\FilterBuilder;
use Drupal\meilisearch\Filter\NullValueParser;
use Drupal\meilisearch\Filter\ScalarValueParser;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\ConditionGroup;
use PHPUnit\Framework\TestCase;

class FilterBuilderTest extends TestCase {

  public function testSingleCondition(): void {
    $builder = $this->buildBuilder();
    $group = new ConditionGroup('AND');
    $group->addCondition('title', 'Hello', '=');

    $this->assertSame('title = "Hello"', $builder->build($group, $this->createIndex()));
  }

  public function testAndGroup(): void {
    $builder = $this->buildBuilder();
    $group = new ConditionGroup('AND');
    $group->addCondition('title', 'Hello', '=');
    $group->addCondition('count', 5, '>');

    $this->assertSame('(title = "Hello" AND count > 5)', $builder->build($group, $this->createIndex()));
  }

  public function testOrGroup(): void {
    $builder = $this->buildBuilder();
    $group = new ConditionGroup('OR');
    $group->addCondition('genre', 'action', '=');
    $group->addCondition('genre', 'comedy', '=');

    $this->assertSame('(genre = "action" OR genre = "comedy")', $builder->build($group, $this->createIndex()));
  }

  public function testNestedGroups(): void {
    $builder = $this->buildBuilder();
    $inner = new ConditionGroup('OR');
    $inner->addCondition('genre', 'action', '=');
    $inner->addCondition('genre', 'comedy', '=');

    $outer = new ConditionGroup('AND');
    $outer->addConditionGroup($inner);
    $outer->addCondition('rating', 4, '>=');

    $this->assertSame(
      '((genre = "action" OR genre = "comedy") AND rating >= 4)',
      $builder->build($outer, $this->createIndex())
    );
  }

  public function testEmptyGroupReturnsNull(): void {
    $builder = $this->buildBuilder();
    $group = new ConditionGroup('AND');

    $this->assertNull($builder->build($group, $this->createIndex()));
  }

  private function buildBuilder(): FilterBuilder {
    $builder = new FilterBuilder();
    $builder->addConditionParser(new BooleanValueParser());
    $builder->addConditionParser(new NullValueParser());
    $builder->addConditionParser(new ScalarValueParser());
    return $builder;
  }

  private function createIndex(): IndexInterface {
    return $this->createMock(IndexInterface::class);
  }

}
