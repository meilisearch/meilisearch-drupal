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
   * Field types that DocumentConverter does not store as strings.
   */
  protected const NON_STRING_TYPES = ['boolean', 'integer', 'decimal', 'date'];

  /**
   * {@inheritdoc}
   *
   * FALSE on a string field is stored as "", which Meilisearch leaves out of
   * every comparison: "<=" and ">=" would miss the items that store it, so
   * range operators are not supported and the filter builder rejects them.
   */
  public function supports(ConditionInterface $condition, IndexInterface $index): bool {
    $value = $condition->getValue();
    if (!is_bool($value)) {
      return FALSE;
    }
    return $value || !$this->isStringField($index, $condition->getField())
      || in_array($condition->getOperator(), ['=', '<>', '!='], TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function parse(ConditionInterface $condition, IndexInterface $index): string {
    $operator = $condition->getOperator() === '<>' ? '!=' : $condition->getOperator();
    $field = $condition->getField();
    $value = $condition->getValue();
    if ($value === FALSE && $this->isStringField($index, $field)) {
      // Meilisearch never matches "" with "=". IS EMPTY matches "", [] and {},
      // but not a missing or null attribute; IS NOT EMPTY matches those, as
      // "!=" does.
      return sprintf($operator === '=' ? '%s IS EMPTY' : '%s IS NOT EMPTY', $field);
    }
    $formatted = FilterValue::format($value, FilterValue::fieldType($index, $field));
    return sprintf('%s %s %s', $field, $operator, $formatted);
  }

  /**
   * Returns whether DocumentConverter stores the field's values as strings.
   */
  protected function isStringField(IndexInterface $index, string $field): bool {
    $type = FilterValue::fieldType($index, $field);
    return $type !== NULL && !in_array($type, static::NON_STRING_TYPES, TRUE);
  }

}
