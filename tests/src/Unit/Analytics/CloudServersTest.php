<?php

declare(strict_types=1);

namespace Drupal\Tests\meilisearch\Unit\Analytics;

use Drupal\Core\Http\ClientFactory;
use Drupal\meilisearch\Api\MeilisearchApiFactory;
use Drupal\meilisearch\Plugin\search_api\backend\MeilisearchBackend;
use Drupal\meilisearch_analytics\CloudServers;
use Drupal\search_api\Backend\BackendInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\SearchApiException;
use Drupal\search_api\ServerInterface;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\meilisearch_analytics\CloudServers
 */
class CloudServersTest extends TestCase {

  /**
   * @covers ::isCloudServer
   */
  public function testOnlyMeilisearchCloudUrlsAreCloudServers(): void {
    $servers = $this->cloudServers();
    $this->assertTrue($servers->isCloudServer($this->server('https://ms-0123456789ab-1234.fra.meilisearch.io')));
    $this->assertFalse($servers->isCloudServer($this->server('http://localhost:7700')));
    $this->assertFalse($servers->isCloudServer($this->server('https://search.example.com')));
    $this->assertFalse($servers->isCloudServer($this->server('')));
  }

  /**
   * @covers ::isCloudServer
   */
  public function testUnknownServersAndOtherBackendsAreNotCloudServers(): void {
    $servers = $this->cloudServers();
    $this->assertFalse($servers->isCloudServer(NULL));

    $server = $this->createStub(ServerInterface::class);
    $server->method('hasValidBackend')->willReturn(TRUE);
    $server->method('getBackend')->willReturn($this->createStub(BackendInterface::class));
    $this->assertFalse($servers->isCloudServer($server));

    // A server whose backend plugin is gone, e.g. after its module was
    // uninstalled, throws on getBackend().
    $server = $this->createStub(ServerInterface::class);
    $server->method('hasValidBackend')->willReturn(FALSE);
    $server->method('getBackend')->willThrowException(new SearchApiException('Missing backend.'));
    $this->assertFalse($servers->isCloudServer($server));
  }

  /**
   * @covers ::isCloudIndex
   */
  public function testIndexesFollowTheirServer(): void {
    $servers = $this->cloudServers();
    $this->assertTrue($servers->isCloudIndex($this->index($this->server('https://ms-0123456789ab-1234.fra.meilisearch.io'))));
    $this->assertFalse($servers->isCloudIndex($this->index($this->server('http://localhost:7700'))));
    $this->assertFalse($servers->isCloudIndex($this->index(NULL)));
  }

  /**
   * Builds the service on the real API factory.
   */
  private function cloudServers(): CloudServers {
    $http_client_factory = $this->createStub(ClientFactory::class);
    $http_client_factory->method('fromOptions')->willReturnCallback(fn(array $options) => new Client($options));
    return new CloudServers(new MeilisearchApiFactory($http_client_factory));
  }

  /**
   * Returns a Meilisearch server configured with the given URL.
   *
   * The backend's own API service must not be asked: the check also runs
   * while the backend creates it.
   */
  private function server(string $url): ServerInterface {
    $backend = $this->createMock(MeilisearchBackend::class);
    $backend->method('getConfiguration')->willReturn(['url' => $url, 'api_key' => 'secret']);
    $backend->expects($this->never())->method('getApi');
    $server = $this->createStub(ServerInterface::class);
    $server->method('hasValidBackend')->willReturn(TRUE);
    $server->method('getBackend')->willReturn($backend);
    return $server;
  }

  /**
   * Returns an index on the given server, or without a valid server.
   */
  private function index(?ServerInterface $server): IndexInterface {
    $index = $this->createStub(IndexInterface::class);
    $index->method('hasValidServer')->willReturn($server !== NULL);
    $index->method('getServerInstance')->willReturn($server);
    return $index;
  }

}
