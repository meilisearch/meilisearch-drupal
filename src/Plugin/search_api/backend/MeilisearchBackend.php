<?php

declare(strict_types=1);

namespace Drupal\meilisearch\Plugin\search_api\backend;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\meilisearch\Api\MeilisearchApiException;
use Drupal\meilisearch\Api\MeilisearchApiFactory;
use Drupal\meilisearch\Api\MeilisearchApiServiceInterface;
use Drupal\meilisearch\Converter\DocumentConverterInterface;
use Drupal\meilisearch\Filter\FilterBuilderInterface;
use Drupal\meilisearch\Filter\FilterValue;
use Drupal\meilisearch\Search\FacetBuilder;
use Drupal\meilisearch\Search\SearchKeys;
use Drupal\meilisearch\Utility\MeilisearchUtils;
use Drupal\search_api\Attribute\SearchApiBackend;
use Drupal\search_api\Backend\BackendPluginBase;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Plugin\PluginFormTrait;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\SearchApiException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Indexes and searches items in Meilisearch.
 */
#[SearchApiBackend(
  id: 'meilisearch',
  label: new TranslatableMarkup('Meilisearch'),
  description: new TranslatableMarkup('Indexes items in Meilisearch, self-hosted or on Meilisearch Cloud.'),
)]
class MeilisearchBackend extends BackendPluginBase implements PluginFormInterface {

  use PluginFormTrait;

  /**
   * Meilisearch caps facet values per facet; this raises the default of 100.
   */
  protected const MAX_VALUES_PER_FACET = 1000;

  /**
   * Ranking rules of the indexes this module creates.
   */
  protected const RANKING_RULES = ['sort', 'words', 'typo', 'proximity', 'attribute', 'exactness'];

  /**
   * The API service of this server, created on first use.
   */
  protected ?MeilisearchApiServiceInterface $api = NULL;

  /**
   * Meilisearch index UIDs known to exist during this request.
   *
   * @var array<string, true>
   */
  protected array $existingIndexes = [];

  /**
   * Constructs a MeilisearchBackend.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected MeilisearchApiFactory $apiFactory,
    protected DocumentConverterInterface $documentConverter,
    protected FilterBuilderInterface $filterBuilder,
    protected ModuleHandlerInterface $moduleHandler,
    LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $plugin = new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('meilisearch.api_factory'),
      $container->get('meilisearch.document_converter'),
      $container->get('meilisearch.filter_builder'),
      $container->get('module_handler'),
      $container->get('logger.channel.meilisearch'),
    );
    $plugin->setFieldsHelper($container->get('search_api.fields_helper'));
    $plugin->setMessenger($container->get('messenger'));
    return $plugin;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'url' => 'http://127.0.0.1:7700',
      'api_key' => '',
      'index_prefix' => '',
      'search_mode' => 'keyword',
      'semantic_ratio' => 0.5,
      'embedder' => '',
      'matching_strategy' => 'last',
      'max_total_hits' => 10000,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration): void {
    parent::setConfiguration($configuration);
    $this->api = NULL;
    $this->existingIndexes = [];
  }

  /**
   * {@inheritdoc}
   *
   * A new URL, key or prefix points at other Meilisearch indexes: set them up
   * and re-index. A new result limit only needs the settings pushed again.
   */
  public function postUpdate(): bool {
    $server = $this->getServer();
    // Drupal 11.2 added getOriginal(); earlier versions use the property.
    $original = method_exists($server, 'getOriginal') ? $server->getOriginal() : ($server->original ?? NULL);
    if (!$original) {
      return FALSE;
    }
    $old = $original->getBackendConfig();
    $changed = fn(string $key) => ($old[$key] ?? NULL) !== ($this->configuration[$key] ?? NULL);

    if ($changed('url') || $changed('api_key') || $changed('index_prefix')) {
      foreach ($server->getIndexes() as $index) {
        $this->addIndex($index);
      }
      return TRUE;
    }
    if ($changed('max_total_hits')) {
      foreach ($server->getIndexes() as $index) {
        if (!$index->isReadOnly()) {
          $this->pushSettings($index);
        }
      }
    }
    return FALSE;
  }

  /**
   * Returns the API service of this server.
   *
   * Other modules can add HTTP headers through
   * hook_meilisearch_request_headers_alter().
   */
  public function getApi(): MeilisearchApiServiceInterface {
    if ($this->api === NULL) {
      $headers = [];
      $server = $this->getServer();
      $this->moduleHandler->alter('meilisearch_request_headers', $headers, $server);
      $this->api = $this->apiFactory->create((string) $this->configuration['url'], (string) $this->configuration['api_key'], $headers);
    }
    return $this->api;
  }

  /**
   * Returns the Meilisearch index UID of a Search API index.
   */
  public function getIndexUid(IndexInterface|string $index): string {
    $id = $index instanceof IndexInterface ? (string) $index->id() : $index;
    return $this->configuration['index_prefix'] . $id;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['url'] = [
      '#type' => 'url',
      '#title' => $this->t('Meilisearch URL'),
      '#default_value' => $this->configuration['url'],
      '#description' => $this->t('Including the port. Self-hosted: <code>http://127.0.0.1:7700</code>. Cloud: <code>https://ms-abc123.fra.meilisearch.io</code>.'),
      '#required' => TRUE,
    ];

    $form['api_key'] = [
      '#type' => 'password',
      '#title' => $this->t('API key'),
      '#description' => $this->t('An API key with the <em>search</em>, <em>documents.*</em>, <em>indexes.*</em>, <em>settings.*</em>, <em>tasks.get</em> and <em>version</em> actions. Avoid the master key. @state To keep the key out of exported configuration, set it in settings.php: <code>@snippet</code>', [
        '@state' => $this->configuration['api_key'] !== '' ? $this->t('A key is stored; leave empty to keep it.') : '',
        '@snippet' => "\$config['search_api.server.SERVER_ID']['backend_config']['api_key'] = getenv('MEILISEARCH_API_KEY');",
      ]),
    ];

    $form['index_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Index prefix'),
      '#default_value' => $this->configuration['index_prefix'],
      '#description' => $this->t('Prepended to the Meilisearch index names, so several sites or environments can share one instance (for example <code>prod_</code>). Letters, digits, <code>-</code> and <code>_</code>. Changing it requires re-indexing.'),
    ];

    $form['search_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Search mode'),
      '#default_value' => $this->configuration['search_mode'],
      '#options' => [
        'keyword' => $this->t('Keyword'),
        'hybrid' => $this->t('Hybrid (keyword and semantic)'),
        'semantic' => $this->t('Semantic'),
      ],
    ];

    $form['embedder'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Embedder'),
      '#default_value' => $this->configuration['embedder'],
      '#description' => $this->t('The name of an embedder configured on the Meilisearch index.'),
      '#states' => [
        'invisible' => [':input[name="backend_config[search_mode]"]' => ['value' => 'keyword']],
      ],
    ];

    $form['semantic_ratio'] = [
      '#type' => 'number',
      '#title' => $this->t('Semantic ratio'),
      '#default_value' => $this->configuration['semantic_ratio'],
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.05,
      '#description' => $this->t('0 is pure keyword search, 1 pure semantic search.'),
      '#states' => [
        'visible' => [':input[name="backend_config[search_mode]"]' => ['value' => 'hybrid']],
      ],
    ];

    $form['matching_strategy'] = [
      '#type' => 'select',
      '#title' => $this->t('Matching strategy'),
      '#default_value' => $this->configuration['matching_strategy'],
      '#options' => [
        'last' => $this->t('Last: documents with all words first, then with fewer (Meilisearch default)'),
        'all' => $this->t('All: only documents containing all words'),
        'frequency' => $this->t('Frequency: drop the most frequent words first'),
      ],
    ];

    $form['max_total_hits'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum number of results'),
      '#default_value' => $this->configuration['max_total_hits'],
      '#min' => 1,
      '#description' => $this->t('How many results a search can page through. Higher values make deep pages slower.'),
    ];

    if ($this->configuration['url'] && $this->getApi()->isCloud()) {
      $form['cloud'] = [
        '#type' => 'item',
        '#markup' => $this->t('Manage synonyms, stop words, ranking rules and embedders in the <a href=":url" target="_blank" rel="noopener noreferrer">Meilisearch Cloud dashboard</a>.', [':url' => 'https://cloud.meilisearch.com/projects']),
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $url = rtrim(trim((string) $form_state->getValue('url')), '/');
    if (!UrlHelper::isValid($url, TRUE) || !preg_match('~^https?://~i', $url)) {
      $form_state->setErrorByName('url', $this->t('Enter a full URL starting with http:// or https://.'));
      return;
    }
    $form_state->setValue('url', $url);

    if (!preg_match('/^[A-Za-z0-9_-]*$/', (string) $form_state->getValue('index_prefix'))) {
      $form_state->setErrorByName('index_prefix', $this->t('The index prefix may only contain letters, digits, - and _.'));
    }
    if ($form_state->getValue('search_mode') !== 'keyword' && trim((string) $form_state->getValue('embedder')) === '') {
      $form_state->setErrorByName('embedder', $this->t('Semantic and hybrid search need an embedder.'));
    }

    $key = (string) $form_state->getValue('api_key');
    $api = $this->apiFactory->create($url, $key !== '' ? $key : (string) $this->configuration['api_key']);
    try {
      $api->version();
    }
    catch (MeilisearchApiException $e) {
      $this->messenger->addWarning($this->t('Could not connect to Meilisearch at %url: @message', [
        '%url' => $url,
        '@message' => $e->getMessage(),
      ]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValues();
    $this->setConfiguration([
      'url' => (string) $values['url'],
      'api_key' => ($values['api_key'] ?? '') !== '' ? (string) $values['api_key'] : (string) $this->configuration['api_key'],
      'index_prefix' => (string) ($values['index_prefix'] ?? ''),
      'search_mode' => (string) $values['search_mode'],
      'semantic_ratio' => (float) $values['semantic_ratio'],
      'embedder' => trim((string) $values['embedder']),
      'matching_strategy' => (string) $values['matching_strategy'],
      'max_total_hits' => max(1, (int) $values['max_total_hits']),
    ] + $this->configuration);
  }

  /**
   * {@inheritdoc}
   */
  public function viewSettings(): array {
    $api = $this->getApi();
    $info = [
      ['label' => $this->t('URL'), 'info' => $api->getUrl()],
      ['label' => $this->t('Meilisearch Cloud'), 'info' => $api->isCloud() ? $this->t('Yes') : $this->t('No')],
    ];
    if ($this->configuration['index_prefix'] !== '') {
      $info[] = ['label' => $this->t('Index prefix'), 'info' => $this->configuration['index_prefix']];
    }
    try {
      $version = $api->version();
      $info[] = ['label' => $this->t('Meilisearch version'), 'info' => $version['pkgVersion'] ?? $this->t('Unknown')];
    }
    catch (MeilisearchApiException $e) {
      $info[] = [
        'label' => $this->t('Connection'),
        'info' => $this->t('Failed: @message', ['@message' => $e->getMessage()]),
        'status' => 'error',
      ];
    }
    return $info;
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return $this->getApi()->ping();
  }

  /**
   * {@inheritdoc}
   */
  public function supportsDataType($type): bool {
    // Provided by the search_api_location module; stored as _geo.
    return $type === 'location';
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedFeatures(): array {
    return ['search_api_facets', 'search_api_facets_operator_or'];
  }

  /**
   * {@inheritdoc}
   */
  public function getDiscouragedProcessors(): array {
    // Meilisearch tokenizes, normalizes and handles typos itself; these
    // processors would alter the text it receives and hurt relevancy.
    return ['ignorecase', 'snowball_stemmer', 'stemmer', 'stopwords', 'tokenizer', 'transliteration'];
  }

  /**
   * {@inheritdoc}
   */
  public function addIndex(IndexInterface $index): void {
    // A read-only index belongs to someone else (another site, production):
    // never create it or change its settings.
    if ($index->isReadOnly()) {
      return;
    }
    $uid = $this->getIndexUid($index);
    $created = FALSE;
    try {
      $task = $this->getApi()->createIndex($uid, DocumentConverterInterface::PRIMARY_KEY);
      $this->getApi()->waitForTask((int) $task['taskUid']);
      $created = TRUE;
    }
    catch (MeilisearchApiException $e) {
      if ($e->getErrorCode() !== 'index_already_exists') {
        throw $this->wrap($e, 'create index', $uid);
      }
    }
    // Search API expects explicit sorts to be strict, while Meilisearch ranks
    // "sort" after the relevancy rules by default. Moving it first has no
    // effect on searches without a sort. Only done for new indexes: ranking
    // rules are then managed in Meilisearch (dashboard, API or CLI).
    $this->pushSettings($index, $created ? ['rankingRules' => self::RANKING_RULES] : []);
    $this->existingIndexes[$uid] = TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function updateIndex(IndexInterface $index): void {
    if ($index->isReadOnly()) {
      return;
    }
    $this->pushSettings($index);
    // Drupal 11.2 added getOriginal(); earlier versions use the property.
    // @phpstan-ignore function.alreadyNarrowedType
    $original = method_exists($index, 'getOriginal') ? $index->getOriginal() : ($index->original ?? NULL);
    if ($original instanceof IndexInterface && $this->fieldSignature($original) !== $this->fieldSignature($index)) {
      $index->reindex();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function removeIndex($index): void {
    // Following Search API: only drop data we know is not read-only.
    if (!$index instanceof IndexInterface || $index->isReadOnly()) {
      return;
    }
    $uid = $this->getIndexUid($index);
    try {
      $task = $this->getApi()->deleteIndex($uid);
      $this->getApi()->waitForTask((int) $task['taskUid']);
    }
    catch (MeilisearchApiException $e) {
      if ($e->getErrorCode() !== 'index_not_found') {
        throw $this->wrap($e, 'delete index', $uid);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function indexItems(IndexInterface $index, array $items): array {
    if (!$items) {
      return [];
    }
    $this->ensureIndex($index);
    $uid = $this->getIndexUid($index);
    try {
      $task = $this->getApi()->addDocuments($uid, $this->documentConverter->convertToDocuments($items), DocumentConverterInterface::PRIMARY_KEY);
      $this->getApi()->waitForTask((int) $task['taskUid']);
    }
    catch (MeilisearchApiException $e) {
      throw $this->wrap($e, 'index items in', $uid);
    }
    return array_keys($items);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteItems(IndexInterface $index, array $item_ids): void {
    if (!$item_ids) {
      return;
    }
    $ids = array_map([MeilisearchUtils::class, 'encodeDocumentId'], array_values($item_ids));
    $this->runDeletion($index, fn(string $uid) => $this->getApi()->deleteDocuments($uid, $ids));
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAllIndexItems(IndexInterface $index, $datasource_id = NULL): void {
    if ($datasource_id === NULL) {
      $this->runDeletion($index, fn(string $uid) => $this->getApi()->deleteAllDocuments($uid));
      return;
    }
    $filter = 'search_api_datasource = ' . FilterValue::quote((string) $datasource_id);
    $this->runDeletion($index, fn(string $uid) => $this->getApi()->deleteDocumentsByFilter($uid, $filter));
  }

  /**
   * {@inheritdoc}
   */
  public function search(QueryInterface $query): void {
    $index = $query->getIndex();
    $uid = $this->getIndexUid($index);
    $results = $query->getResults();

    $params = $this->buildSearchParams($query);
    $extra_filters = [$this->languageFilter($query), $this->locationFilter($query)];
    $facets = new FacetBuilder($query, $this->filterBuilder, $params, $extra_filters);
    $params = $facets->alterMainQuery($params);
    $context = ['query' => 'main'];
    $this->moduleHandler->alter('meilisearch_search_params', $params, $query, $context);

    try {
      $extra = $facets->extraQueries($uid, function (array &$facet_params, array $context) use ($query): void {
        $this->moduleHandler->alter('meilisearch_search_params', $facet_params, $query, $context);
      });
      if ($extra) {
        $responses = $this->getApi()->multiSearch(array_merge([['indexUid' => $uid] + $params], $extra));
        $response = array_shift($responses);
      }
      else {
        $response = $this->getApi()->search($uid, (string) ($params['q'] ?? ''), array_diff_key($params, ['q' => TRUE]));
        $responses = [];
      }
    }
    catch (MeilisearchApiException $e) {
      // Do not let Search API or Views cache the empty result.
      if ($query instanceof RefinableCacheableDependencyInterface) {
        $query->mergeCacheMaxAge(0);
      }
      throw $this->wrap($e, 'search', $uid);
    }

    $results->setResultCount((int) ($response['totalHits'] ?? $response['estimatedTotalHits'] ?? 0));
    foreach ($response['hits'] ?? [] as $hit) {
      if (!isset($hit['search_api_id'])) {
        continue;
      }
      $item = $this->getFieldsHelper()->createItem($index, $hit['search_api_id']);
      if (isset($hit['_rankingScore'])) {
        $item->setScore((float) $hit['_rankingScore']);
      }
      if (isset($hit['_formatted'])) {
        $item->setExtraData('meilisearch_formatted', $hit['_formatted']);
      }
      $results->addResultItem($item);
    }

    if ($facets->hasFacets()) {
      $results->setExtraData('search_api_facets', $facets->buildResults($response, $responses));
    }
    if (isset($response['metadata']['queryUid'])) {
      $results->setExtraData('meilisearch_query_uid', $response['metadata']['queryUid']);
    }
    $results->setExtraData('meilisearch_index_uid', $uid);
    if (isset($response['processingTimeMs'])) {
      $results->setExtraData('meilisearch_processing_time_ms', (int) $response['processingTimeMs']);
    }
  }

  /**
   * Builds the Meilisearch search parameters of a query, facets aside.
   *
   * @throws \Drupal\search_api\SearchApiException
   */
  protected function buildSearchParams(QueryInterface $query): array {
    $index = $query->getIndex();
    $params = [
      'q' => SearchKeys::toMeilisearch($query->getKeys()),
      'attributesToRetrieve' => ['search_api_id'],
      'showRankingScore' => TRUE,
      'matchingStrategy' => $this->configuration['matching_strategy'],
    ];

    $fulltext_fields = $query->getFulltextFields();
    if ($fulltext_fields !== NULL) {
      $params['attributesToSearchOn'] = array_values($fulltext_fields);
    }

    $filter = FacetBuilder::combine([
      $this->filterBuilder->build($query->getConditionGroup(), $index),
      $this->languageFilter($query),
      $this->locationFilter($query),
    ]);
    if ($filter !== NULL) {
      $params['filter'] = $filter;
    }

    $sorts = $this->buildSorts($query);
    if ($sorts) {
      $params['sort'] = $sorts;
    }

    $params += $this->buildPagination($query);

    if ($this->configuration['search_mode'] !== 'keyword' && $this->configuration['embedder'] !== '') {
      $params['hybrid'] = [
        'embedder' => $this->configuration['embedder'],
        'semanticRatio' => $this->configuration['search_mode'] === 'semantic' ? 1.0 : (float) $this->configuration['semantic_ratio'],
      ];
    }

    $highlight = $query->getOption('meilisearch_highlighting');
    if (is_array($highlight) && !empty($highlight['fields'])) {
      $params['attributesToHighlight'] = $highlight['fields'];
      $params['highlightPreTag'] = $highlight['pre_tag'];
      $params['highlightPostTag'] = $highlight['post_tag'];
      $params['attributesToCrop'] = $highlight['fields'];
      $params['cropLength'] = $highlight['crop_length'];
      $params['cropMarker'] = $highlight['crop_marker'];
    }

    return $params;
  }

  /**
   * Returns the filter restricting results to the query's languages.
   */
  protected function languageFilter(QueryInterface $query): ?string {
    $languages = $query->getLanguages();
    if ($languages === NULL) {
      return NULL;
    }
    return 'search_api_language IN [' . implode(', ', array_map([FilterValue::class, 'quote'], $languages)) . ']';
  }

  /**
   * Returns the radius filter set by the search_api_location module, if any.
   */
  protected function locationFilter(QueryInterface $query): ?string {
    $location = $this->getLocationOption($query);
    if (!$location || !isset($location['radius'])) {
      return NULL;
    }
    // search_api_location radiuses are in kilometers.
    return sprintf('_geoRadius(%F, %F, %d)', $location['lat'], $location['lon'], (int) round($location['radius'] * 1000));
  }

  /**
   * Returns the first search_api_location option of a query, if any.
   *
   * @return array|null
   *   The location option: "field", "lat", "lon" and optionally "radius".
   */
  protected function getLocationOption(QueryInterface $query): ?array {
    foreach ((array) $query->getOption('search_api_location', []) as $location) {
      if (isset($location['field'], $location['lat'], $location['lon'])) {
        return [
          'field' => (string) $location['field'],
          'lat' => (float) $location['lat'],
          'lon' => (float) $location['lon'],
        ] + (isset($location['radius']) ? ['radius' => (float) $location['radius']] : []);
      }
    }
    return NULL;
  }

  /**
   * Builds the sort parameter.
   *
   * @return string[]
   *   Meilisearch sort expressions.
   */
  protected function buildSorts(QueryInterface $query): array {
    $index = $query->getIndex();
    $location = $this->getLocationOption($query);
    $sorts = [];
    $query_sorts = $query->getSorts();
    // "Relevance, then X" with keys means relevance order: sorts are strict
    // in the indexes this module creates, so X must not be sent.
    if (array_key_first($query_sorts) === 'search_api_relevance' && $query->getKeys()) {
      return [];
    }
    foreach ($query_sorts as $field => $direction) {
      $direction = strtolower((string) $direction) === 'desc' ? 'desc' : 'asc';
      if ($field === 'search_api_relevance' || $field === 'search_api_random') {
        // Relevance is Meilisearch's default order; random is not supported.
        continue;
      }
      if ($index->getField($field)?->getType() === 'location') {
        if ($location) {
          $sorts[] = sprintf('_geoPoint(%F, %F):%s', $location['lat'], $location['lon'], $direction);
        }
        continue;
      }
      $sorts[] = $field . ':' . $direction;
    }
    return $sorts;
  }

  /**
   * Builds the pagination parameters.
   *
   * Uses page mode whenever the offset is a multiple of the limit, because
   * only page mode returns an exact total.
   */
  protected function buildPagination(QueryInterface $query): array {
    $offset = max(0, (int) $query->getOption('offset', 0));
    $limit = $query->getOption('limit');
    $limit = $limit === NULL ? (int) $this->configuration['max_total_hits'] : max(0, (int) $limit);
    if ($limit === 0) {
      return ['page' => 1, 'hitsPerPage' => 0];
    }
    if ($offset % $limit === 0) {
      return ['page' => intdiv($offset, $limit) + 1, 'hitsPerPage' => $limit];
    }
    return ['offset' => $offset, 'limit' => $limit];
  }

  /**
   * Builds the settings of a Meilisearch index from its Search API index.
   */
  protected function buildIndexSettings(IndexInterface $index): array {
    $searchable = [];
    $attributes = DocumentConverterInterface::SPECIAL_FIELDS;
    $has_location = FALSE;
    foreach ($index->getFields() as $id => $field) {
      if (str_starts_with($id, '_')) {
        continue;
      }
      if ($field->getType() === 'location') {
        $has_location = TRUE;
        continue;
      }
      $attributes[] = $id;
      if ($field->getType() === 'text') {
        $searchable[$id] = $field->getBoost() ?? 1.0;
      }
    }
    // Most boosted fields first, keeping field order on ties.
    $order = array_flip(array_keys($searchable));
    uksort($searchable, fn($a, $b) => [$searchable[$b], $order[$a]] <=> [$searchable[$a], $order[$b]]);
    if ($has_location) {
      $attributes[] = '_geo';
    }

    return [
      'searchableAttributes' => $searchable ? array_keys($searchable) : ['*'],
      'filterableAttributes' => $attributes,
      'sortableAttributes' => $attributes,
      'displayedAttributes' => ['*'],
      'pagination' => ['maxTotalHits' => (int) $this->configuration['max_total_hits']],
      // Rank values by count before Meilisearch truncates them.
      'faceting' => ['maxValuesPerFacet' => self::MAX_VALUES_PER_FACET, 'sortFacetValuesBy' => ['*' => 'count']],
    ];
  }

  /**
   * Sends the index settings to Meilisearch.
   *
   * @throws \Drupal\search_api\SearchApiException
   */
  protected function pushSettings(IndexInterface $index, array $extra = []): void {
    $uid = $this->getIndexUid($index);
    try {
      $task = $this->getApi()->updateSettings($uid, $this->buildIndexSettings($index) + $extra);
      $this->getApi()->waitForTask((int) $task['taskUid']);
    }
    catch (MeilisearchApiException $e) {
      throw $this->wrap($e, 'update settings of', $uid);
    }
  }

  /**
   * Sets up the Meilisearch index if it does not exist.
   *
   * Meilisearch would otherwise create it on the first documents, without
   * filterable or sortable attributes. That happens when an environment
   * points at a fresh instance through a settings.php override.
   *
   * @throws \Drupal\search_api\SearchApiException
   */
  protected function ensureIndex(IndexInterface $index): void {
    $uid = $this->getIndexUid($index);
    if (isset($this->existingIndexes[$uid])) {
      return;
    }
    try {
      $exists = $this->getApi()->indexExists($uid);
    }
    catch (MeilisearchApiException $e) {
      throw $this->wrap($e, 'check', $uid);
    }
    if (!$exists) {
      $this->addIndex($index);
    }
    $this->existingIndexes[$uid] = TRUE;
  }

  /**
   * Returns what, in an index's fields, requires re-indexing when changed.
   */
  protected function fieldSignature(IndexInterface $index): array {
    $signature = [];
    foreach ($index->getFields() as $id => $field) {
      $signature[$id] = [$field->getType(), $field->getDatasourceId(), $field->getPropertyPath()];
    }
    ksort($signature);
    return $signature;
  }

  /**
   * Runs a deletion task. A missing Meilisearch index has nothing to delete.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The index.
   * @param callable $delete
   *   Enqueues the deletion for an index UID and returns the task.
   *
   * @throws \Drupal\search_api\SearchApiException
   */
  protected function runDeletion(IndexInterface $index, callable $delete): void {
    $uid = $this->getIndexUid($index);
    try {
      $task = $delete($uid);
      $this->getApi()->waitForTask((int) $task['taskUid']);
    }
    catch (MeilisearchApiException $e) {
      if ($e->getErrorCode() !== 'index_not_found') {
        throw $this->wrap($e, 'delete items from', $uid);
      }
    }
  }

  /**
   * Logs a Meilisearch failure and converts it for Search API.
   */
  protected function wrap(MeilisearchApiException $e, string $operation, string $uid): SearchApiException {
    $message = sprintf('Meilisearch could not %s index "%s": %s', $operation, $uid, $e->getMessage());
    $this->logger->error($message);
    return new SearchApiException($message, 0, $e);
  }

  /**
   * {@inheritdoc}
   */
  public function __sleep(): array {
    $properties = array_flip(parent::__sleep());
    unset($properties['api'], $properties['apiFactory'], $properties['documentConverter'], $properties['filterBuilder'], $properties['moduleHandler']);
    return array_keys($properties);
  }

  /**
   * {@inheritdoc}
   */
  public function __wakeup(): void {
    parent::__wakeup();
    // Services cannot be injected into an unserialized plugin.
    // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
    $container = \Drupal::getContainer();
    $this->apiFactory = $container->get('meilisearch.api_factory');
    $this->documentConverter = $container->get('meilisearch.document_converter');
    $this->filterBuilder = $container->get('meilisearch.filter_builder');
    $this->moduleHandler = $container->get('module_handler');
    $this->logger = $container->get('logger.channel.meilisearch');
  }

}
