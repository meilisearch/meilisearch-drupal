<?php

declare(strict_types=1);

namespace Drupal\Tests\meilisearch\Unit\Filter;

use Drupal\meilisearch\Filter\FilterValue;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\meilisearch\Filter\FilterValue
 */
class FilterValueTest extends TestCase {

  /**
   * Numbers keep their exact value and never use a bare exponent.
   *
   * @covers ::format
   * @dataProvider numberProvider
   */
  public function testNumbers(mixed $value, ?string $type, string $expected): void {
    $this->assertSame($expected, FilterValue::format($value, $type));
  }

  /**
   * Provides numeric values, field types and the expected filter literal.
   */
  public static function numberProvider(): array {
    return [
      'int' => [7, 'integer', '7'],
      'int string' => ['7', 'integer', '7'],
      'int on a decimal field' => [5, 'decimal', '5'],
      'negative int' => [-5, 'integer', '-5'],
      'negative int string' => ['-5', 'integer', '-5'],
      'largest int' => [PHP_INT_MAX, 'integer', '9223372036854775807'],
      'decimal string' => ['0.9', 'decimal', '0.9'],
      'float' => [19.99, 'decimal', '19.99'],
      'negative float' => [-1.5, 'decimal', '-1.5'],
      'fraction on an integer field' => ['1.5', 'integer', '1.5'],
      'whole float' => [5.0, 'decimal', '5'],
      'exponent string on an integer field' => ['1e15', 'integer', '1000000000000000'],
      'exponent string on a decimal field' => ['1e15', 'decimal', '1000000000000000'],
      'negative exponent string' => ['-1e15', 'decimal', '-1000000000000000'],
      'float of 1e14' => [100000000000000.0, 'decimal', '100000000000000'],
      '20-digit integer string' => ['99999999999999999999', 'integer', '100000000000000000000'],
      '20-digit integer string on a decimal field' => ['99999999999999999999', 'decimal', '100000000000000000000'],
      '15 significant digits' => ['1234567.12345678', 'decimal', '1234567.12345678'],
      '16 significant digits' => ['1234567.123456789', 'decimal', '1234567.123456789'],
      '17 significant digits' => ['0.30000000000000004', 'decimal', '0.30000000000000004'],
      'large decimal' => ['123456789012345.67', 'decimal', '123456789012345.67'],
      'float sum' => [0.1 + 0.2, 'decimal', '0.30000000000000004'],
      'small decimal' => ['0.0001', 'decimal', '0.0001'],
      'very small decimal' => ['0.00001', 'decimal', '"1.0E-5"'],
      'very small negative decimal' => [-0.00001, 'decimal', '"-1.0E-5"'],
      'very large float' => [1e25, 'decimal', '"1.0E+25"'],
      'very large exponent string' => ['1e25', 'integer', '"1.0E+25"'],
      'float without a field type' => [1e15, NULL, '1000000000000000'],
      'int without a field type' => [5, NULL, '5'],
    ];
  }

  /**
   * The literal does not depend on the precision settings of php.ini.
   *
   * @covers ::format
   */
  public function testNumbersIgnoreIniPrecision(): void {
    $precision = ini_set('precision', '5');
    $serialize_precision = ini_set('serialize_precision', '5');
    try {
      $this->assertSame('1234567.12345678', FilterValue::format('1234567.12345678', 'decimal'));
      $this->assertSame('1000000000000000', FilterValue::format('1e15', 'integer'));
    }
    finally {
      ini_set('precision', (string) $precision);
      ini_set('serialize_precision', (string) $serialize_precision);
    }
  }

}
