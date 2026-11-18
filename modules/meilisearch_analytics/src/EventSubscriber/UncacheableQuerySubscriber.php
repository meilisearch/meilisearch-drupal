<?php

declare(strict_types=1);

namespace Drupal\meilisearch_analytics\EventSubscriber;

use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\meilisearch_analytics\CloudServers;
use Drupal\search_api\Event\QueryPreExecuteEvent;
use Drupal\search_api\Event\SearchApiEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Keeps Meilisearch Cloud searches out of the Search API results cache.
 *
 * Each search gets its own query UID for click attribution; a cached result
 * set would replay one UID for every visitor.
 */
class UncacheableQuerySubscriber implements EventSubscriberInterface {

  /**
   * Constructs an UncacheableQuerySubscriber.
   */
  public function __construct(protected CloudServers $cloudServers) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [SearchApiEvents::QUERY_PRE_EXECUTE => 'onQueryPreExecute'];
  }

  /**
   * Marks queries on Meilisearch Cloud servers as uncacheable.
   */
  public function onQueryPreExecute(QueryPreExecuteEvent $event): void {
    $query = $event->getQuery();
    if ($query instanceof RefinableCacheableDependencyInterface && $this->cloudServers->isCloudIndex($query->getIndex())) {
      $query->mergeCacheMaxAge(0);
    }
  }

}
