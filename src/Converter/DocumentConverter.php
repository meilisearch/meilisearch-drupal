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

    // Cast each value, then drop any that are NULL (e.g. unparseable dates).
    // Empty value lists intentionally produce no field entry — Meilisearch
    // treats absent keys as null for filtering.
    $converted = array_filter(
      array_map(fn($v) => $this->castValue($v, $type), $values),
      static fn($v) => $v !== NULL,
    );
    $converted = array_values($converted);
    if (count($converted) === 1) {
      $doc[$id] = $converted[0];
    }
    elseif (count($converted) > 1) {
      $doc[$id] = $converted;
    }
  }

  /**
   * Casts a single value to its Meilisearch-appropriate type.
   *
   * Returns NULL for values that cannot be safely converted (e.g. an
   * unparseable date string). Callers must treat NULL as "skip this value"
   * rather than indexing it — Meilisearch rejects documents whose fields
   * contain values incompatible with the field's declared type.
   */
  protected function castValue(mixed $value, string $type): mixed {
    return match ($type) {
      'integer' => is_numeric($value) ? (int) $value : NULL,
      'decimal' => is_numeric($value) ? (float) $value : NULL,
      'boolean' => (bool) $value,
      'date' => $this->castDate($value),
      default => (string) $value,
    };
  }

  /**
   * Casts a value to a Unix timestamp, returning NULL if it can't be parsed.
   */
  protected function castDate(mixed $value): ?int {
    if (is_numeric($value)) {
      return (int) $value;
    }
    $timestamp = strtotime((string) $value);
    return $timestamp === FALSE ? NULL : $timestamp;
  }

}
