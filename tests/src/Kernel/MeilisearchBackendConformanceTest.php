<?php

declare(strict_types=1);

namespace Drupal\Tests\meilisearch\Kernel;

use Drupal\Tests\search_api\Kernel\BackendTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Runs Search API's backend conformance suite against a real Meilisearch.
 *
 * @group meilisearch
 */
#[RunTestsInSeparateProcesses]
class MeilisearchBackendConformanceTest extends BackendTestBase {

  use MeilisearchTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'meilisearch',
    'meilisearch_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected $serverId = 'meilisearch_test_server';

  /**
   * {@inheritdoc}
   */
  protected $indexId = 'meilisearch_test_index';

  /**
   * {@inheritdoc}
   */
  public function setUp(): void {
    $this->setUpMeilisearchConnection([$this->serverId]);
    parent::setUp();
    $this->installConfig(['meilisearch_test']);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->tearDownMeilisearch();
    parent::tearDown();
  }

  /**
   * {@inheritdoc}
   */
  protected function checkServerBackend() {
    $uid = $this->getServer()->getBackend()->getIndexUid($this->getIndex());
    $this->assertSame([$uid], $this->testIndexUids(), 'Adding the index created it in Meilisearch.');
    $settings = $this->meilisearchClient()->index($uid)->getSettings();
    $this->assertContains('search_api_language', $settings['filterableAttributes']);
    $this->assertContains('search_api_datasource', $settings['filterableAttributes']);
    $this->assertSame(['name', 'body'], $settings['searchableAttributes'], 'Boosted fields are searched first.');
    $this->assertSame('search_api_document_id', $this->meilisearchClient()->index($uid)->fetchPrimaryKey());
  }

  /**
   * {@inheritdoc}
   */
  protected function checkModuleUninstall() {
    $this->getIndex()->delete();
    $this->assertSame([], $this->testIndexUids(), 'Deleting the index removed it from Meilisearch.');
  }

}
