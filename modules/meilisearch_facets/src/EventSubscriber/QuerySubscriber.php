<?php

declare(strict_types=1);

namespace Drupal\meilisearch_facets\EventSubscriber;

use Drupal\search_api\Event\QueryPreExecuteEvent;
use Drupal\search_api\Event\SearchApiEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Adds requested facets to Meilisearch query options before execution.
 */
class QuerySubscriber implements EventSubscriberInterface {

  public static function getSubscribedEvents(): array {
    return [SearchApiEvents::QUERY_PRE_EXECUTE => 'onPreExecute'];
  }

  public function onPreExecute(QueryPreExecuteEvent $event): void {
    $query = $event->getQuery();
    if ($query->getIndex()->getServerInstance()?->getBackendId() !== 'meilisearch') {
      return;
    }
    $facets = $query->getOption('search_api_facets', []);
    if (empty($facets)) {
      return;
    }
    $fields = [];
    foreach ($facets as $info) {
      if (isset($info['field'])) {
        $fields[] = $info['field'];
      }
    }
    if ($fields) {
      $query->setOption('meilisearch_facets', array_values(array_unique($fields)));
    }
  }

}
