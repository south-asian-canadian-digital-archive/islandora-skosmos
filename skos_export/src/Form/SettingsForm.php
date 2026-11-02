<?php

namespace Drupal\skos_export\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

/**
 * Chooses which vocabularies are published.
 */
class SettingsForm extends ConfigFormBase {

  private const NEW_FIELD = 'field_skos_id';
  private const CREATE = '__create';

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'skos_export_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['skos_export.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('skos_export.settings');
    $form['public_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Public site URL'),
      '#default_value' => $config->get('public_url'),
      '#description' => $this->t('Leave empty to keep the domain out of exported config. Drush and cron then need the right --uri, and cron sync is skipped without a host.'),
    ];
    $form['service_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Skosmos service name'),
      '#default_value' => $config->get('service_name'),
      '#required' => TRUE,
    ];
    $form['skosmos_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Skosmos path'),
      '#default_value' => $config->get('skosmos_path'),
      '#description' => $this->t('Empty if Skosmos is not used; browsers then go to the term page.'),
    ];
    $form['store_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Store protocol'),
      '#options' => ['blazegraph' => $this->t('Blazegraph REST'), 'graph_store' => $this->t('SPARQL Graph Store Protocol')],
      '#default_value' => $config->get('store_type'),
    ];
    $form['store_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Store write URL'),
      '#default_value' => $config->get('store_url'),
      '#required' => TRUE,
    ];
    $form['sparql_endpoint'] = [
      '#type' => 'url',
      '#title' => $this->t('SPARQL endpoint for Skosmos'),
      '#default_value' => $config->get('sparql_endpoint'),
      '#required' => TRUE,
    ];
    $form['vocabularies'] = ['#tree' => TRUE];
    $published = $config->get('vocabularies') ?? [];
    foreach (\Drupal::entityTypeManager()->getStorage('taxonomy_vocabulary')->loadMultiple() as $vid => $vocabulary) {
      $settings = $published[$vid] ?? [];
      $checked = [':input[name="vocabularies[' . $vid . '][enabled]"]' => ['checked' => TRUE]];
      $form['vocabularies'][$vid] = [
        '#type' => 'details',
        '#title' => $vocabulary->label(),
        '#open' => isset($published[$vid]),
        'enabled' => ['#type' => 'checkbox', '#title' => $this->t('Publish'), '#default_value' => isset($published[$vid])],
        'short_name' => ['#type' => 'textfield', '#title' => $this->t('Short name'), '#default_value' => $settings['short_name'] ?? $vid],
        'title' => ['#type' => 'textfield', '#title' => $this->t('Title'), '#default_value' => $settings['title'] ?? $vocabulary->label()],
        'base_uri' => [
          '#type' => 'textfield',
          '#title' => $this->t('URI namespace'),
          '#default_value' => $settings['base_uri'] ?? '',
          '#description' => $this->t('A path such as /vocab/ or an absolute URI, ending in / or #. Treat it as permanent once published.'),
        ],
        'language' => ['#type' => 'textfield', '#title' => $this->t('Language'), '#default_value' => $settings['language'] ?? 'en', '#size' => 8],
        'id_field' => [
          '#type' => 'select',
          '#title' => $this->t('Identifier field'),
          '#options' => [self::CREATE => $this->t('Create @field', ['@field' => self::NEW_FIELD])] + $this->fields($vid, ['string']),
          '#default_value' => $settings['id_field'] ?? self::CREATE,
          '#description' => $this->t('Permanent identifiers are minted here. They cannot change after a term is saved.'),
        ],
        'id_prefix' => ['#type' => 'textfield', '#title' => $this->t('Identifier prefix'), '#default_value' => $settings['id_prefix'] ?? '', '#size' => 8],
        'match_field' => [
          '#type' => 'select',
          '#title' => $this->t('Authority link field'),
          '#options' => ['' => $this->t('None')] + $this->fields($vid, ['link', 'authority_link']),
          '#default_value' => $settings['match_field'] ?? '',
        ],
      ];
      foreach (['short_name', 'title', 'base_uri', 'language'] as $key) {
        $form['vocabularies'][$vid][$key]['#states'] = ['required' => $checked];
      }
    }
    return parent::buildForm($form, $form_state);
  }

  /**
   * Lists a vocabulary's fields of the given types.
   */
  private function fields(string $vid, array $types): array {
    $options = [];
    foreach (\Drupal::service('entity_field.manager')->getFieldDefinitions('taxonomy_term', $vid) as $name => $field) {
      if (str_starts_with($name, 'field_') && in_array($field->getType(), $types, TRUE)) {
        $options[$name] = $field->getLabel();
      }
    }
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    foreach ($form_state->getValue('vocabularies') as $vid => $values) {
      if (empty($values['enabled'])) {
        continue;
      }
      if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $values['short_name'])) {
        $form_state->setErrorByName("vocabularies][$vid][short_name", $this->t('Short name must start with a letter and contain only letters, digits, _ or -.'));
      }
      $base = $values['base_uri'];
      if (!preg_match('/[\/#]$/', $base) || !(str_starts_with($base, '/') || str_starts_with($base, 'http'))) {
        $form_state->setErrorByName("vocabularies][$vid][base_uri", $this->t('Namespace must be a path or http(s) URI ending in / or #.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $published = [];
    foreach ($form_state->getValue('vocabularies') as $vid => $values) {
      if (empty($values['enabled'])) {
        continue;
      }
      unset($values['enabled']);
      if ($values['id_field'] === self::CREATE) {
        $this->createIdField($vid);
        $values['id_field'] = self::NEW_FIELD;
      }
      $published[$vid] = $values;
    }
    $config = $this->config('skos_export.settings');
    foreach (['public_url', 'service_name', 'skosmos_path', 'store_type', 'store_url', 'sparql_endpoint'] as $key) {
      $config->set($key, $form_state->getValue($key));
    }
    $config->set('vocabularies', $published)->save();
    \Drupal::service('router.builder')->setRebuildNeeded();
    $this->messenger()->addStatus($this->t('Run drush skos:mint, skos:config and skos:sync to publish these changes.'));
    parent::submitForm($form, $form_state);
  }

  /**
   * Adds the identifier field to a vocabulary.
   */
  private function createIdField(string $vid): void {
    if (!FieldStorageConfig::loadByName('taxonomy_term', self::NEW_FIELD)) {
      FieldStorageConfig::create([
        'field_name' => self::NEW_FIELD,
        'entity_type' => 'taxonomy_term',
        'type' => 'string',
        'settings' => ['max_length' => 64],
      ])->save();
    }
    if (!FieldConfig::loadByName('taxonomy_term', $vid, self::NEW_FIELD)) {
      FieldConfig::create([
        'field_name' => self::NEW_FIELD,
        'entity_type' => 'taxonomy_term',
        'bundle' => $vid,
        'label' => 'Permanent identifier',
      ])->save();
    }
  }

}
