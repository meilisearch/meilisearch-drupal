<?php

declare(strict_types=1);

namespace Drupal\meilisearch_facets\EventSubscriber;

use Drupal\search_api\Event\DeterminingServerFeaturesEvent;
use Drupal\search_api\Event\SearchApiEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Advertises facet support for the Meilisearch backend.
 */
class DeterminingServerFeaturesSubscriber implements EventSubscriberInterface {

  public static function getSubscribedEvents(): array {
    return [SearchApiEvents::DETERMINING_SERVER_FEATURES => 'onDetermining'];
  }

  public function onDetermining(DeterminingServerFeaturesEvent $event): void {
    if ($event->getBackend()->getPluginId() !== 'meilisearch') {
      return;
    }
    $features = $event->getFeatures();
    if (!in_array('search_api_facets', $features, TRUE)) {
      $features[] = 'search_api_facets';
    }
    $event->setFeatures($features);
  }

}
