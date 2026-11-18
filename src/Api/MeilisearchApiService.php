<?php

declare(strict_types=1);

namespace Drupal\meilisearch\Api;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Meilisearch\Client;
use Meilisearch\Exceptions\ApiException;
use Meilisearch\Exceptions\ExceptionInterface;
use Psr\Http\Client\ClientExceptionInterface;

/**
 * Wraps the Meilisearch PHP client for one instance.
 *
 * Create instances with \Drupal\meilisearch\Api\MeilisearchApiFactory.
 */
class MeilisearchApiService implements MeilisearchApiServiceInterface {

  /**
   * The pause before the second poll of a task, in milliseconds.
   */
  protected const TASK_POLL_INTERVAL = 25;

  /**
   * The longest pause between two polls of a task, in milliseconds.
   */
  protected const TASK_POLL_MAX_INTERVAL = 500;

  /**
   * How long connecting may take when sending an analytics event, in seconds.
   */
  protected const EVENT_CONNECT_TIMEOUT = 1;

  /**
   * How long sending an analytics event may take in total, in seconds.
   */
  protected const EVENT_TIMEOUT = 3;

  /**
   * Constructs a MeilisearchApiService.
   *
   * @param \Meilisearch\Client $client
   *   The Meilisearch client.
   * @param \GuzzleHttp\ClientInterface $http
   *   The HTTP client the Meilisearch client uses, for routes it lacks.
   * @param string $url
   *   The instance URL, without trailing slash.
   * @param string $apiKey
   *   The API key.
   */
  public function __construct(
    protected Client $client,
    protected ClientInterface $http,
    protected string $url,
    protected string $apiKey,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getUrl(): string {
    return $this->url;
  }

  /**
   * {@inheritdoc}
   */
  public function isCloud(): bool {
    return (bool) preg_match('/(?:^|\.)meilisearch\.io$/i', (string) parse_url($this->url, PHP_URL_HOST));
  }

  /**
   * {@inheritdoc}
   */
  public function ping(): bool {
    try {
      $this->client->health();
      return TRUE;
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function version(): array {
    return $this->call(fn() => $this->client->version());
  }

  /**
   * {@inheritdoc}
   */
  public function indexExists(string $uid): bool {
    try {
      $this->call(fn() => $this->client->getRawIndex($uid));
      return TRUE;
    }
    catch (MeilisearchApiException $e) {
      if ($e->getErrorCode() === 'index_not_found') {
        return FALSE;
      }
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function indexStats(string $uid): array {
    return $this->call(fn() => $this->client->index($uid)->stats());
  }

  /**
   * {@inheritdoc}
   */
  public function createIndex(string $uid, string $primaryKey): array {
    return $this->call(fn() => $this->client->createIndex($uid, ['primaryKey' => $primaryKey]));
  }

  /**
   * {@inheritdoc}
   */
  public function deleteIndex(string $uid): array {
    return $this->call(fn() => $this->client->deleteIndex($uid));
  }

  /**
   * {@inheritdoc}
   */
  public function updateSettings(string $uid, array $settings): array {
    return $this->call(fn() => $this->client->index($uid)->updateSettings($settings));
  }

  /**
   * {@inheritdoc}
   */
  public function addDocuments(string $uid, array $documents, string $primaryKey): array {
    return $this->call(fn() => $this->client->index($uid)->addDocuments($documents, $primaryKey));
  }

  /**
   * {@inheritdoc}
   */
  public function deleteDocuments(string $uid, array $ids): array {
    return $this->call(fn() => $this->client->index($uid)->deleteDocuments(array_values($ids)));
  }

  /**
   * {@inheritdoc}
   */
  public function deleteDocumentsByFilter(string $uid, string $filter): array {
    return $this->call(fn() => $this->client->index($uid)->deleteDocuments(['filter' => $filter]));
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAllDocuments(string $uid): array {
    return $this->call(fn() => $this->client->index($uid)->deleteAllDocuments());
  }

  /**
   * {@inheritdoc}
   */
  public function search(string $uid, string $query, array $params): array {
    return $this->call(fn() => $this->client->index($uid)->search($query, $params, ['raw' => TRUE]));
  }

  /**
   * {@inheritdoc}
   */
  public function multiSearch(array $queries): array {
    $response = $this->post('multi-search', ['queries' => array_values($queries)]);
    $results = $response['results'] ?? NULL;
    // Meilisearch answers with exactly one result per query.
    if (!is_array($results) || !array_is_list($results) || count($results) !== count($queries)) {
      throw new MeilisearchApiException(sprintf('The response to the Meilisearch "multi-search" request does not hold one result for each of the %d queries.', count($queries)));
    }
    return $results;
  }

  /**
   * {@inheritdoc}
   */
  public function sendEvent(array $event): void {
    // Events are sent while a visitor's click request is being served. They
    // are not worth holding a PHP worker for as long as an indexing request.
    $this->post('events', $event, [
      'connect_timeout' => self::EVENT_CONNECT_TIMEOUT,
      'timeout' => self::EVENT_TIMEOUT,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function waitForTask(int $taskUid, int $timeoutMs = self::TASK_TIMEOUT): array {
    // The SDK's own wait loop only counts the time it sleeps, not the time the
    // requests take, so this one measures against a real deadline. A request
    // that starts before the deadline can still end after it, by at most the
    // HTTP timeout.
    $deadline = $this->monotonicTime() + $timeoutMs * 1000000;
    $interval = self::TASK_POLL_INTERVAL;
    while (TRUE) {
      $task = $this->call(fn() => $this->client->getTask($taskUid));
      if (!in_array($task['status'] ?? NULL, ['enqueued', 'processing'], TRUE)) {
        break;
      }
      $remaining = $deadline - $this->monotonicTime();
      if ($remaining <= 0) {
        throw new MeilisearchApiException(sprintf('Meilisearch task %d did not finish within %d ms.', $taskUid, $timeoutMs), 'task_timeout');
      }
      // Most tasks finish within milliseconds, so the first polls are quick;
      // the interval then doubles to spare a busy instance.
      $this->sleep(min($interval * 1000, intdiv($remaining + 999, 1000)));
      $interval = min($interval * 2, self::TASK_POLL_MAX_INTERVAL);
    }
    if (($task['status'] ?? NULL) === 'failed') {
      throw new MeilisearchApiException(
        sprintf('Meilisearch task %d failed: %s', $taskUid, $task['error']['message'] ?? 'unknown error'),
        $task['error']['code'] ?? NULL,
      );
    }
    // A canceled task wrote nothing and carries no error.
    if (($task['status'] ?? NULL) === 'canceled') {
      throw new MeilisearchApiException(sprintf('Meilisearch task %d was canceled.', $taskUid), 'task_canceled');
    }
    if (($task['status'] ?? NULL) !== 'succeeded') {
      throw new MeilisearchApiException(sprintf('Meilisearch task %d ended with the unexpected status "%s".', $taskUid, (string) ($task['status'] ?? '')));
    }
    return $task;
  }

  /**
   * Returns a monotonic time in nanoseconds.
   */
  protected function monotonicTime(): int {
    return hrtime(TRUE);
  }

  /**
   * Pauses for a number of microseconds.
   */
  protected function sleep(int $microseconds): void {
    usleep($microseconds);
  }

  /**
   * Runs an SDK call, converting its exceptions.
   *
   * @param callable $callback
   *   The SDK call.
   *
   * @return mixed
   *   The result of the call.
   */
  protected function call(callable $callback): mixed {
    try {
      return $callback();
    }
    // The SDK only wraps connection errors; TLS and transfer errors (Guzzle
    // RequestException) come through as PSR-18 client exceptions.
    catch (ExceptionInterface | ClientExceptionInterface $e) {
      throw $this->convert($e);
    }
    catch (MeilisearchApiException $e) {
      throw $e;
    }
    // Some SDK methods (deleteDocuments() for one) rethrow a response that is
    // not JSON as a bare \Exception carrying a generic hint. The exception it
    // wraps has the real message.
    catch (\Exception $e) {
      $previous = $e->getPrevious();
      if ($previous instanceof ExceptionInterface) {
        throw $this->convert($previous);
      }
      throw new MeilisearchApiException($e->getMessage(), NULL, $e);
    }
  }

  /**
   * Converts an SDK exception.
   */
  protected function convert(ExceptionInterface|ClientExceptionInterface $e): MeilisearchApiException {
    if ($e instanceof ApiException) {
      return new MeilisearchApiException($e->getMessage(), $e->errorCode, $e);
    }
    return new MeilisearchApiException($e->getMessage(), NULL, $e);
  }

  /**
   * Sends a JSON POST request for routes the SDK does not cover.
   *
   * @param string $path
   *   The route, relative to the instance URL.
   * @param array $body
   *   The request body, sent as JSON.
   * @param array $options
   *   Guzzle request options that override those of the HTTP client, for
   *   example "timeout".
   *
   * @return array
   *   The decoded response, or an empty array when the response has no body.
   *
   * @throws \Drupal\meilisearch\Api\MeilisearchApiException
   *   When the request fails or its response has a body that is not JSON.
   */
  protected function post(string $path, array $body, array $options = []): array {
    $options = ['json' => $body, 'headers' => []] + $options;
    if ($this->apiKey !== '') {
      $options['headers']['Authorization'] = 'Bearer ' . $this->apiKey;
    }
    try {
      $response = $this->http->request('POST', $path, $options);
    }
    catch (RequestException $e) {
      $error = $e->hasResponse() ? json_decode((string) $e->getResponse()->getBody(), TRUE) : NULL;
      throw new MeilisearchApiException($error['message'] ?? $e->getMessage(), $error['code'] ?? NULL, $e);
    }
    catch (GuzzleException $e) {
      throw new MeilisearchApiException($e->getMessage(), NULL, $e);
    }
    // Some routes answer without a body. Anything else must be a JSON object
    // or array: a proxy can answer 200 with an HTML page or cut a body short.
    $content = trim((string) $response->getBody());
    if ($content === '') {
      return [];
    }
    $decoded = json_decode($content, TRUE);
    if (!is_array($decoded)) {
      throw new MeilisearchApiException(sprintf('The response to the Meilisearch "%s" request (HTTP %d) is not valid JSON.', $path, $response->getStatusCode()));
    }
    return $decoded;
  }

}
