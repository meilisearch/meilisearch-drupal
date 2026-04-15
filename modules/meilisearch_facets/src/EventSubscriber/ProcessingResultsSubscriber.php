<?php

declare(strict_types=1);

namespace Drupal\meilisearch_facets\EventSubscriber;

use Drupal\search_api\Event\ProcessingResultsEvent;
use Drupal\search_api\Event\SearchApiEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Parses facet data from Meilisearch response into Facets module format.
 */
class ProcessingResultsSubscriber implements EventSubscriberInterface {

  public static function getSubscribedEvents(): array {
    return [SearchApiEvents::PROCESSING_RESULTS => 'onProcessing'];
  }

  public function onProcessing(ProcessingResultsEvent $event): void {
    $results = $event->getResults();
    $query = $results->getQuery();
    if ($query->getIndex()->getServerInstance()?->getBackendId() !== 'meilisearch') {
      return;
    }
    $raw = $results->getExtraData('meilisearch_response');
    if (!is_array($raw)) {
      return;
    }
    $distribution = $raw['facetDistribution'] ?? [];
    $facetData = [];
    foreach ($distribution as $field => $buckets) {
      foreach ($buckets as $value => $count) {
        $facetData[$field][] = [
          'count' => $count,
          'filter' => '"' . $value . '"',
        ];
      }
    }
    if ($facetData) {
      $results->setExtraData('search_api_facets', $facetData);
    }
  }

}
