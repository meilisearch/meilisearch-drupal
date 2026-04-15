<?php

declare(strict_types=1);

namespace Drupal\meilisearch\Filter;

use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\ConditionInterface;

/**
 * Parses null value conditions (IS NULL / IS NOT NULL).
 */
class NullValueParser implements ConditionParserInterface {

  /**
   * {@inheritdoc}
   */
  public function supports(ConditionInterface $condition, IndexInterface $index): bool {
    return $condition->getValue() === NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function parse(ConditionInterface $condition, IndexInterface $index): string {
    $operator = $condition->getOperator();
    $suffix = in_array($operator, ['!=', '<>'], TRUE) ? 'IS NOT NULL' : 'IS NULL';
    return sprintf('%s %s', $condition->getField(), $suffix);
  }

}
