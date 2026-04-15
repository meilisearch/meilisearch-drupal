<?php

declare(strict_types=1);

namespace Drupal\meilisearch\Converter;

use Drupal\meilisearch\Utility\MeilisearchUtils;
use Drupal\search_api\Item\FieldInterface;

/**
 * Default document converter.
 */
class DocumentConverter implements DocumentConverterInterface {

  /**
   * {@inheritdoc}
   */
  public function convertToDocuments(array $items): array {
    $documents = [];
    foreach ($items as $item) {
      $doc = [
        'id' => MeilisearchUtils::formatAsDocumentId($item->getId()),
        'search_api_id' => $item->getId(),
      ];
      foreach ($item->getFields() as $field) {
        $this->applyField($doc, $field);
      }
      $documents[] = $doc;
    }
    return $documents;
  }

  /**
   * Applies a field's values to the document array.
   */
  protected function applyField(array &$doc, FieldInterface $field): void {
    $id = $field->getFieldIdentifier();
    $type = $field->getType();
    $values = $field->getValues();

    if ($type === 'location') {
      // Meilisearch expects _geo: {lat, lng} — take the first value.
      $first = reset($values);
      if (is_string($first) && str_contains($first, ',')) {
        [$lat, $lng] = array_map('trim', explode(',', $first, 2));
        $doc['_geo'] = ['lat' => (float) $lat, 'lng' => (float) $lng];
      }
      elseif (is_array($first) && isset($first['lat'], $first['lng'])) {
        $doc['_geo'] = ['lat' => (float) $first['lat'], 'lng' => (float) $first['lng']];
      }
      return;
    }

    $converted = array_map(fn($v) => $this->castValue($v, $type), $values);
    if (count($converted) === 1) {
      $doc[$id] = $converted[0];
    }
    elseif (count($converted) > 1) {
      $doc[$id] = $converted;
    }
  }

  /**
   * Casts a single value to its Meilisearch-appropriate type.
   */
  protected function castValue(mixed $value, string $type): mixed {
    return match ($type) {
      'integer' => (int) $value,
      'decimal' => (float) $value,
      'boolean' => (bool) $value,
      'date' => is_numeric($value) ? (int) $value : strtotime((string) $value),
      default => (string) $value,
    };
  }

}
