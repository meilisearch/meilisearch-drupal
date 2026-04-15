<?php

declare(strict_types=1);

namespace Drupal\meilisearch\Utility;

/**
 * Utility functions for Meilisearch integration.
 */
class MeilisearchUtils {

  /**
   * Sanitizes a Search API item ID into a safe Meilisearch document ID.
   *
   * Meilisearch document IDs allow [A-Za-z0-9_-]. Replace unsafe chars.
   */
  public static function formatAsDocumentId(string $id): string {
    return (string) preg_replace('/[^A-Za-z0-9_-]/', '-', $id);
  }

}
