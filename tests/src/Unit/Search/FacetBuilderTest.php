<?php

declare(strict_types=1);

namespace Drupal\Tests\meilisearch\Unit\Search;

use Drupal\meilisearch\Filter\FilterBuilderInterface;
use Drupal\meilisearch\Search\FacetBuilder;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\FieldInterface;
use Drupal\search_api\Query\ConditionGroupInterface;
use Drupal\search_api\Query\QueryInterface;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\meilisearch\Search\FacetBuilder
 */
class FacetBuilderTest extends TestCase {

  /**
   * Returns a facet builder for a query requesting the given facets.
   *
   * @param array $facets
   *   The "search_api_facets" option.
   * @param array<string, string> $types
   *   Search API field types, keyed by field ID.
   * @param array $params
   *   The search parameters of the main query.
   */
  protected function facetBuilder(array $facets, array $types, array $params = []): FacetBuilder {
    $index = $this->createMock(IndexInterface::class);
    $index->method('getField')->willReturnCallback(function (string $id) use ($types): ?FieldInterface {
      if (!isset($types[$id])) {
        return NULL;
      }
      $field = $this->createMock(FieldInterface::class);
      $field->method('getType')->willReturn($types[$id]);
      return $field;
    });

    $query = $this->createMock(QueryInterface::class);
    $query->method('getIndex')->willReturn($index);
    $query->method('getConditionGroup')->willReturn($this->createMock(ConditionGroupInterface::class));
    $query->method('getOption')->willReturnCallback(
      fn(string $name, $default = NULL) => $name === 'search_api_facets' ? $facets : $default
    );

    // Without the conditions of an OR facet, no filter is left.
    $filter_builder = $this->createMock(FilterBuilderInterface::class);
    $filter_builder->method('build')->willReturn(NULL);

    return new FacetBuilder($query, $filter_builder, $params, []);
  }

  /**
   * @covers ::buildResults
   */
  public function testBooleanValuesUseSearchApiNotation(): void {
    $builder = $this->facetBuilder(['flag' => ['field' => 'flag']], ['flag' => 'boolean']);

    $results = $builder->buildResults(['facetDistribution' => ['flag' => ['false' => 1, 'true' => 2]]], []);

    $this->assertSame([
      ['count' => 2, 'filter' => '"1"'],
      ['count' => 1, 'filter' => '"0"'],
    ], $results['flag']);
  }

  /**
   * @covers ::buildResults
   */
  public function testBooleanValuesWithoutResultAreNotDuplicated(): void {
    $builder = $this->facetBuilder(['flag' => ['field' => 'flag', 'min_count' => 0]], ['flag' => 'boolean']);
    $builder->extraQueries('uid');

    $results = $builder->buildResults(
      ['facetDistribution' => ['flag' => ['true' => 2]]],
      [['facetDistribution' => ['flag' => ['false' => 1, 'true' => 3]]]],
    );

    $this->assertSame([
      ['count' => 2, 'filter' => '"1"'],
      ['count' => 0, 'filter' => '"0"'],
    ], $results['flag']);
  }

  /**
   * @covers ::buildResults
   */
  public function testStringValuesAreKeptVerbatim(): void {
    $builder = $this->facetBuilder(['answer' => ['field' => 'answer']], ['answer' => 'string']);

    $results = $builder->buildResults(['facetDistribution' => ['answer' => ['true' => 2, 'false' => 1]]], []);

    $this->assertSame([
      ['count' => 2, 'filter' => '"true"'],
      ['count' => 1, 'filter' => '"false"'],
    ], $results['answer']);
  }

  /**
   * @covers ::extraQueries
   */
  public function testCountQueriesSearchLikeTheMainQuery(): void {
    $hybrid = ['embedder' => 'default', 'semanticRatio' => 0.5];
    $params = [
      'q' => 'shoes',
      'hybrid' => $hybrid,
      'matchingStrategy' => 'all',
      'attributesToSearchOn' => ['title'],
      'filter' => 'color = "red"',
      'page' => 2,
      'hitsPerPage' => 10,
    ];
    $facets = ['color' => ['field' => 'color', 'operator' => 'or', 'min_count' => 0, 'missing' => TRUE]];
    $builder = $this->facetBuilder($facets, ['color' => 'string'], $params);

    $search = ['q' => 'shoes', 'hybrid' => $hybrid, 'matchingStrategy' => 'all', 'attributesToSearchOn' => ['title']];
    $this->assertSame([
      // The values of the OR facet, counted without its own filter.
      ['indexUid' => 'uid', 'facets' => ['color'], 'limit' => 0] + $search,
      // All the values of the field: not a search.
      ['indexUid' => 'uid', 'facets' => ['color'], 'limit' => 0],
      // The results without a value.
      ['indexUid' => 'uid', 'page' => 1, 'hitsPerPage' => 0] + $search + ['filter' => '(color NOT EXISTS OR color IS NULL)'],
    ], $builder->extraQueries('uid'));
  }

  /**
   * @covers ::extraQueries
   */
  public function testKeywordCountQueriesAreNotHybrid(): void {
    $facets = ['color' => ['field' => 'color', 'missing' => TRUE]];
    $builder = $this->facetBuilder($facets, ['color' => 'string'], ['q' => 'shoes', 'matchingStrategy' => 'last']);

    $this->assertSame([
      [
        'indexUid' => 'uid',
        'page' => 1,
        'hitsPerPage' => 0,
        'q' => 'shoes',
        'matchingStrategy' => 'last',
        'filter' => '(color NOT EXISTS OR color IS NULL)',
      ],
    ], $builder->extraQueries('uid'));
  }

}
