(function (Drupal) {
  'use strict';

  Drupal.behaviors.meilisearchClickTracking = {
    attach: function (context) {
      const links = context.querySelectorAll('[data-meilisearch-result]');
      links.forEach(function (link) {
        if (link.dataset.meiliBound) return;
        link.dataset.meiliBound = '1';
        link.addEventListener('click', function () {
          const payload = {
            indexUid: link.dataset.meilisearchIndex,
            queryUid: link.dataset.meilisearchQueryuid,
            objectId: link.dataset.meilisearchResult,
            position: parseInt(link.dataset.meilisearchPosition || '0', 10)
          };
          fetch('/meilisearch/events/click', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
            keepalive: true
          });
        });
      });
    }
  };
})(Drupal);
