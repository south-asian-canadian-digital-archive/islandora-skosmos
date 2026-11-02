<?php

namespace Drupal\skos_export;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Builds SKOS and Skosmos configuration from published vocabularies.
 */
class SkosBuilder {

  private const ALPHABET = 'bcdfghjkmnpqrstvwxz23456789';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * Returns published vocabulary settings, keyed by machine name.
   */
  public function vocabularies(): array {
    return $this->configFactory->get('skos_export.settings')->get('vocabularies') ?? [];
  }

  /**
   * Returns the public site URL without a trailing slash.
   */
  public function publicUrl(): string {
    $url = (string) $this->configFactory->get('skos_export.settings')->get('public_url');
    if ($url === '') {
      $request = $this->requestStack->getCurrentRequest();
      $url = $request ? $request->getSchemeAndHttpHost() : '';
    }
    return rtrim($url, '/');
  }

  /**
   * Returns a vocabulary's absolute concept namespace.
   */
  public function namespace(array $settings): string {
    $base = $settings['base_uri'];
    return str_starts_with($base, '/') ? $this->publicUrl() . $base : $base;
  }

  /**
   * Returns a vocabulary's concept scheme URI.
   */
  public function schemeUri(array $settings): string {
    return rtrim($this->namespace($settings), '/#');
  }

  /**
   * Returns a term's public links, or NULL when it has no published concept.
   *
   * Links are derived on read and never stored, so they cannot trigger a sync
   * or appear in SKOS output.
   *
   * @return array{uri: string, skosmos: ?string}|null
   *   The concept URI and, when Skosmos is configured, its page URL.
   */
  public function links(TermInterface $term): ?array {
    $settings = $this->vocabularies()[$term->bundle()] ?? NULL;
    $field = $settings['id_field'] ?? '';
    if (!$field || !$term->isPublished() || !$term->hasField($field) || $term->get($field)->isEmpty()) {
      return NULL;
    }
    $id = rawurlencode((string) $term->get($field)->value);
    $path = rtrim((string) $this->configFactory->get('skos_export.settings')->get('skosmos_path'), '/');
    $lang = $settings['language'] ?? 'en';
    return [
      'uri' => $this->namespace($settings) . $id,
      'skosmos' => $path === '' ? NULL : "{$this->publicUrl()}$path/{$settings['short_name']}/$lang/page/$id",
    ];
  }

  /**
   * Returns an unused opaque identifier. Random IDs are never reused.
   */
  public function mint(string $vid): string {
    $settings = $this->vocabularies()[$vid];
    do {
      $id = $settings['id_prefix'] ?? '';
      for ($i = 0; $i < 8; $i++) {
        $id .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
      }
    } while ($this->findTerm($vid, $id));
    return $id;
  }

  /**
   * Finds a term by its permanent identifier, published or not.
   */
  public function findTerm(string $vid, string $id): ?TermInterface {
    $field = $this->vocabularies()[$vid]['id_field'] ?? '';
    if (!$field) {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $tids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('vid', $vid)->condition($field, $id)->range(0, 1)->execute();
    return $tids ? $storage->load(reset($tids)) : NULL;
  }

  /**
   * Describes every published term that has an identifier.
   *
   * @return array
   *   Concepts keyed by term ID. Terms lacking an identifier are left out, so
   *   no unstable URI is ever exported.
   */
  public function concepts(string $vid): array {
    $settings = $this->vocabularies()[$vid];
    $field = $settings['id_field'] ?? '';
    $base = $this->namespace($settings);
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $tids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('vid', $vid)->condition('status', 1)->sort('tid')->execute();
    $terms = $storage->loadMultiple($tids);

    $concepts = [];
    foreach ($terms as $tid => $term) {
      if (!$field || !$term->hasField($field) || $term->get($field)->isEmpty()) {
        continue;
      }
      $concepts[$tid] = [
        'uri' => $base . rawurlencode((string) $term->get($field)->value),
        'label' => (string) $term->label(),
        'definition' => trim(strip_tags((string) $term->getDescription())),
        'broader' => [],
        'narrower' => [],
        'exactMatch' => [],
        'altLabel' => $this->fieldValues($term, $settings['alt_label_field'] ?? ''),
        'scopeNote' => $this->fieldValues($term, $settings['scope_note_field'] ?? ''),
        'historyNote' => $this->fieldValues($term, $settings['history_note_field'] ?? ''),
      ];
    }
    foreach ($concepts as $tid => &$concept) {
      foreach ($terms[$tid]->get('parent') as $item) {
        $parent = (int) $item->target_id;
        if (isset($concepts[$parent])) {
          $concept['broader'][] = $concepts[$parent]['uri'];
          $concepts[$parent]['narrower'][] = $concept['uri'];
        }
      }
      $match = $settings['match_field'] ?? '';
      if ($match && $terms[$tid]->hasField($match)) {
        foreach ($terms[$tid]->get($match) as $item) {
          if (!empty($item->uri)) {
            $concept['exactMatch'][] = str_replace(['>', '<', ' ', '"'], '', $item->uri);
          }
        }
      }
    }
    return $concepts;
  }

  /**
   * Returns a term field's non-empty plain text values.
   */
  private function fieldValues(TermInterface $term, string $field): array {
    if (!$field || !$term->hasField($field)) {
      return [];
    }
    $values = [];
    foreach ($term->get($field) as $item) {
      $value = trim(strip_tags((string) $item->value));
      if ($value !== '') {
        $values[] = $value;
      }
    }
    return $values;
  }

  /**
   * Renders a whole vocabulary as Turtle.
   */
  public function vocabularyTurtle(string $vid): string {
    $settings = $this->vocabularies()[$vid];
    $scheme = $this->schemeUri($settings);
    $lang = $settings['language'] ?? 'en';
    $concepts = $this->concepts($vid);
    $out = $this->prefixes() . "<$scheme> a skos:ConceptScheme ;\n  dct:title " . $this->literal($settings['title'], $lang);
    foreach ($concepts as $concept) {
      if (!$concept['broader']) {
        $out .= " ;\n  skos:hasTopConcept <{$concept['uri']}>";
      }
    }
    $out .= " .\n\n";
    foreach ($concepts as $concept) {
      $out .= $this->conceptBlock($concept, $scheme, $lang);
    }
    return $out;
  }

  /**
   * Renders one concept as Turtle.
   */
  public function conceptTurtle(string $vid, array $concept): string {
    $settings = $this->vocabularies()[$vid];
    return $this->prefixes() . $this->conceptBlock($concept, $this->schemeUri($settings), $settings['language'] ?? 'en');
  }

  /**
   * Renders one concept as JSON-LD.
   */
  public function conceptJsonLd(string $vid, array $concept): array {
    $settings = $this->vocabularies()[$vid];
    $lang = $settings['language'] ?? 'en';
    $ids = static fn(array $uris) => array_map(static fn($uri) => ['@id' => $uri], $uris);
    $text = static fn(array $values) => array_map(static fn($value) => ['@value' => $value, '@language' => $lang], $values);
    return array_filter([
      '@context' => ['skos' => 'http://www.w3.org/2004/02/skos/core#'],
      '@id' => $concept['uri'],
      '@type' => 'skos:Concept',
      'skos:inScheme' => ['@id' => $this->schemeUri($settings)],
      'skos:prefLabel' => ['@value' => $concept['label'], '@language' => $lang],
      'skos:definition' => $concept['definition'] === '' ? NULL : ['@value' => $concept['definition'], '@language' => $lang],
      'skos:altLabel' => $text($concept['altLabel']),
      'skos:scopeNote' => $text($concept['scopeNote']),
      'skos:historyNote' => $text($concept['historyNote']),
      'skos:broader' => $ids($concept['broader']),
      'skos:narrower' => $ids($concept['narrower']),
      'skos:exactMatch' => $ids($concept['exactMatch']),
    ]);
  }

  /**
   * Renders Skosmos's config.ttl for all published vocabularies.
   */
  public function skosmosConfig(): string {
    $config = $this->configFactory->get('skos_export.settings');
    $endpoint = $config->get('sparql_endpoint');
    $public = $this->publicUrl();
    $service = $this->escape((string) $config->get('service_name'));
    $languages = [];
    foreach ($this->vocabularies() as $settings) {
      $lang = $settings['language'] ?? 'en';
      $locale = $lang === 'en' ? 'en_GB' : $lang . '_' . strtoupper($lang);
      $languages[$lang] = "[ rdfs:label \"$lang\" ; rdf:value \"$locale.utf8\" ]";
    }
    $languages = $languages ?: ['en' => '[ rdfs:label "en" ; rdf:value "en_GB.utf8" ]'];

    $out = "@prefix dc: <http://purl.org/dc/terms/> .\n"
      . "@prefix rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#> .\n"
      . "@prefix rdfs: <http://www.w3.org/2000/01/rdf-schema#> .\n"
      . "@prefix skos: <http://www.w3.org/2004/02/skos/core#> .\n"
      . "@prefix skosmos: <http://purl.org/net/skosmos#> .\n"
      . "@prefix void: <http://rdfs.org/ns/void#> .\n"
      . "@prefix : <#> .\n\n"
      . ":config a skosmos:Configuration ;\n"
      . "    skosmos:sparqlEndpoint <$endpoint> ;\n"
      . "    skosmos:sparqlDialect \"Generic\" ;\n"
      . "    skosmos:sparqlCollationEnabled false ;\n"
      . "    skosmos:serviceName \"$service\" ;\n"
      . '    skosmos:baseHref "' . $public . rtrim((string) $config->get('skosmos_path'), '/') . "/\" ;\n"
      . '    skosmos:languages ( ' . implode(' ', $languages) . " ) ;\n"
      . "    skosmos:templateCache \"/tmp/skosmos-template-cache\" .\n\n";
    foreach ($this->vocabularies() as $settings) {
      $short = $settings['short_name'];
      $lang = $settings['language'] ?? 'en';
      $namespace = $this->namespace($settings);
      $out .= ":$short a skosmos:Vocabulary, void:Dataset ;\n"
        . '    dc:title "' . $this->escape($settings['title']) . "\"@$lang ;\n"
        . "    skosmos:shortName \"$short\" ;\n"
        . "    dc:subject :cat_general ;\n"
        . "    void:uriSpace \"$namespace\" ;\n"
        . "    skosmos:language \"$lang\" ;\n"
        . "    skosmos:defaultLanguage \"$lang\" ;\n"
        . "    skosmos:showTopConcepts true ;\n"
        . '    skosmos:mainConceptScheme <' . $this->schemeUri($settings) . "> ;\n"
        . "    void:sparqlEndpoint <$endpoint> ;\n"
        . "    skosmos:sparqlGraph <$namespace> .\n\n";
    }
    return $out . ":categories a skos:ConceptScheme ; skos:prefLabel \"Vocabularies\"@en .\n"
      . ":cat_general a skos:Concept ; skos:topConceptOf :categories ; skos:inScheme :categories ; skos:prefLabel \"General\"@en .\n";
  }

  /**
   * Returns the Turtle prefix header.
   */
  private function prefixes(): string {
    return "@prefix skos: <http://www.w3.org/2004/02/skos/core#> .\n@prefix dct: <http://purl.org/dc/terms/> .\n\n";
  }

  /**
   * Renders a concept's Turtle block.
   */
  private function conceptBlock(array $concept, string $scheme, string $lang): string {
    $lines = ['a skos:Concept', "skos:inScheme <$scheme>", 'skos:prefLabel ' . $this->literal($concept['label'], $lang)];
    if ($concept['definition'] !== '') {
      $lines[] = 'skos:definition ' . $this->literal($concept['definition'], $lang);
    }
    foreach (['altLabel', 'scopeNote', 'historyNote'] as $property) {
      foreach ($concept[$property] as $value) {
        $lines[] = "skos:$property " . $this->literal($value, $lang);
      }
    }
    if (!$concept['broader']) {
      $lines[] = "skos:topConceptOf <$scheme>";
    }
    foreach (['broader', 'narrower', 'exactMatch'] as $property) {
      foreach ($concept[$property] as $uri) {
        $lines[] = "skos:$property <$uri>";
      }
    }
    return "<{$concept['uri']}> " . implode(" ;\n  ", $lines) . " .\n\n";
  }

  /**
   * Formats a language-tagged Turtle literal.
   */
  private function literal(string $value, string $lang): string {
    return '"' . $this->escape($value) . "\"@$lang";
  }

  /**
   * Escapes a Turtle string.
   */
  private function escape(string $value): string {
    return str_replace(['\\', '"', "\n", "\r", "\t"], ['\\\\', '\\"', '\\n', '', '\\t'], $value);
  }

}
