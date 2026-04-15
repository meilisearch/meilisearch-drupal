<?php

declare(strict_types=1);

namespace Drupal\meilisearch\Filter;

use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\ConditionGroupInterface;

/**
 * Converts Search API condition groups to Meilisearch filter strings.
 */
interface FilterBuilderInterface {

  /**
   * Parses a condition group into a Meilisearch filter string.
   *
   * @return string|null
   *   The filter string, or NULL if the condition group is empty.
   */
  public function build(ConditionGroupInterface $group, IndexInterface $index): ?string;

}
