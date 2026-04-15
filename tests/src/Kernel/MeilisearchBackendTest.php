<?php

declare(strict_types=1);

namespace Drupal\Tests\meilisearch\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\meilisearch\Plugin\search_api\backend\MeilisearchBackend;
use Drupal\search_api\Entity\Server;

/**
 * @group meilisearch
 */
class MeilisearchBackendTest extends KernelTestBase {

  protected static $modules = ['search_api', 'meilisearch'];

  public function testBackendPluginIsRegistered(): void {
    $manager = $this->container->get('plugin.manager.search_api.backend');
    $definitions = $manager->getDefinitions();
    $this->assertArrayHasKey('meilisearch', $definitions);
    $this->assertSame('Meilisearch', (string) $definitions['meilisearch']['label']);
  }

  public function testDefaultConfiguration(): void {
    $server = Server::create([
      'id' => 'test',
      'name' => 'Test',
      'backend' => 'meilisearch',
    ]);
    /** @var MeilisearchBackend $backend */
    $backend = $server->getBackend();
    $config = $backend->defaultConfiguration();

    $this->assertSame('self_hosted', $config['connection_mode']);
    $this->assertSame(7700, $config['port']);
    $this->assertSame('keyword', $config['search_mode']);
  }

}
