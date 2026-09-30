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
 * Called for the main search and for each extra query computing facet
 * counts (OR facets, zero counts, missing values), so a filter added here
 * restricts the facet counts too.
 *
 * @param array $params
 *   The search parameters, as documented at
 *   https://www.meilisearch.com/docs/reference/api/search.
 * @param \Drupal\search_api\Query\QueryInterface $query
 *   The Search API query.
 * @param array $context
 *   Which query this is. The "query" key is "main", "facet_values",
 *   "facet_all_values" or "facet_missing"; facet queries also have the facet
 *   ID in "facet". Paging and facet parameters of facet queries are reset
 *   after the hook.
 */
function hook_meilisearch_search_params_alter(array &$params, \Drupal\search_api\Query\QueryInterface $query, array $context): void {
  // Only show the current tenant's documents, in results and facet counts.
  $tenant = \Drupal::service('mymodule.tenant')->id();
  $params['filter'] = isset($params['filter']) ? "({$params['filter']}) AND tenant = $tenant" : "tenant = $tenant";
}

/**
 * @} End of "addtogroup hooks".
 */
