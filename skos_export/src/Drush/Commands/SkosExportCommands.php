<?php

namespace Drupal\skos_export\Drush\Commands;

use Drupal\skos_export\SkosBuilder;
use Drupal\skos_export\SkosSync;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Mints identifiers, renders Skosmos config and syncs vocabularies.
 */
final class SkosExportCommands extends DrushCommands {

  public function __construct(
    private readonly SkosBuilder $builder,
    private readonly SkosSync $sync,
  ) {
    parent::__construct();
  }

  /**
   * Creates the command.
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('skos_export.builder'), $container->get('skos_export.sync'));
  }

  /**
   * Gives every term in published vocabularies a permanent identifier.
   */
  #[CLI\Command(name: 'skos:mint')]
  public function mint(): void {
    $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    foreach ($this->builder->vocabularies() as $vid => $settings) {
      $count = 0;
      $tids = $storage->getQuery()->accessCheck(FALSE)->condition('vid', $vid)->notExists($settings['id_field'])->execute();
      foreach ($storage->loadMultiple($tids) as $term) {
        $term->save();
        $count++;
      }
      $this->output()->writeln("$vid: minted $count");
    }
  }

  /**
   * Prints Skosmos's config.ttl.
   */
  #[CLI\Command(name: 'skos:config')]
  public function config(): void {
    $this->output()->write($this->builder->skosmosConfig());
  }

  /**
   * Replaces published graphs in the store.
   */
  #[CLI\Command(name: 'skos:sync')]
  #[CLI\Argument(name: 'vocabulary', description: 'One vocabulary; all when omitted.')]
  public function sync(?string $vocabulary = NULL): void {
    foreach ($vocabulary ? [$vocabulary] : array_keys($this->builder->vocabularies()) as $vid) {
      $this->output()->writeln("$vid: " . $this->sync->sync($vid) . ' concepts');
    }
  }

}
