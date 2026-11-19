<?php

declare(strict_types=1);

namespace Drupal\Tests\meilisearch\Unit\Api;

use Drupal\Core\Http\ClientFactory;
use Drupal\meilisearch\Api\MeilisearchApiException;
use Drupal\meilisearch\Api\MeilisearchApiFactory;
use Drupal\meilisearch\Api\MeilisearchApiServiceInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Meilisearch\Client;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\meilisearch\Api\MeilisearchApiService
 */
class MeilisearchApiServiceTest extends TestCase {

  /**
   * Requests sent through the mocked HTTP client.
   *
   * @var array<int, array{request: \Psr\Http\Message\RequestInterface, options: array<string, mixed>}>
   */
  protected array $history = [];

  /**
   * Builds an API service whose HTTP layer answers with the given responses.
   *
   * @param array $responses
   *   Responses or exceptions, in order.
   * @param array $headers
   *   Extra headers for every request.
   * @param string $url
   *   The Meilisearch URL.
   */
  protected function api(array $responses, array $headers = [], string $url = 'http://meili.test:7700'): MeilisearchApiServiceInterface {
    $this->history = [];
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($this->history));
    return (new MeilisearchApiFactory(new ClientFactory($stack)))->create($url, 'secret', $headers);
  }

  /**
   * Returns a JSON response.
   */
  protected function json(array $body, int $status = 200): Response {
    return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
  }

  /**
   * @covers ::waitForTask
   */
  public function testFailedTaskThrowsWithMeilisearchError(): void {
    $api = $this->api([
      $this->json([
        'uid' => 7,
        'status' => 'failed',
        'error' => ['message' => 'Document has invalid _geo', 'code' => 'invalid_document_geo_field'],
      ]),
    ]);
    try {
      $api->waitForTask(7);
      $this->fail('A failed task must throw.');
    }
    catch (MeilisearchApiException $e) {
      $this->assertStringContainsString('Document has invalid _geo', $e->getMessage());
      $this->assertSame('invalid_document_geo_field', $e->getErrorCode());
    }
  }

  /**
   * @covers ::waitForTask
   */
  public function testCanceledTaskThrows(): void {
    $api = $this->api([
      $this->json([
        'uid' => 7,
        'status' => 'canceled',
        'canceledBy' => 9,
        'error' => NULL,
      ]),
    ]);
    try {
      $api->waitForTask(7);
      $this->fail('A canceled task must throw.');
    }
    catch (MeilisearchApiException $e) {
      $this->assertSame('Meilisearch task 7 was canceled.', $e->getMessage());
      $this->assertSame('task_canceled', $e->getErrorCode());
    }
  }

  /**
   * @covers ::waitForTask
   */
  public function testSucceededTaskIsReturned(): void {
    $api = $this->api([$this->json(['uid' => 7, 'status' => 'succeeded'])]);
    $this->assertSame('succeeded', $api->waitForTask(7)['status']);
  }

  /**
   * @covers ::waitForTask
   */
  public function testTaskTimeoutThrows(): void {
    $api = $this->api(array_fill(0, 20, $this->json(['uid' => 7, 'status' => 'processing'])));
    $this->expectException(MeilisearchApiException::class);
    $this->expectExceptionMessageMatches('/task 7/i');
    $api->waitForTask(7, 1);
  }

  /**
   * Builds an API service with a fake clock.
   *
   * @param callable $answer
   *   Receives the service and returns the response to each request. It can
   *   move the clock forward to simulate a slow request.
   */
  protected function clockApi(callable $answer): FakeClockApiService {
    $this->history = [];
    $api = NULL;
    $stack = HandlerStack::create(function () use (&$api, $answer) {
      return Create::promiseFor($answer($api));
    });
    $stack->push(Middleware::history($this->history));
    $url = 'http://meili.test:7700';
    $http = (new ClientFactory($stack))->fromOptions(['base_uri' => $url . '/']);
    $api = new FakeClockApiService(new Client($url, 'secret', $http), $http, $url, 'secret');
    return $api;
  }

  /**
   * The time spent in requests counts towards the timeout.
   *
   * @covers ::waitForTask
   */
  public function testTaskTimeoutCountsRequestTime(): void {
    $api = $this->clockApi(function (FakeClockApiService $api) {
      $api->advance(400);
      return $this->json(['uid' => 7, 'status' => 'processing']);
    });
    try {
      $api->waitForTask(7, 1000);
      $this->fail('A task that keeps processing must time out.');
    }
    catch (MeilisearchApiException $e) {
      $this->assertSame('task_timeout', $e->getErrorCode());
      $this->assertSame('Meilisearch task 7 did not finish within 1000 ms.', $e->getMessage());
    }
    // Requests end at 400, 825 and 1275 ms; the last one started in time.
    $this->assertCount(3, $this->history);
    $this->assertEquals(1275, $api->elapsedMs());
  }

  /**
   * @covers ::waitForTask
   */
  public function testTaskPollingBacksOff(): void {
    $api = $this->clockApi(fn(FakeClockApiService $api) => $this->json([
      'uid' => 7,
      'status' => $api->elapsedMs() >= 3000 ? 'succeeded' : 'processing',
    ]));
    $this->assertSame('succeeded', $api->waitForTask(7)['status']);
    $this->assertEquals([25, 50, 100, 200, 400, 500, 500, 500, 500, 500], $api->sleeps);
    // A fixed 100 ms interval needs 31 requests for a 3 s task.
    $this->assertCount(11, $this->history);
  }

  /**
   * @covers ::waitForTask
   */
  public function testTaskPollingSleepIsClampedToRemainingTime(): void {
    $api = $this->clockApi(fn() => $this->json(['uid' => 7, 'status' => 'enqueued']));
    try {
      $api->waitForTask(7, 60);
      $this->fail('A task that stays enqueued must time out.');
    }
    catch (MeilisearchApiException $e) {
      $this->assertSame('task_timeout', $e->getErrorCode());
    }
    $this->assertEquals([25, 35], $api->sleeps);
    $this->assertCount(3, $this->history);
  }

  /**
   * @covers ::waitForTask
   */
  public function testTaskIsPolledOnceWithoutTimeout(): void {
    $api = $this->clockApi(fn() => $this->json(['uid' => 7, 'status' => 'succeeded']));
    $this->assertSame('succeeded', $api->waitForTask(7, 0)['status']);
    $this->assertCount(1, $this->history);
    $this->assertSame([], $api->sleeps);
  }

  /**
   * @covers ::search
   */
  public function testNetworkErrorThrows(): void {
    $api = $this->api([new ConnectException('Connection refused', new Request('POST', '/'))]);
    $this->expectException(MeilisearchApiException::class);
    $api->search('idx', 'foo', []);
  }

  /**
   * TLS and transfer errors are not "network" errors for the SDK.
   *
   * @covers ::search
   * @covers ::waitForTask
   */
  public function testTransferErrorsThrow(): void {
    $error = new RequestException('cURL error 60: SSL certificate problem', new Request('POST', '/'));
    try {
      $this->api([$error])->search('idx', 'foo', []);
      $this->fail('A transfer error during search must throw MeilisearchApiException.');
    }
    catch (MeilisearchApiException $e) {
      $this->assertStringContainsString('cURL error 60', $e->getMessage());
    }
    $this->expectException(MeilisearchApiException::class);
    $this->api([$error])->waitForTask(7);
  }

  /**
   * The SDK rethrows a non-JSON answer to a deletion as a bare \Exception.
   *
   * @covers ::deleteDocuments
   * @covers ::deleteDocumentsByFilter
   * @covers ::call
   */
  public function testNonJsonResponseToDeletionThrowsApiException(): void {
    $deletions = [
      'deleteDocuments' => fn(MeilisearchApiServiceInterface $api) => $api->deleteDocuments('idx', ['a', 'b']),
      'deleteDocumentsByFilter' => fn(MeilisearchApiServiceInterface $api) => $api->deleteDocumentsByFilter('idx', 'type = "page"'),
    ];
    foreach ($deletions as $method => $delete) {
      $api = $this->api([new Response(502, ['Content-Type' => 'text/html'], '<html>Bad gateway</html>')]);
      try {
        $delete($api);
        $this->fail("$method() must throw when the response is not JSON.");
      }
      catch (MeilisearchApiException $e) {
        $this->assertStringContainsString('Bad gateway', $e->getMessage(), $method);
        $this->assertStringNotContainsString('Hint:', $e->getMessage(), $method);
        $this->assertNull($e->getErrorCode(), $method);
      }
    }
  }

  /**
   * @covers ::search
   */
  public function testApiErrorKeepsErrorCode(): void {
    $api = $this->api([
      $this->json([
        'message' => 'Attribute `x` is not filterable.',
        'code' => 'invalid_search_filter',
        'type' => 'invalid_request',
        'link' => '',
      ], 400),
    ]);
    try {
      $api->search('idx', 'foo', ['filter' => 'x = 1']);
      $this->fail('An API error must throw.');
    }
    catch (MeilisearchApiException $e) {
      $this->assertSame('invalid_search_filter', $e->getErrorCode());
    }
  }

  /**
   * @covers ::search
   */
  public function testSearchReturnsRawResponse(): void {
    $api = $this->api([$this->json(['hits' => [], 'metadata' => ['queryUid' => 'abc']])]);
    $this->assertSame('abc', $api->search('idx', '', [])['metadata']['queryUid']);
  }

  /**
   * @covers \Drupal\meilisearch\Api\MeilisearchApiFactory::create
   */
  public function testExtraHeadersAndUserAgentAreSent(): void {
    $api = $this->api([$this->json(['hits' => []])], ['Meili-Include-Metadata' => 'true']);
    $api->search('idx', '', []);
    $request = $this->history[0]['request'];
    $this->assertSame('true', $request->getHeaderLine('Meili-Include-Metadata'));
    $this->assertSame('Bearer secret', $request->getHeaderLine('Authorization'));
    $this->assertStringContainsString('Meilisearch Drupal', $request->getHeaderLine('User-Agent'));
  }

  /**
   * @covers ::getSettings
   */
  public function testGetSettingsReturnsTheIndexSettings(): void {
    $rules = ['words', 'typo', 'proximity', 'attributeRank', 'sort', 'wordPosition', 'exactness'];
    $api = $this->api([$this->json(['rankingRules' => $rules, 'filterableAttributes' => []])]);
    $this->assertSame($rules, $api->getSettings('idx')['rankingRules']);
    $request = $this->history[0]['request'];
    $this->assertSame('GET', $request->getMethod());
    $this->assertSame('http://meili.test:7700/indexes/idx/settings', (string) $request->getUri());
  }

  /**
   * @covers ::getSettings
   */
  public function testGetSettingsErrorsThrowApiException(): void {
    $api = $this->api([
      $this->json([
        'message' => 'Index `idx` not found.',
        'code' => 'index_not_found',
        'type' => 'invalid_request',
        'link' => '',
      ], 404),
    ]);
    try {
      $api->getSettings('idx');
      $this->fail('An API error must throw.');
    }
    catch (MeilisearchApiException $e) {
      $this->assertSame('index_not_found', $e->getErrorCode());
    }
    $this->expectException(MeilisearchApiException::class);
    $this->api([new ConnectException('Connection refused', new Request('GET', '/'))])->getSettings('idx');
  }

  /**
   * @covers ::multiSearch
   */
  public function testMultiSearchPostsQueriesAndReturnsResults(): void {
    $api = $this->api([$this->json(['results' => [['hits' => [], 'indexUid' => 'idx']]])]);
    $results = $api->multiSearch([['indexUid' => 'idx', 'q' => 'foo', 'limit' => 0]]);
    $this->assertSame('idx', $results[0]['indexUid']);
    $request = $this->history[0]['request'];
    $this->assertSame('http://meili.test:7700/multi-search', (string) $request->getUri());
    $this->assertSame(['queries' => [['indexUid' => 'idx', 'q' => 'foo', 'limit' => 0]]], json_decode((string) $request->getBody(), TRUE));
  }

  /**
   * A 2xx answer that is not the expected JSON must not pass as "no results".
   *
   * @covers ::multiSearch
   * @covers ::post
   */
  public function testMultiSearchRejectsUnusableResponses(): void {
    $responses = [
      'HTML page' => new Response(200, ['Content-Type' => 'text/html'], '<html>Maintenance</html>'),
      'truncated JSON' => new Response(200, ['Content-Type' => 'application/json'], '{"results":[{"hits":['),
      'JSON scalar' => new Response(200, ['Content-Type' => 'application/json'], '"ok"'),
      'empty body' => new Response(200),
      'no results key' => $this->json(['hits' => []]),
      'results not a list' => $this->json(['results' => 'none']),
      'fewer results than queries' => $this->json(['results' => [['hits' => [], 'indexUid' => 'idx']]]),
    ];
    $queries = [['indexUid' => 'idx', 'q' => 'foo'], ['indexUid' => 'idx', 'q' => 'foo', 'limit' => 0]];
    foreach ($responses as $case => $response) {
      try {
        $this->api([$response])->multiSearch($queries);
        $this->fail("A multi-search answered with $case must throw.");
      }
      catch (MeilisearchApiException $e) {
        $this->assertNull($e->getErrorCode(), $case);
      }
    }
  }

  /**
   * @covers ::sendEvent
   * @covers ::post
   */
  public function testSendEventAcceptsEmptyResponse(): void {
    $this->api([new Response(204)])->sendEvent(['eventType' => 'click']);
    $this->api([new Response(200, ['Content-Type' => 'application/json'], '{}')])->sendEvent(['eventType' => 'click']);
    $this->assertCount(1, $this->history);
  }

  /**
   * @covers ::sendEvent
   */
  public function testSendEventPostsToEventsRoute(): void {
    $api = $this->api([new Response(201)]);
    $api->sendEvent(['eventType' => 'click']);
    $this->assertSame('http://meili.test:7700/events', (string) $this->history[0]['request']->getUri());
  }

  /**
   * A click must not hold a PHP worker as long as an indexing request may.
   *
   * @covers ::sendEvent
   * @covers ::multiSearch
   */
  public function testOnlyEventsUseShortTimeouts(): void {
    $api = $this->api([
      new Response(204),
      $this->json(['results' => [['hits' => [], 'indexUid' => 'idx']]]),
      $this->json(['hits' => []]),
    ]);
    $api->sendEvent(['eventType' => 'click']);
    $api->multiSearch([['indexUid' => 'idx', 'q' => 'foo']]);
    $api->search('idx', 'foo', []);
    $timeouts = array_map(
      fn(array $transaction) => [$transaction['options']['connect_timeout'], $transaction['options']['timeout']],
      $this->history,
    );
    $this->assertSame([[1, 3], [5, 30], [5, 30]], $timeouts);
  }

  /**
   * @covers ::isCloud
   */
  public function testIsCloudDetectsMeilisearchDomain(): void {
    $this->assertTrue($this->api([], [], 'https://ms-abc123.fra.meilisearch.io')->isCloud());
    $this->assertTrue($this->api([], [], 'https://meilisearch.io')->isCloud());
    $this->assertFalse($this->api([], [], 'http://127.0.0.1:7700')->isCloud());
    $this->assertFalse($this->api([], [], 'https://evilmeilisearch.io')->isCloud(), 'Confusable hostname must not be treated as cloud.');
  }

}
