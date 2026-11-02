<?php

namespace Drupal\skos_export\Routing;

use Symfony\Component\Routing\Route;

/**
 * Serves a route under each published path-style namespace.
 */
class ConceptRoutes {

  /**
   * Returns concept and scheme routes.
   */
  public function routes(): array {
    $routes = [];
    foreach (\Drupal::config('skos_export.settings')->get('vocabularies') ?? [] as $vid => $settings) {
      $path = rtrim((string) parse_url($settings['base_uri'], PHP_URL_PATH), '/');
      if ($path === '' || str_ends_with($settings['base_uri'], '#')) {
        continue;
      }
      // The response depends on Accept, which the page cache does not key on.
      $options = ['no_cache' => TRUE];
      $requirements = ['_permission' => 'access content', 'id' => '[A-Za-z0-9._~-]+'];
      $routes["skos_export.concept.$vid"] = new Route("$path/{id}", [
        '_controller' => '\Drupal\skos_export\Controller\SkosExportController::concept',
        'vocabulary' => $vid,
      ], $requirements, $options);
      unset($requirements['id']);
      $routes["skos_export.scheme.$vid"] = new Route($path, [
        '_controller' => '\Drupal\skos_export\Controller\SkosExportController::scheme',
        'vocabulary' => $vid,
      ], $requirements, $options);
    }
    return $routes;
  }

}
