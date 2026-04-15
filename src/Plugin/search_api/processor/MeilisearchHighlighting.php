<?php

declare(strict_types=1);

namespace Drupal\meilisearch\Plugin\search_api\processor;

use Drupal\Core\Form\FormStateInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Query\QueryInterface;

/**
 * Adds highlighting/cropping to Meilisearch search queries.
 *
 * @SearchApiProcessor(
 *   id = "meilisearch_highlighting",
 *   label = @Translation("Meilisearch highlighting"),
 *   description = @Translation("Highlights matched terms and crops snippets."),
 *   stages = {"preprocess_query" = 0}
 * )
 */
class MeilisearchHighlighting extends ProcessorPluginBase {

  public static function supportsIndex(IndexInterface $index): bool {
    return $index->getServerInstance()?->getBackendId() === 'meilisearch';
  }

  public function defaultConfiguration(): array {
    return [
      'fields' => [],
      'pre_tag' => '<em>',
      'post_tag' => '</em>',
      'crop_length' => 10,
      'crop_marker' => '…',
    ];
  }

  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $text_fields = [];
    foreach ($this->index->getFields() as $id => $field) {
      if ($field->getType() === 'text') {
        $text_fields[$id] = $field->getLabel();
      }
    }

    $form['fields'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Fields to highlight'),
      '#options' => $text_fields,
      '#default_value' => $this->configuration['fields'],
    ];
    $form['pre_tag'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Pre tag'),
      '#default_value' => $this->configuration['pre_tag'],
    ];
    $form['post_tag'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Post tag'),
      '#default_value' => $this->configuration['post_tag'],
    ];
    $form['crop_length'] = [
      '#type' => 'number',
      '#title' => $this->t('Crop length (words)'),
      '#default_value' => $this->configuration['crop_length'],
      '#min' => 0,
    ];
    $form['crop_marker'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Crop marker'),
      '#default_value' => $this->configuration['crop_marker'],
    ];
    return $form;
  }

  public function preprocessSearchQuery(QueryInterface $query): void {
    // Store the highlighting config on the query so the backend can read it.
    $fields = array_keys(array_filter($this->configuration['fields']));
    if (empty($fields)) {
      return;
    }
    $query->setOption('meilisearch_highlighting', [
      'fields' => $fields,
      'pre_tag' => $this->configuration['pre_tag'],
      'post_tag' => $this->configuration['post_tag'],
      'crop_length' => (int) $this->configuration['crop_length'],
      'crop_marker' => $this->configuration['crop_marker'],
    ]);
  }

}
