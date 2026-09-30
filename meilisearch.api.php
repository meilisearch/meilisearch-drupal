<?php

/**
 * @file
 * Hooks provided by the Meilisearch module.
 */

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Alters the HTTP headers sent with every request of a server.
 *
 * Called once per server and request, when its Meilisearch client is created.
 *
 * @param array<string, string> $headers
 *   The headers, keyed by name.
 * @param \Drupal\search_api\ServerInterface|null $server
 *   The Search API server, if known.
 */
function hook_meilisearch_request_headers_alter(array &$headers, ?\Drupal\search_api\ServerInterface $server): void {
  $headers['X-MS-USER-ID'] = 'user-' . \Drupal::currentUser()->id();
}

/**
 * Alters the Meilisearch search parameters of a Search API query.
 *
 * @param array $params
 *   The search parameters, as documented at
 *   https://www.meilisearch.com/docs/reference/api/search.
 * @param \Drupal\search_api\Query\QueryInterface $query
 *   The Search API query.
 */
function hook_meilisearch_search_params_alter(array &$params, \Drupal\search_api\Query\QueryInterface $query): void {
  if ($query->getSearchId() === 'views_page:search__page_1') {
    $params['rankingScoreThreshold'] = 0.2;
  }
}

/**
 * @} End of "addtogroup hooks".
 */
