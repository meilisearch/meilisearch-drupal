<?php

declare(strict_types=1);

namespace Drupal\Tests\meilisearch\Kernel;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\PageCache\ResponsePolicyInterface;
use Drupal\Core\Session\UserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\meilisearch\Api\MeilisearchApiFactory;
use Drupal\meilisearch\Api\MeilisearchApiServiceInterface;
use Drupal\search_api\Entity\Index;
use Drupal\Tests\search_api\Functional\ExampleContentTrait;
use Drupal\views\Views;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the Meilisearch analytics submodule.
 *
 * The instance under test is self-hosted. Tests of the Meilisearch Cloud
 * behaviour call useCloud(), which makes its API service answer isCloud()
 * with TRUE and record events instead of sending them.
 *
 * @group meilisearch
 */
#[RunTestsInSeparateProcesses]
class MeilisearchAnalyticsTest extends KernelTestBase {

  use ExampleContentTrait;
  use MeilisearchTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'field',
    'search_api',
    'user',
    'system',
    'entity_test',
    'filter',
    'text',
    'views',
    'search_api_test_example_content',
    'meilisearch',
    'meilisearch_test',
    'meilisearch_analytics',
  ];

  /**
   * Whether the test server poses as a Meilisearch Cloud project.
   */
  protected bool $cloud = FALSE;

  /**
   * The extra HTTP headers of each API service created during the test.
   *
   * @var array<int, array<string, string>>
   */
  protected array $requestHeaders = [];

  /**
   * The analytics events sent during the test.
   *
   * @var array<int, array<string, mixed>>
   */
  protected array $sentEvents = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->setUpMeilisearchConnection(['meilisearch_test_server']);
    parent::setUp();
    $this->decorateApiFactory();
    $this->installSchema('search_api', ['search_api_item']);
    $this->installEntitySchema('entity_test_mulrev_changed');
    $this->installEntitySchema('search_api_task');
    $this->installEntitySchema('user');
    $this->installConfig(['search_api', 'search_api_test_example_content', 'meilisearch_test']);
    $this->setUpExampleStructure();
    $this->insertExampleContent();
    $this->indexItems('meilisearch_test_index');
    // Messages shown while setting up have triggered the page cache kill
    // switch: give the code under test a new one.
    $this->container->set('page_cache_kill_switch', new KillSwitch());
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->tearDownMeilisearch();
    parent::tearDown();
  }

  /**
   * Replaces the API factory with one whose services can pose as Cloud.
   *
   * The services it returns are the real ones, talking to the instance under
   * test, except for two methods: isCloud() answers with $this->cloud, and
   * sendEvent() records the event instead of calling the events route, which
   * only Meilisearch Cloud has.
   */
  protected function decorateApiFactory(): void {
    $real_factory = $this->container->get('meilisearch.api_factory');
    $factory = $this->createStub(MeilisearchApiFactory::class);
    $factory->method('create')->willReturnCallback(function (string $url, string $api_key, array $headers = []) use ($real_factory): MeilisearchApiServiceInterface {
      $this->requestHeaders[] = $headers;
      $real_api = $real_factory->create($url, $api_key, $headers);
      $api = $this->createStub(MeilisearchApiServiceInterface::class);
      foreach (get_class_methods(MeilisearchApiServiceInterface::class) as $method) {
        $api->method($method)->willReturnCallback(match ($method) {
          'isCloud' => fn(): bool => $this->cloud,
          'sendEvent' => function (array $event): void {
            $this->sentEvents[] = $event;
          },
          default => fn(...$arguments) => $real_api->$method(...$arguments),
        });
      }
      return $api;
    });
    $this->container->set('meilisearch.api_factory', $factory);
  }

  /**
   * Makes the test server pose as a Meilisearch Cloud project from now on.
   */
  protected function useCloud(): void {
    $this->cloud = TRUE;
    $this->requestHeaders = [];
    // Servers keep their API service: load them again so a new one is made.
    foreach (['search_api_server', 'search_api_index'] as $entity_type_id) {
      $this->container->get('entity_type.manager')->getStorage($entity_type_id)->resetCache();
    }
  }

  /**
   * Skips the test when the instance does not return search metadata.
   *
   * Older Meilisearch versions ignore the Meili-Include-Metadata header.
   */
  protected function requireSearchMetadata(): void {
    $results = Index::load('meilisearch_test_index')->query()->keys('foo')->execute();
    if ($results->getExtraData('meilisearch_query_uid') === NULL) {
      $this->markTestSkipped('This Meilisearch version does not return search metadata.');
    }
  }

  /**
   * Returns the analytics headers of the API services created so far.
   *
   * @return string[]
   *   Header names.
   */
  protected function sentAnalyticsHeaders(): array {
    $names = array_keys(array_merge(...$this->requestHeaders));
    return array_values(array_intersect($names, ['Meili-Include-Metadata', 'X-MS-USER-ID']));
  }

  /**
   * Returns whether the page cache kill switch was triggered.
   */
  protected function pageCacheIsKilled(): bool {
    return $this->container->get('page_cache_kill_switch')->check(new Response(), Request::create('/')) === ResponsePolicyInterface::DENY;
  }

  /**
   * Makes the test view cache its results and output by cache tags.
   */
  protected function cacheTestView(): void {
    $view_config = $this->config('views.view.meilisearch_test_view');
    $view_config->set('display.default.display_options.cache', ['type' => 'search_api_tag', 'options' => []])->save();
  }

  /**
   * Renders the test view.
   *
   * @return array{0: string, 1: array}
   *   The HTML and the rendered build.
   */
  protected function renderTestView(): array {
    $build = Views::getView('meilisearch_test_view')->preview();
    $html = (string) $this->container->get('renderer')->renderRoot($build);
    return [$html, $build];
  }

  /**
   * Searches on self-hosted servers are not tracked and stay cacheable.
   */
  public function testSelfHostedSearchesAreLeftAlone(): void {
    $this->container->get('current_user')->setAccount(new UserSession(['uid' => 2]));
    // Load the server again, so its API service is made for this user.
    $this->container->get('entity_type.manager')->getStorage('search_api_server')->resetCache();
    $this->container->get('entity_type.manager')->getStorage('search_api_index')->resetCache();
    $query = Index::load('meilisearch_test_index')->query()->keys('foo');
    $results = $query->execute();

    $this->assertGreaterThan(0, $results->getResultCount());
    $this->assertSame([], $this->sentAnalyticsHeaders());
    $this->assertNull($results->getExtraData('meilisearch_query_uid'));
    $this->assertInstanceOf(CacheableDependencyInterface::class, $query);
    $this->assertSame(Cache::PERMANENT, $query->getCacheMaxAge());
  }

  /**
   * Views on self-hosted servers get no tracking and stay cacheable.
   */
  public function testSelfHostedViewRowsAreLeftAlone(): void {
    // Without a cache plugin, Views itself makes the display uncacheable.
    $this->cacheTestView();
    [$html, $build] = $this->renderTestView();

    $this->assertStringContainsString('views-row', $html);
    $this->assertStringNotContainsString('data-meilisearch-', $html);
    $this->assertNotContains('meilisearch_analytics/click_tracking', $build['#attached']['library'] ?? []);
    $this->assertNotSame(0, $build['#cache']['max-age'] ?? Cache::PERMANENT);
    $this->assertFalse($this->pageCacheIsKilled());
  }

  /**
   * Searches on Meilisearch Cloud ask for metadata and are not cached.
   */
  public function testCloudSearchesAskForMetadata(): void {
    $this->container->get('current_user')->setAccount(new UserSession(['uid' => 2]));
    $this->useCloud();
    $query = Index::load('meilisearch_test_index')->query()->keys('foo');
    $query->execute();

    $this->assertSame(['Meili-Include-Metadata', 'X-MS-USER-ID'], $this->sentAnalyticsHeaders());
    $this->assertInstanceOf(CacheableDependencyInterface::class, $query);
    $this->assertSame(0, $query->getCacheMaxAge());
  }

  /**
   * Searches on Meilisearch Cloud return their query UID.
   */
  public function testCloudSearchesReturnTheQueryUid(): void {
    $this->useCloud();
    $this->requireSearchMetadata();
    $results = Index::load('meilisearch_test_index')->query()->keys('foo')->execute();
    $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $results->getExtraData('meilisearch_query_uid'));
  }

  /**
   * Result rows carry what the click tracker needs, and are not cached.
   */
  public function testCloudViewRowsCarryAnalyticsAttributes(): void {
    $this->useCloud();
    $this->requireSearchMetadata();
    [$html, $build] = $this->renderTestView();

    $this->assertSame(5, substr_count($html, 'data-meilisearch-object-id="'));
    $this->assertStringContainsString('data-meilisearch-object-id="entity_3Aentity__test__mulrev__changed_2F1_3Aen"', $html);
    $this->assertStringContainsString('data-meilisearch-position="0"', $html);
    $this->assertStringContainsString('data-meilisearch-position="4"', $html);
    $this->assertStringContainsString('data-meilisearch-index="meilisearch_test_index"', $html);
    $this->assertMatchesRegularExpression('/data-meilisearch-query-uid="[0-9a-f-]{36}"/', $html);
    $this->assertContains('meilisearch_analytics/click_tracking', $build['#attached']['library']);
    $this->assertSame(0, $build['#cache']['max-age']);
    $this->assertTrue($this->pageCacheIsKilled());
  }

  /**
   * Cached view results must not replay an old query UID.
   */
  public function testCloudCachedViewsGetFreshQueryUids(): void {
    $this->useCloud();
    $this->requireSearchMetadata();
    $this->cacheTestView();

    $uids = [];
    for ($run = 0; $run < 2; $run++) {
      [$html] = $this->renderTestView();
      preg_match('/data-meilisearch-query-uid="([0-9a-f-]{36})"/', $html, $match);
      $uids[] = $match[1] ?? NULL;
    }
    $this->assertNotNull($uids[0]);
    $this->assertNotSame($uids[0], $uids[1]);
  }

  /**
   * The click endpoint validates its input.
   */
  public function testClickEndpointValidatesInput(): void {
    $this->assertSame(400, $this->postClick('not json')->getStatusCode());
    $this->assertSame(400, $this->postClick(['index' => 'meilisearch_test_index'])->getStatusCode());
    $this->assertSame(404, $this->postClick($this->validClick(['index' => 'nope']))->getStatusCode());
    $this->assertSame(400, $this->postClick($this->validClick(['objectId' => 'a b']))->getStatusCode());
  }

  /**
   * Self-hosted Meilisearch has no /events route: nothing is sent.
   */
  public function testClickOnSelfHostedIsAccepted(): void {
    $this->assertSame(204, $this->postClick($this->validClick())->getStatusCode());
    $this->assertSame([], $this->sentEvents);
  }

  /**
   * Clicks on Meilisearch Cloud results are sent as events.
   */
  public function testClickOnCloudIsSent(): void {
    $this->useCloud();
    $this->assertSame(202, $this->postClick($this->validClick())->getStatusCode());
    $this->assertSame([
      [
        'eventType' => 'click',
        'eventName' => 'Search Result Clicked',
        'queryUid' => '01a0f434-50d7-7083-a0c7-33f3febe3cd7',
        'objectId' => 'entity_3Aentity__test__mulrev__changed_2F1_3Aen',
        'position' => 0,
        'indexUid' => $this->meilisearchBackend()->getIndexUid('meilisearch_test_index'),
        'userId' => 'anonymous',
      ],
    ], $this->sentEvents);
  }

  /**
   * Cross-site requests are refused.
   */
  public function testCrossSiteClicksAreRefused(): void {
    $this->assertSame(403, $this->postClick($this->validClick(), ['HTTP_SEC_FETCH_SITE' => 'cross-site'])->getStatusCode());
  }

  /**
   * Clicks are rate limited per client.
   */
  public function testClicksAreRateLimited(): void {
    for ($i = 0; $i < 60; $i++) {
      $this->assertSame(204, $this->postClick($this->validClick())->getStatusCode());
    }
    $this->assertSame(429, $this->postClick($this->validClick())->getStatusCode());
  }

  /**
   * Returns a valid click payload, with overrides.
   */
  protected function validClick(array $overrides = []): array {
    return $overrides + [
      'index' => 'meilisearch_test_index',
      'queryUid' => '01a0f434-50d7-7083-a0c7-33f3febe3cd7',
      'objectId' => 'entity_3Aentity__test__mulrev__changed_2F1_3Aen',
      'position' => 0,
    ];
  }

  /**
   * Sends a click through the HTTP kernel.
   */
  protected function postClick(array|string $payload, array $server = []) {
    $body = is_string($payload) ? $payload : json_encode($payload);
    $request = Request::create('/meilisearch/analytics/click', 'POST', [], [], [], $server + ['CONTENT_TYPE' => 'application/json'], $body);
    return $this->container->get('http_kernel')->handle($request);
  }

}
