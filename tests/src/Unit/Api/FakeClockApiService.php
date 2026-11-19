<?php

declare(strict_types=1);

namespace Drupal\Tests\meilisearch\Unit\Api;

use Drupal\meilisearch\Api\MeilisearchApiService;

/**
 * An API service whose clock only moves when a test or a sleep moves it.
 */
class FakeClockApiService extends MeilisearchApiService {

  /**
   * The current time, in nanoseconds.
   */
  public int $now = 0;

  /**
   * The sleeps requested so far, in milliseconds.
   *
   * @var array<int, int|float>
   */
  public array $sleeps = [];

  /**
   * Moves the clock forward, as a slow request would.
   */
  public function advance(int $milliseconds): void {
    $this->now += $milliseconds * 1000000;
  }

  /**
   * Returns the current time in milliseconds.
   */
  public function elapsedMs(): int|float {
    return $this->now / 1000000;
  }

  /**
   * {@inheritdoc}
   */
  protected function monotonicTime(): int {
    return $this->now;
  }

  /**
   * {@inheritdoc}
   */
  protected function sleep(int $microseconds): void {
    $this->sleeps[] = $microseconds / 1000;
    $this->now += $microseconds * 1000;
  }

}
