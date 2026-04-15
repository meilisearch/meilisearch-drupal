<?php

declare(strict_types=1);

namespace Drupal\meilisearch_analytics\EventSubscriber;

use Drupal\search_api\Event\QueryPreExecuteEvent;
use Drupal\search_api\Event\SearchApiEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Marks queries so the backend includes Meili-Include-Metadata header.
 */
class SearchMetadataSubscriber implements EventSubscriberInterface {

  public static function getSubscribedEvents(): array {
    return [SearchApiEvents::QUERY_PRE_EXECUTE => 'onPreExecute'];
  }

  public function onPreExecute(QueryPreExecuteEvent $event): void {
    $query = $event->getQuery();
    if ($query->getIndex()->getServerInstance()?->getBackendId() !== 'meilisearch') {
      return;
    }
    $query->setOption('meilisearch_include_metadata', TRUE);
  }

}
