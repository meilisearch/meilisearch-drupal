<?php

declare(strict_types=1);

namespace Drupal\meilisearch\Plugin\search_api\backend;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\meilisearch\Api\MeilisearchApiException;
use Drupal\meilisearch\Api\MeilisearchApiServiceInterface;
use Drupal\meilisearch\Converter\DocumentConverterInterface;
use Drupal\meilisearch\Filter\FilterBuilderInterface;
use Drupal\meilisearch\Utility\MeilisearchUtils;
use Drupal\search_api\Backend\BackendPluginBase;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Plugin\PluginFormTrait;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\SearchApiException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Meilisearch backend for Search API.
 *
 * @SearchApiBackend(
 *   id = "meilisearch",
 *   label = @Translation("Meilisearch"),
 *   description = @Translation("Indexes items in Meilisearch.")
 * )
 */
final class MeilisearchBackend extends BackendPluginBase implements PluginFormInterface {

  use PluginFormTrait;

  protected MeilisearchApiServiceInterface $api;
  protected DocumentConverterInterface $documentConverter;
  protected FilterBuilderInterface $filterBuilder;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    MeilisearchApiServiceInterface $api,
    DocumentConverterInterface $documentConverter,
    FilterBuilderInterface $filterBuilder,
    LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->api = $api;
    $this->documentConverter = $documentConverter;
    $this->filterBuilder = $filterBuilder;
    $this->logger = $logger;
    $this->configureApi();
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('meilisearch.api'),
      $container->get('meilisearch.document_converter'),
      $container->get('meilisearch.filter_builder'),
      $container->get('logger.channel.meilisearch'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'connection_mode' => 'self_hosted',
      'host' => 'http://127.0.0.1',
      'port' => 7700,
      'api_key' => '',
      'is_cloud' => FALSE,
      'search_mode' => 'keyword',
      'semantic_ratio' => 0.5,
      'embedder' => '',
    ];
  }

  /**
   * Configures the API service using the current configuration.
   */
  protected function configureApi(): void {
    if ($this->configuration['connection_mode'] === 'cloud') {
      $this->api->setUrl($this->configuration['host']);
    }
    else {
      $this->api->setUrl(rtrim($this->configuration['host'], '/') . ':' . $this->configuration['port']);
    }
    $this->api->setApiKey($this->configuration['api_key']);
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration): void {
    parent::setConfiguration($configuration);
    $this->configureApi();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['connection_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Connection mode'),
      '#default_value' => $this->configuration['connection_mode'],
      '#options' => [
        'self_hosted' => $this->t('Self-hosted Meilisearch'),
        'cloud' => $this->t('Meilisearch Cloud'),
      ],
      '#required' => TRUE,
    ];

    $form['host'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Host URL'),
      '#default_value' => $this->configuration['host'],
      '#description' => $this->t('Self-hosted example: <code>http://127.0.0.1</code>. Cloud example: <code>https://ms-abc.fra.meilisearch.io</code>.'),
      '#required' => TRUE,
    ];

    $form['port'] = [
      '#type' => 'number',
      '#title' => $this->t('Port'),
      '#default_value' => $this->configuration['port'],
      '#min' => 1,
      '#max' => 65535,
      '#states' => [
        'visible' => [
          ':input[name="backend_config[connection_mode]"]' => ['value' => 'self_hosted'],
        ],
      ],
    ];

    $form['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API key'),
      '#default_value' => $this->configuration['api_key'],
      '#description' => $this->t('Master key (self-hosted) or admin API key (Cloud).'),
    ];

    $form['search_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Search mode'),
      '#default_value' => $this->configuration['search_mode'],
      '#options' => [
        'keyword' => $this->t('Keyword (default)'),
        'semantic' => $this->t('Semantic (vector)'),
        'hybrid' => $this->t('Hybrid (keyword + vector)'),
      ],
    ];

    $form['semantic_ratio'] = [
      '#type' => 'number',
      '#title' => $this->t('Semantic ratio'),
      '#default_value' => $this->configuration['semantic_ratio'],
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.01,
      '#description' => $this->t('0.0 = full keyword, 1.0 = full semantic.'),
      '#states' => [
        'visible' => [
          ':input[name="backend_config[search_mode]"]' => ['value' => 'hybrid'],
        ],
      ],
    ];

    $form['embedder'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Embedder name'),
      '#default_value' => $this->configuration['embedder'],
      '#description' => $this->t('Name of an embedder configured on the Meilisearch instance.'),
      '#states' => [
        'invisible' => [
          ':input[name="backend_config[search_mode]"]' => ['value' => 'keyword'],
        ],
      ],
    ];

    if ($this->configuration['connection_mode'] === 'cloud') {
      $form['cloud_dashboard_link'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('<a href="https://cloud.meilisearch.com/projects" target="_blank" rel="noopener noreferrer">Manage synonyms, stop words, ranking rules, and embedders in your Meilisearch Cloud dashboard →</a>') . '</p>',
        '#states' => [
          'visible' => [
            ':input[name="backend_config[connection_mode]"]' => ['value' => 'cloud'],
          ],
        ],
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $host = rtrim((string) $form_state->getValue('host'), '/');
    $form_state->setValue('host', $host);

    if ($form_state->getValue('connection_mode') === 'cloud') {
      if (!preg_match('~^https?://~', $host)) {
        $form_state->setErrorByName('host', $this->t('Cloud URL must include scheme (https://).'));
      }
      // Match the root domain and any subdomain (mirrors MeilisearchApiService::isCloud).
      $form_state->setValue('is_cloud', (bool) preg_match('/(?:^|\.)meilisearch\.io$/i', parse_url($host, PHP_URL_HOST) ?? ''));
    }
    else {
      $form_state->setValue('is_cloud', FALSE);
    }

    if ($form_state->getValue('search_mode') !== 'keyword' && !$form_state->getValue('embedder')) {
      $form_state->setErrorByName('embedder', $this->t('Embedder is required for semantic/hybrid search.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function viewSettings(): array {
    $info = [];
    try {
      $version = $this->api->version();
      $info[] = [
        'label' => $this->t('Meilisearch version'),
        'info' => $version['pkgVersion'] ?? 'unknown',
      ];
      $info[] = [
        'label' => $this->t('Cloud'),
        'info' => $this->api->isCloud() ? $this->t('Yes') : $this->t('No'),
      ];
    }
    catch (MeilisearchApiException $e) {
      $this->logger->error($e->getMessage());
    }
    return $info;
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return $this->api->ping();
  }

  /**
   * {@inheritdoc}
   */
  public function addIndex(IndexInterface $index): void {
    try {
      $task = $this->api->createIndex($index->id());
      $this->api->waitForTask((int) $task['taskUid']);
      $this->updateIndex($index);
    }
    catch (MeilisearchApiException $e) {
      $this->logger->error('Failed to add index @id: @msg', [
        '@id' => $index->id(),
        '@msg' => $e->getMessage(),
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function updateIndex(IndexInterface $index): void {
    try {
      $settings = $this->buildIndexSettings($index);
      $task = $this->api->updateSettings($index->id(), $settings);
      $this->api->waitForTask((int) $task['taskUid']);
    }
    catch (MeilisearchApiException $e) {
      $this->logger->error('Failed to update index @id: @msg', [
        '@id' => $index->id(),
        '@msg' => $e->getMessage(),
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function removeIndex($index): void {
    if (is_object($index) && method_exists($index, 'isReadOnly') && $index->isReadOnly()) {
      return;
    }
    $id = is_object($index) ? $index->id() : (string) $index;
    try {
      $task = $this->api->deleteIndex($id);
      $this->api->waitForTask((int) $task['taskUid']);
    }
    catch (MeilisearchApiException $e) {
      $this->logger->error('Failed to remove index @id: @msg', ['@id' => $id, '@msg' => $e->getMessage()]);
    }
  }

  /**
   * Builds the bulk settings payload for an index.
   */
  protected function buildIndexSettings(IndexInterface $index): array {
    $fields = $index->getFields();
    $fieldNames = array_keys($fields);

    // Sort searchable fields by boost descending.
    $searchable = [];
    foreach ($fields as $id => $field) {
      if ($field->getType() === 'text') {
        $searchable[$id] = $field->getBoost() ?? 1.0;
      }
    }
    arsort($searchable);
    $searchableAttributes = array_keys($searchable);

    // Geo support: if any field is of type 'location', _geo must be filterable + sortable.
    $hasGeo = FALSE;
    foreach ($fields as $field) {
      if ($field->getType() === 'location') {
        $hasGeo = TRUE;
        break;
      }
    }

    $filterable = $fieldNames;
    $sortable = $fieldNames;
    if ($hasGeo) {
      $filterable[] = '_geo';
      $sortable[] = '_geo';
    }

    return [
      'searchableAttributes' => $searchableAttributes ?: ['*'],
      'filterableAttributes' => array_values(array_unique($filterable)),
      'sortableAttributes' => array_values(array_unique($sortable)),
      'displayedAttributes' => ['*'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function indexItems(IndexInterface $index, array $items): array {
    $documents = $this->documentConverter->convertToDocuments($items);
    try {
      $task = $this->api->addDocuments($index->id(), $documents);
      $this->api->waitForTask((int) $task['taskUid']);
    }
    catch (MeilisearchApiException $e) {
      $this->logger->error('Index items failed: @msg', ['@msg' => $e->getMessage()]);
      throw new SearchApiException($e->getMessage(), $e->getCode(), $e);
    }
    return array_keys($items);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteItems(IndexInterface $index, array $item_ids): void {
    $ids = array_map(fn($id) => MeilisearchUtils::formatAsDocumentId($id), $item_ids);
    try {
      $task = $this->api->deleteDocuments($index->id(), $ids);
      $this->api->waitForTask((int) $task['taskUid']);
    }
    catch (MeilisearchApiException $e) {
      $this->logger->error('Delete items failed: @msg', ['@msg' => $e->getMessage()]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAllIndexItems(IndexInterface $index, $datasource_id = NULL): void {
    try {
      $task = $this->api->deleteAllDocuments($index->id());
      $this->api->waitForTask((int) $task['taskUid']);
    }
    catch (MeilisearchApiException $e) {
      $this->logger->error('Delete all items failed: @msg', ['@msg' => $e->getMessage()]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function search(QueryInterface $query): void {
    $results = $query->getResults();
    $index = $query->getIndex();
    $keys = (string) ($query->getOriginalKeys() ?? '');
    $options = [];

    // Pagination.
    $queryOptions = $query->getOptions();
    $options['offset'] = (int) ($queryOptions['offset'] ?? 0);
    $options['limit'] = isset($queryOptions['limit']) && $queryOptions['limit'] > 0
      ? (int) $queryOptions['limit']
      : 20;

    // Filter.
    $filter = $this->filterBuilder->build($query->getConditionGroup(), $index);
    if ($filter !== NULL) {
      $options['filter'] = $filter;
    }

    // Sort.
    $sorts = [];
    foreach ($query->getSorts() as $field => $direction) {
      if (in_array($field, ['search_api_relevance', 'search_api_random'], TRUE)) {
        continue;
      }
      $sorts[] = $field . ':' . strtolower($direction);
    }
    if ($sorts) {
      $options['sort'] = $sorts;
    }

    // Semantic / hybrid.
    $mode = $this->configuration['search_mode'];
    if ($mode !== 'keyword' && !empty($this->configuration['embedder'])) {
      $ratio = $mode === 'semantic' ? 1.0 : (float) $this->configuration['semantic_ratio'];
      $options['hybrid'] = [
        'semanticRatio' => $ratio,
        'embedder' => $this->configuration['embedder'],
      ];
    }

    // Allow other modules to alter options (e.g., facets, highlighting).
    $this->alterSearchOptions($options, $query);

    try {
      $data = $this->api->search($index->id(), $keys, $options);
    }
    catch (MeilisearchApiException $e) {
      $this->logger->error('Search failed: @msg', ['@msg' => $e->getMessage()]);
      throw new SearchApiException($e->getMessage(), $e->getCode(), $e);
    }

    $results->setResultCount($data->getEstimatedTotalHits());
    foreach ($data->getHits() as $hit) {
      if (!isset($hit['search_api_id'])) {
        continue;
      }
      $item = $this->getFieldsHelper()->createItem($index, $hit['search_api_id']);
      if (isset($hit['_formatted'])) {
        $item->setExtraData('meilisearch_highlighted', $hit['_formatted']);
      }
      $results->addResultItem($item);
    }

    // Store raw Meilisearch response for subscribers (facets, analytics).
    $results->setExtraData('meilisearch_response', $data->toArray());
  }

  /**
   * Hook point for submodules and processors to alter search options.
   */
  protected function alterSearchOptions(array &$options, QueryInterface $query): void {
    $hl = $query->getOption('meilisearch_highlighting');
    if (is_array($hl)) {
      $options['attributesToHighlight'] = $hl['fields'];
      $options['highlightPreTag'] = $hl['pre_tag'];
      $options['highlightPostTag'] = $hl['post_tag'];
      $options['attributesToCrop'] = $hl['fields'];
      $options['cropLength'] = $hl['crop_length'];
      $options['cropMarker'] = $hl['crop_marker'];
    }

    $facets = $query->getOption('meilisearch_facets');
    if (is_array($facets) && $facets) {
      $options['facets'] = $facets;
    }

    // Analytics submodule may request queryUid metadata via custom fields.
    if ($query->getOption('meilisearch_include_metadata')) {
      $options['analyticsCustomFields'] = [
        'drupal_query' => $query->getIndex()->id(),
      ];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedFeatures(): array {
    return ['search_api_facets'];
  }

  public function __sleep(): array {
    $properties = array_flip(parent::__sleep());
    unset($properties['api'], $properties['documentConverter'], $properties['filterBuilder'], $properties['logger']);
    return array_keys($properties);
  }

  public function __wakeup(): void {
    if (method_exists(get_parent_class($this), '__wakeup')) {
      parent::__wakeup();
    }
    $container = \Drupal::getContainer();
    $this->api = $container->get('meilisearch.api');
    $this->documentConverter = $container->get('meilisearch.document_converter');
    $this->filterBuilder = $container->get('meilisearch.filter_builder');
    $this->logger = $container->get('logger.channel.meilisearch');
    $this->configureApi();
  }

}
