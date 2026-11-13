<?php

declare(strict_types=1);

namespace Drupal\meilisearch\Filter;

use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\ConditionInterface;

/**
 * Parses boolean value conditions.
 *
 * The value is written according to the type of the field, so that a boolean
 * condition also matches fields that store 1/0 or "1"/"".
 */
class BooleanValueParser implements ConditionParserInterface {

  /**
   * {@inheritdoc}
   */
  public function supports(ConditionInterface $condition, IndexInterface $index): bool {
    return is_bool($condition->getValue());
  }

  /**
   * {@inheritdoc}
   */
  public function parse(ConditionInterface $condition, IndexInterface $index): string {
    $operator = $condition->getOperator() === '<>' ? '!=' : $condition->getOperator();
    $field = $condition->getField();
    $formatted = FilterValue::format($condition->getValue(), FilterValue::fieldType($index, $field));
    return sprintf('%s %s %s', $field, $operator, $formatted);
  }

}
