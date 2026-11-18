<?php

declare(strict_types=1);

namespace Drupal\meilisearch\Filter;

use Drupal\meilisearch\Converter\DocumentConverterInterface;
use Drupal\search_api\IndexInterface;

/**
 * Formats condition values for Meilisearch filter expressions.
 */
final class FilterValue {

  /**
   * Returns the Search API type of a filterable field.
   *
   * Special fields are strings. Returns NULL for fields the index does not
   * have.
   */
  public static function fieldType(IndexInterface $index, string $field): ?string {
    if (in_array($field, DocumentConverterInterface::SPECIAL_FIELDS, TRUE)) {
      return 'string';
    }
    return $index->getField($field)?->getType();
  }

  /**
   * Formats a value as a filter literal, following the field type.
   *
   * Values are converted the same way DocumentConverter stores them: numbers
   * bare, booleans as true/false, dates as timestamps, everything else as a
   * quoted string. With an unknown type, numeric values are left bare.
   *
   * Numbers that need an exponent are quoted: Meilisearch rejects a bare "+"
   * but compares quoted numbers numerically. A PHP boolean is written the way
   * the field stores it: true/false on a boolean field, 1/0 on a numeric or
   * date field and "1"/"" on a string field.
   */
  public static function format(mixed $value, ?string $type): string {
    if (is_bool($value) && in_array($type, ['integer', 'decimal', 'date'], TRUE)) {
      $value = (int) $value;
    }
    switch ($type) {
      case 'boolean':
        return self::toBool($value) ? 'true' : 'false';

      case 'integer':
      case 'decimal':
        if (is_numeric($value)) {
          $number = self::number(is_string($value) ? 0 + $value : $value);
          if ($number !== NULL) {
            return $number;
          }
        }
        break;

      case 'date':
        if (is_numeric($value)) {
          return (string) (int) $value;
        }
        $timestamp = strtotime((string) $value);
        if ($timestamp !== FALSE) {
          return (string) $timestamp;
        }
        break;

      case NULL:
        if (is_bool($value)) {
          return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
          $number = self::number($value);
          if ($number !== NULL) {
            return $number;
          }
        }
        elseif (is_string($value) && is_numeric($value)) {
          return $value;
        }
        break;
    }
    return self::quote((string) $value);
  }

  /**
   * Quotes and escapes a string literal.
   */
  public static function quote(string $value): string {
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
  }

  /**
   * Renders a number as a filter literal with its exact value.
   *
   * Floats use the shortest notation that converts back to the same float,
   * whatever the precision settings of php.ini are, so the literal equals the
   * number DocumentConverter stores. Whole numbers below 1e21 are written out
   * in full; other numbers that need an exponent are quoted. Returns NULL for
   * INF and NAN, which have no numeric literal.
   */
  protected static function number(int|float $number): ?string {
    if (is_int($number)) {
      return (string) $number;
    }
    if (!is_finite($number)) {
      return NULL;
    }
    if (floor($number) === $number && abs($number) < 1e21) {
      return sprintf('%.0F', $number);
    }
    // 17 significant digits always identify a float; fewer are often enough.
    // The "H" conversion ignores the locale's decimal separator.
    foreach ([15, 16, 17] as $precision) {
      $literal = sprintf('%.' . $precision . 'H', $number);
      if ((float) $literal === $number) {
        break;
      }
    }
    return preg_match('/^-?[0-9.]+$/', $literal) ? $literal : self::quote($literal);
  }

  /**
   * Interprets a value the way Search API's boolean data type does.
   */
  protected static function toBool(mixed $value): bool {
    if (is_string($value)) {
      return !in_array(strtolower(trim($value)), ['', '0', 'false', 'off', 'no'], TRUE);
    }
    return (bool) $value;
  }

}
