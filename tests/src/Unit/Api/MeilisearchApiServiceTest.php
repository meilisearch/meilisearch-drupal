<?php

declare(strict_types=1);

namespace Drupal\Tests\meilisearch\Unit\Api;

use Drupal\meilisearch\Api\MeilisearchApiService;
use Drupal\meilisearch\Client\MeilisearchClientFactoryInterface;
use Meilisearch\Client;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\meilisearch\Api\MeilisearchApiService
 */
class MeilisearchApiServiceTest extends TestCase {

  /**
   * @covers ::isCloud
   */
  public function testIsCloudDetectsMeilisearchDomain(): void {
    $factory = $this->createMock(MeilisearchClientFactoryInterface::class);
    $service = new MeilisearchApiService($factory);

    $service->setUrl('https://ms-abc123.fra.meilisearch.io');
    $this->assertTrue($service->isCloud());

    $service->setUrl('https://meilisearch.io');
    $this->assertTrue($service->isCloud(), 'Root meilisearch.io host should be detected as cloud.');

    $service->setUrl('http://127.0.0.1:7700');
    $this->assertFalse($service->isCloud());

    $service->setUrl('https://evilmeilisearch.io');
    $this->assertFalse($service->isCloud(), 'Confusable hostname must not be treated as cloud.');
  }

  /**
   * @covers ::connection
   */
  public function testConnectionCreatesClient(): void {
    $client = $this->createMock(Client::class);
    $factory = $this->createMock(MeilisearchClientFactoryInterface::class);
    $factory->expects($this->once())
      ->method('getInstance')
      ->with('http://localhost:7700', 'key')
      ->willReturn($client);

    $service = new MeilisearchApiService($factory);
    $service->setUrl('http://localhost:7700');
    $service->setApiKey('key');

    $this->assertSame($client, $service->connection());
    // Second call should reuse cached client.
    $this->assertSame($client, $service->connection());
  }

}
