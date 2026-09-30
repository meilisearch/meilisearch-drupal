<?php

declare(strict_types=1);

namespace Drupal\meilisearch\Utility;

/**
 * Utility functions for the Meilisearch integration.
 */
class MeilisearchUtils {

  /**
   * The maximum length of a Meilisearch document ID, in bytes.
   */
  protected const MAX_DOCUMENT_ID_LENGTH = 511;

  /**
   * Encodes a Search API item ID as a Meilisearch document ID.
   *
   * Meilisearch document IDs may only contain [A-Za-z0-9_-]. Letters, digits
   * and "-" are kept, "_" becomes "__" and every other byte becomes "_XX"
   * (uppercase hex), so the encoding is injective and stays readable:
   * "entity:node/1:en" becomes "entity_3Anode_2F1_3Aen". IDs whose encoding
   * exceeds 511 bytes are replaced by "h_" and their SHA-256 hash.
   */
  public static function encodeDocumentId(string $id): string {
    $encoded = preg_replace_callback(
      '/[^A-Za-z0-9-]/',
      static fn(array $m) => $m[0] === '_' ? '__' : sprintf('_%02X', ord($m[0])),
      $id,
    );
    if (strlen($encoded) > self::MAX_DOCUMENT_ID_LENGTH) {
      return 'h_' . hash('sha256', $id);
    }
    return $encoded;
  }

}
