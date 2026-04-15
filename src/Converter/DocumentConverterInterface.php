<?php

declare(strict_types=1);

namespace Drupal\meilisearch\Converter;

use Drupal\search_api\Item\ItemInterface;

/**
 * Converts Search API items to Meilisearch documents.
 */
interface DocumentConverterInterface {

  /**
   * Converts a list of Search API items to Meilisearch documents.
   *
   * @param \Drupal\search_api\Item\ItemInterface[] $items
   *   Search API items keyed by item ID.
   *
   * @return array
   *   Array of document arrays, each with at minimum `id` and `search_api_id`.
   */
  public function convertToDocuments(array $items): array;

}
