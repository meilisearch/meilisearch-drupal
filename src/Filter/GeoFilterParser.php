<?php

declare(strict_types=1);

namespace Drupal\meilisearch\Filter;

use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\ConditionInterface;

/**
 * Parses geo conditions into Meilisearch geo filter syntax.
 */
class GeoFilterParser implements ConditionParserInterface {

  public const OPERATORS = ['GEO_RADIUS', 'GEO_BBOX'];

  /**
   * {@inheritdoc}
   */
  public function supports(ConditionInterface $condition, IndexInterface $index): bool {
    if (!in_array($condition->getOperator(), self::OPERATORS, TRUE)) {
      return FALSE;
    }
    $field = $index->getField($condition->getField());
    return $field !== NULL && $field->getType() === 'location';
  }

  /**
   * {@inheritdoc}
   */
  public function parse(ConditionInterface $condition, IndexInterface $index): string {
    $value = $condition->getValue();

    if ($condition->getOperator() === 'GEO_RADIUS') {
      return sprintf('_geoRadius(%s, %s, %s)', $value['lat'], $value['lng'], $value['radius']);
    }

    // GEO_BBOX.
    $tl = $value['top_left'];
    $br = $value['bottom_right'];
    return sprintf('_geoBoundingBox([%s, %s], [%s, %s])', $tl['lat'], $tl['lng'], $br['lat'], $br['lng']);
  }

}
