<?php

declare(strict_types=1);

namespace Drupal\meilisearch\Search;

use Drupal\meilisearch\Filter\FilterBuilderInterface;
use Drupal\search_api\Query\QueryInterface;

/**
 * Computes Search API facets ("search_api_facets" option) with Meilisearch.
 *
 * Plain facets come from the facet distribution of the main search. Three
 * cases need extra queries, all sent in the same multi-search request:
 * - OR facets with an active filter must ignore that filter (the condition
 *   groups tagged "facet:FIELD"), so the other values stay selectable;
 * - "min_count" 0 lists values that have no result in the current search;
 * - "missing" counts results without a value.
 */
final class FacetBuilder {

  /**
   * The facets requested by the query, keyed by facet ID.
   */
  protected array $facets;

  /**
   * Per facet: the filter its counts are computed with.
   */
  protected array $filters = [];

  /**
   * Per facet: positions of its extra queries in the multi-search.
   */
  protected array $positions = [];

  /**
   * Constructs a FacetBuilder.
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The query.
   * @param \Drupal\meilisearch\Filter\FilterBuilderInterface $filterBuilder
   *   The filter builder.
   * @param array $params
   *   The search parameters of the main query.
   * @param array $extraFilters
   *   Filters added to the query's conditions (language, location), or NULL.
   */
  public function __construct(
    protected QueryInterface $query,
    protected FilterBuilderInterface $filterBuilder,
    protected array $params,
    protected array $extraFilters,
  ) {
    $this->facets = array_filter((array) $query->getOption('search_api_facets', []), fn($facet) => isset($facet['field']));
    $main_filter = $params['filter'] ?? NULL;
    foreach ($this->facets as $id => $facet) {
      $filter = $main_filter;
      if (($facet['operator'] ?? 'and') === 'or') {
        $filter = self::combine(array_merge(
          [$filterBuilder->build($query->getConditionGroup(), $query->getIndex(), ['facet:' . $facet['field']])],
          $extraFilters,
        ));
      }
      $this->filters[$id] = $filter;
    }
  }

  /**
   * AND-combines filters, ignoring empty ones.
   *
   * @param array $filters
   *   Filter expressions or NULL.
   */
  public static function combine(array $filters): ?string {
    $filters = array_values(array_filter($filters, fn($filter) => $filter !== NULL && $filter !== ''));
    if (!$filters) {
      return NULL;
    }
    return count($filters) === 1 ? $filters[0] : '(' . implode(') AND (', $filters) . ')';
  }

  /**
   * Returns TRUE if the query requests facets.
   */
  public function hasFacets(): bool {
    return (bool) $this->facets;
  }

  /**
   * Adds the facets computed from the main search to its parameters.
   */
  public function alterMainQuery(array $params): array {
    $fields = [];
    foreach ($this->facets as $id => $facet) {
      if (!$this->needsOwnQuery($id)) {
        $fields[] = $facet['field'];
      }
    }
    if ($fields) {
      $params['facets'] = array_values(array_unique($fields));
    }
    return $params;
  }

  /**
   * Returns the extra queries to send along with the main query.
   *
   * @param string $uid
   *   The Meilisearch index UID.
   *
   * @return array[]
   *   Multi-search queries. Position 0 is reserved for the main query.
   */
  public function extraQueries(string $uid): array {
    $shared = array_flip(['q', 'hybrid', 'matchingStrategy', 'attributesToSearchOn']);
    $base = ['indexUid' => $uid] + array_intersect_key($this->params, $shared);
    $queries = [];
    foreach ($this->facets as $id => $facet) {
      $field = $facet['field'];
      if ($this->needsOwnQuery($id)) {
        $queries[] = $base + array_filter(['filter' => $this->filters[$id]]) + ['facets' => [$field], 'limit' => 0];
        $this->positions[$id]['values'] = count($queries);
      }
      if ((int) ($facet['min_count'] ?? 1) < 1) {
        $queries[] = ['indexUid' => $uid, 'facets' => [$field], 'limit' => 0];
        $this->positions[$id]['all'] = count($queries);
      }
      if (!empty($facet['missing'])) {
        $missing = sprintf('(%1$s NOT EXISTS OR %1$s IS NULL)', $field);
        $queries[] = $base + [
          'filter' => self::combine([$this->filters[$id], $missing]),
          'page' => 1,
          'hitsPerPage' => 0,
        ];
        $this->positions[$id]['missing'] = count($queries);
      }
    }
    return $queries;
  }

  /**
   * Builds the "search_api_facets" results.
   *
   * @param array $main
   *   The main search response.
   * @param array $responses
   *   The responses of the extra queries (without the main one).
   *
   * @return array
   *   Facet values keyed by facet ID, each with "count" and "filter".
   */
  public function buildResults(array $main, array $responses): array {
    $output = [];
    foreach ($this->facets as $id => $facet) {
      $field = $facet['field'];
      $positions = $this->positions[$id] ?? [];
      $source = isset($positions['values']) ? $responses[$positions['values'] - 1] : $main;
      $counts = $source['facetDistribution'][$field] ?? [];
      if (isset($positions['all'])) {
        foreach (array_keys($responses[$positions['all'] - 1]['facetDistribution'][$field] ?? []) as $value) {
          $counts[$value] ??= 0;
        }
      }

      $min_count = (int) ($facet['min_count'] ?? 1);
      $values = [];
      foreach ($counts as $value => $count) {
        if ($count >= $min_count) {
          $values[] = ['count' => (int) $count, 'filter' => '"' . $value . '"'];
        }
      }
      usort($values, fn($a, $b) => $b['count'] <=> $a['count']);
      $limit = (int) ($facet['limit'] ?? 0);
      if ($limit > 0) {
        $values = array_slice($values, 0, $limit);
      }

      if (isset($positions['missing'])) {
        $missing = $responses[$positions['missing'] - 1];
        $count = (int) ($missing['totalHits'] ?? $missing['estimatedTotalHits'] ?? 0);
        if ($count >= $min_count) {
          $values[] = ['count' => $count, 'filter' => '!'];
        }
      }
      $output[$id] = $values;
    }
    return $output;
  }

  /**
   * Returns TRUE if a facet must ignore filters the main query applies.
   */
  protected function needsOwnQuery(string $id): bool {
    return $this->filters[$id] !== ($this->params['filter'] ?? NULL);
  }

}
