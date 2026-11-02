<?php

namespace Drupal\skos_export\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Renders one configured vocabulary as SKOS Turtle.
 *
 * Concept URIs never contain term IDs: those differ between environments.
 */
class SkosExportController extends ControllerBase {

  /**
   * Returns the concept scheme for a vocabulary.
   */
  public function export(string $vocabulary, Request $request): CacheableResponse {
    $config = $this->config('skos_export.settings');
    $settings = $config->get("vocabularies.$vocabulary");
    if (!$settings) {
      throw new NotFoundHttpException();
    }

    $base = $settings['base_uri'];
    // A path keeps the domain out of exported config: it resolves per environment.
    if (str_starts_with($base, '/')) {
      $base = $request->getSchemeAndHttpHost() . $base;
    }
    $lang = $settings['language'] ?? 'en';
    $scheme = rtrim($base, '/#');
    $storage = $this->entityTypeManager()->getStorage('taxonomy_term');
    $ids = $storage->getQuery()->accessCheck(TRUE)
      ->condition('vid', $vocabulary)->condition('status', 1)->sort('tid')->execute();
    $terms = $storage->loadMultiple($ids);

    $uris = [];
    foreach ($terms as $tid => $term) {
      $uris[$tid] = $base . $this->identifier($term, $settings['id_field'] ?? '');
    }

    $parents = [];
    $children = [];
    foreach ($terms as $tid => $term) {
      foreach ($term->get('parent') as $item) {
        $parent = (int) $item->target_id;
        if (isset($uris[$parent])) {
          $parents[$tid][] = $parent;
          $children[$parent][] = $tid;
        }
      }
    }

    $out = "@prefix skos: <http://www.w3.org/2004/02/skos/core#> .\n";
    $out .= "@prefix dct: <http://purl.org/dc/terms/> .\n\n";
    $out .= "<$scheme> a skos:ConceptScheme ;\n  dct:title " . $this->literal($settings['title'], $lang);
    foreach ($terms as $tid => $term) {
      if (empty($parents[$tid])) {
        $out .= " ;\n  skos:hasTopConcept <{$uris[$tid]}>";
      }
    }
    $out .= " .\n\n";

    foreach ($terms as $tid => $term) {
      $lines = ['a skos:Concept', "skos:inScheme <$scheme>", 'skos:prefLabel ' . $this->literal($term->label(), $lang)];
      $description = trim(strip_tags((string) $term->getDescription()));
      if ($description !== '') {
        $lines[] = 'skos:definition ' . $this->literal($description, $lang);
      }
      if (empty($parents[$tid])) {
        $lines[] = "skos:topConceptOf <$scheme>";
      }
      foreach ($parents[$tid] ?? [] as $parent) {
        $lines[] = "skos:broader <{$uris[$parent]}>";
      }
      foreach ($children[$tid] ?? [] as $child) {
        $lines[] = "skos:narrower <{$uris[$child]}>";
      }
      $field = $settings['match_field'] ?? '';
      if ($field && $term->hasField($field)) {
        foreach ($term->get($field) as $item) {
          if (!empty($item->uri)) {
            $lines[] = 'skos:exactMatch <' . str_replace(['>', ' '], '', $item->uri) . '>';
          }
        }
      }
      $out .= "<{$uris[$tid]}> " . implode(" ;\n  ", $lines) . " .\n\n";
    }

    $response = new CacheableResponse($out, 200, ['Content-Type' => 'text/turtle; charset=utf-8']);
    $response->addCacheableDependency((new CacheableMetadata())
      ->addCacheableDependency($config)
      ->addCacheTags(['taxonomy_term_list'])
      ->addCacheContexts(['url.site']));
    return $response;
  }

  /**
   * Returns the stable local identifier of a term.
   */
  private function identifier($term, string $field): string {
    if ($field && $term->hasField($field) && !$term->get($field)->isEmpty()) {
      return rawurlencode((string) $term->get($field)->value);
    }
    return $term->uuid();
  }

  /**
   * Formats a Turtle string literal.
   */
  private function literal(string $value, string $lang): string {
    $escaped = str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', ''], $value);
    return "\"$escaped\"@$lang";
  }

}
