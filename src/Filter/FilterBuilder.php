<?php

declare(strict_types=1);

namespace Drupal\meilisearch\Filter;

use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\ConditionGroupInterface;
use Drupal\search_api\Query\ConditionInterface;

/**
 * Default implementation of FilterBuilder.
 */
class FilterBuilder implements FilterBuilderInterface {

  /**
   * @var \Drupal\meilisearch\Filter\ConditionParserInterface[]
   */
  protected array $parsers = [];

  /**
   * Adds a condition parser. Called by the service collector.
   */
  public function addConditionParser(ConditionParserInterface $parser): void {
    $this->parsers[] = $parser;
  }

  /**
   * {@inheritdoc}
   */
  public function build(ConditionGroupInterface $group, IndexInterface $index): ?string {
    $parts = [];
    foreach ($group->getConditions() as $item) {
      if ($item instanceof ConditionGroupInterface) {
        $nested = $this->build($item, $index);
        if ($nested !== NULL) {
          $parts[] = $nested;
        }
      }
      elseif ($item instanceof ConditionInterface) {
        $parsed = $this->parseCondition($item, $index);
        if ($parsed !== NULL) {
          $parts[] = $parsed;
        }
      }
    }

    if (empty($parts)) {
      return NULL;
    }

    if (count($parts) === 1) {
      return $parts[0];
    }

    return '(' . implode(' ' . $group->getConjunction() . ' ', $parts) . ')';
  }

  /**
   * Finds the first supporting parser and returns its output.
   */
  protected function parseCondition(ConditionInterface $condition, IndexInterface $index): ?string {
    foreach ($this->parsers as $parser) {
      if ($parser->supports($condition, $index)) {
        return $parser->parse($condition, $index);
      }
    }
    return NULL;
  }

}
