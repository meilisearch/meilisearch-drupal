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
  protected LoggerInterface $logger;

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

  public function __sleep(): array {
    $properties = array_flip(parent::__sleep());
    unset($properties['api'], $properties['documentConverter'], $properties['filterBuilder'], $properties['logger']);
    return array_keys($properties);
  }

  public function __wakeup(): void {
    parent::__wakeup();
    $container = \Drupal::getContainer();
    $this->api = $container->get('meilisearch.api');
    $this->documentConverter = $container->get('meilisearch.document_converter');
    $this->filterBuilder = $container->get('meilisearch.filter_builder');
    $this->logger = $container->get('logger.channel.meilisearch');
    $this->configureApi();
  }

}
