<?php

namespace Drupal\skos_export\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\skos_export\SkosBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves SKOS exports and resolves concept URIs.
 */
class SkosExportController extends ControllerBase {

  public function __construct(private readonly SkosBuilder $builder) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('skos_export.builder'));
  }

  /**
   * Returns a vocabulary as Turtle.
   */
  public function export(string $vocabulary): CacheableResponse {
    if (!isset($this->builder->vocabularies()[$vocabulary])) {
      throw new NotFoundHttpException();
    }
    $response = new CacheableResponse($this->builder->vocabularyTurtle($vocabulary), 200, ['Content-Type' => 'text/turtle; charset=utf-8']);
    $response->addCacheableDependency((new CacheableMetadata())
      ->addCacheableDependency($this->config('skos_export.settings'))
      ->addCacheTags(['taxonomy_term_list'])
      ->addCacheContexts(['url.site']));
    return $response;
  }

  /**
   * Resolves a concept URI by content negotiation.
   */
  public function concept(string $vocabulary, string $id, Request $request): Response {
    $term = $this->builder->findTerm($vocabulary, $id);
    if (!$term) {
      throw new NotFoundHttpException();
    }
    // An identifier is never reused, so a retired concept answers 410.
    if (!$term->isPublished()) {
      throw new GoneHttpException();
    }
    $concept = $this->builder->concepts($vocabulary)[$term->id()] ?? NULL;
    if (!$concept) {
      throw new NotFoundHttpException();
    }
    $accept = (string) $request->headers->get('Accept');
    if (str_contains($accept, 'text/turtle')) {
      return $this->vary(new Response($this->builder->conceptTurtle($vocabulary, $concept), 200, ['Content-Type' => 'text/turtle; charset=utf-8']));
    }
    if (str_contains($accept, 'application/ld+json')) {
      $response = new JsonResponse($this->builder->conceptJsonLd($vocabulary, $concept));
      $response->headers->set('Content-Type', 'application/ld+json');
      return $this->vary($response);
    }
    $target = $this->skosmosPage($vocabulary, '?uri=' . rawurlencode($concept['uri'])) ?? $term->toUrl()->setAbsolute()->toString();
    return $this->vary(new TrustedRedirectResponse($target, 303));
  }

  /**
   * Resolves a concept scheme URI by content negotiation.
   */
  public function scheme(string $vocabulary, Request $request): Response {
    if (str_contains((string) $request->headers->get('Accept'), 'text/turtle')) {
      return $this->vary(new Response($this->builder->vocabularyTurtle($vocabulary), 200, ['Content-Type' => 'text/turtle; charset=utf-8']));
    }
    $target = $this->skosmosPage($vocabulary, '');
    if (!$target) {
      throw new NotFoundHttpException();
    }
    return $this->vary(new TrustedRedirectResponse($target, 303));
  }

  /**
   * Returns a Skosmos URL, or NULL when Skosmos is not configured.
   */
  private function skosmosPage(string $vocabulary, string $query): ?string {
    $path = rtrim((string) $this->config('skos_export.settings')->get('skosmos_path'), '/');
    if ($path === '') {
      return NULL;
    }
    $settings = $this->builder->vocabularies()[$vocabulary];
    $lang = $settings['language'] ?? 'en';
    $page = $query === '' ? '' : 'page/';
    return "{$this->builder->publicUrl()}$path/{$settings['short_name']}/$lang/$page$query";
  }

  /**
   * Marks a negotiated response.
   */
  private function vary(Response $response): Response {
    $response->headers->set('Vary', 'Accept');
    return $response;
  }

}
