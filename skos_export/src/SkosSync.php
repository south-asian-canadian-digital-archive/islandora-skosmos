<?php

namespace Drupal\skos_export;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;

/**
 * Publishes vocabularies into a SPARQL store.
 */
class SkosSync {

  private const DIRTY = 'skos_export.dirty';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly SkosBuilder $builder,
    private readonly ClientInterface $client,
    private readonly StateInterface $state,
  ) {}

  /**
   * Records that a published vocabulary changed.
   */
  public function markDirty(string $vid): void {
    if (isset($this->builder->vocabularies()[$vid])) {
      $dirty = $this->state->get(self::DIRTY, []);
      $dirty[$vid] = TRUE;
      $this->state->set(self::DIRTY, $dirty);
    }
  }

  /**
   * Syncs changed vocabularies, leaving failures queued for the next run.
   */
  public function syncDirty(): void {
    foreach (array_keys($this->state->get(self::DIRTY, [])) as $vid) {
      if (!$this->builder->publicUrl() || !isset($this->builder->vocabularies()[$vid])) {
        continue;
      }
      try {
        $this->sync($vid);
      }
      catch (\Throwable $e) {
        \Drupal::logger('skos_export')->error('Sync of @vid failed: @message', ['@vid' => $vid, '@message' => $e->getMessage()]);
      }
    }
  }

  /**
   * Replaces a vocabulary's graph and returns the concept count.
   */
  public function sync(string $vid): int {
    $settings = $this->builder->vocabularies()[$vid];
    $graph = $this->builder->namespace($settings);
    $turtle = $this->builder->vocabularyTurtle($vid);
    $config = $this->configFactory->get('skos_export.settings');
    $url = $config->get('store_url');
    $headers = ['Content-Type' => 'text/turtle'];
    if ($config->get('store_type') === 'graph_store') {
      $this->client->request('PUT', $url, ['query' => ['graph' => $graph], 'headers' => $headers, 'body' => $turtle]);
    }
    else {
      $this->client->request('POST', $url, ['form_params' => ['update' => "CLEAR SILENT GRAPH <$graph>"]]);
      $this->client->request('POST', $url, ['query' => ['context-uri' => $graph], 'headers' => $headers, 'body' => $turtle]);
    }
    $dirty = $this->state->get(self::DIRTY, []);
    unset($dirty[$vid]);
    $this->state->set(self::DIRTY, $dirty);
    return count($this->builder->concepts($vid));
  }

}
