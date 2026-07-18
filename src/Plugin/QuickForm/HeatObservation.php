<?php

declare(strict_types=1);

namespace Drupal\farm_heat\Plugin\QuickForm;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\farm_heat\Service\HeatCyclePredictor;
use Drupal\farm_quick\Attribute\QuickForm;
use Drupal\farm_quick\Plugin\QuickForm\QuickFormBase;
use Drupal\farm_quick\Traits\QuickLogTrait;
use Drupal\farm_quick\Traits\QuickPrepopulateTrait;
use Drupal\farm_quick\Traits\QuickStringTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Heat Observation quick form.
 */
#[QuickForm(
  id: 'heat_observation',
  label: new TranslatableMarkup('Heat Observation'),
  description: new TranslatableMarkup('Record an observed heat (estrus) for one or more animals.'),
  helpText: new TranslatableMarkup('Use this form to record when you observe an animal in heat. The form will alert you if any selected animal may be returning to estrus after a recent breeding, suggesting possible non-conception.'),
  permissions: [
    'create heat_observation log',
  ],
)]
class HeatObservation extends QuickFormBase {

  use QuickLogTrait;
  use QuickPrepopulateTrait;
  use QuickStringTrait;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    AccountInterface $current_user,
    protected HeatCyclePredictor $heatCyclePredictor,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $current_user);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('farm_heat.heat_cycle_predictor'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $prepopulated = $this->getPrepopulatedEntities('asset', $form_state);

    $form['timestamp'] = [
      '#type'          => 'datetime',
      '#title'         => $this->t('Date/time observed'),
      '#default_value' => new DrupalDateTime('now', $this->currentUser->getTimeZone()),
      '#required'      => TRUE,
    ];

    $form['asset'] = [
      '#type'               => 'entity_autocomplete',
      '#title'              => $this->t('Animals'),
      '#description'        => $this->t('Animals observed in heat. Start typing a name to search.'),
      '#target_type'        => 'asset',
      '#selection_settings' => [
        'target_bundles' => ['animal'],
        'sort'           => ['field' => 'archived', 'direction' => 'ASC'],
      ],
      '#tags'          => TRUE,
      '#required'      => TRUE,
      '#default_value' => $prepopulated ?: NULL,
      '#ajax'          => [
        'callback' => [$this, 'returnEstrusCallback'],
        'wrapper'  => 'return-estrus-wrapper',
        'event'    => 'autocompleteclose change',
      ],
    ];

    $form['return_estrus_wrapper'] = [
      '#type'       => 'container',
      '#attributes' => ['id' => 'return-estrus-wrapper'],
    ];

    // Build return-to-estrus warnings for any prepopulated animals.
    $warnings = $this->buildReturnEstrusWarnings($form_state);
    if (!empty($warnings)) {
      $form['return_estrus_wrapper']['warnings'] = $warnings;
    }

    $form['heat_score'] = [
      '#type'     => 'radios',
      '#title'    => $this->t('Heat expression (EDS score)'),
      '#required' => TRUE,
      '#options'  => [
        '1' => $this->t('1 — Pre-heat signs only (restless, near others, chin resting)'),
        '2' => $this->t('2 — Approaching heat (mounting others, not standing, mucus discharge)'),
        '3' => $this->t('3 — Standing heat (will stand to be mounted) ← primary sign'),
        '4' => $this->t('4 — Post-heat (recently passed, swollen vulva, calm)'),
      ],
    ];

    $form['notes'] = [
      '#type'  => 'textarea',
      '#title' => $this->t('Notes'),
      '#rows'  => 3,
    ];

    return $form;
  }

  /**
   * AJAX callback: rebuilds the return-to-estrus warning section.
   */
  public function returnEstrusCallback(array $form, FormStateInterface $form_state): array {
    return $form['return_estrus_wrapper'];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\Core\Datetime\DrupalDateTime $timestamp */
    $timestamp   = $form_state->getValue('timestamp');
    $asset_items = $form_state->getValue('asset') ?? [];
    $heat_score  = $form_state->getValue('heat_score');
    $notes       = $form_state->getValue('notes');

    $asset_ids = array_column($asset_items, 'target_id');
    $assets    = $this->entityTypeManager->getStorage('asset')->loadMultiple($asset_ids);

    $this->createLog([
      'type'       => 'heat_observation',
      'name'       => $this->t('Heat observed (score @score): @animals', [
        '@score'   => $heat_score,
        '@animals' => $this->entityLabelsSummary(array_values($assets)),
      ]),
      'timestamp'  => $timestamp->getTimestamp(),
      'asset'      => $asset_ids,
      'heat_score' => $heat_score,
      'status'     => 'done',
      'notes'      => $notes,
    ]);

    // Post-submit return-to-estrus messenger warnings.
    // hook_entity_presave() sets heat_return_suspected on the saved log, but we
    // also check here so the user sees feedback immediately in the message area.
    $observed_ts = $timestamp->getTimestamp();
    foreach ($assets as $asset) {
      $return = $this->heatCyclePredictor->checkReturnToEstrus(
        (int) $asset->id(),
        $observed_ts,
        $this->heatCyclePredictor->resolveSpeciesFromAsset($asset),
      );
      if ($return['suspected'] && $return['breeding_log']) {
        $breeding_url = Url::fromRoute('entity.log.edit_form', ['log' => $return['breeding_log']->id()])->toString();
        $this->messenger()->addWarning($this->t(
          'Return to estrus detected for <strong>@animal</strong> (bred @days days ago). <a href="@url">Review breeding record</a>.',
          [
            '@animal' => $asset->label(),
            '@days'   => $return['days_since_bred'],
            '@url'    => $breeding_url,
          ]
        ));
      }
    }
  }

  /**
   * Builds return-to-estrus warning render elements for currently selected animals.
   */
  protected function buildReturnEstrusWarnings(FormStateInterface $form_state): array {
    $asset_items = $form_state->getValue('asset') ?? [];
    if (empty($asset_items)) {
      return [];
    }

    $observed_ts = \Drupal::time()->getRequestTime();
    $warnings    = [];

    foreach ($asset_items as $item) {
      $asset_id = $item['target_id'] ?? NULL;
      if (!$asset_id) {
        continue;
      }
      $asset = $this->entityTypeManager->getStorage('asset')->load($asset_id);
      if (!$asset) {
        continue;
      }
      $return = $this->heatCyclePredictor->checkReturnToEstrus(
        (int) $asset_id,
        $observed_ts,
        $this->heatCyclePredictor->resolveSpeciesFromAsset($asset),
      );
      if ($return['suspected'] && $return['breeding_log']) {
        $breeding_url = Url::fromRoute('entity.log.edit_form', ['log' => $return['breeding_log']->id()])->toString();
        $warnings[] = $this->t(
          '<strong>@animal</strong> was bred @days days ago — possible non-conception. <a href="@url">Review breeding record</a>.',
          [
            '@animal' => $asset->label(),
            '@days'   => $return['days_since_bred'],
            '@url'    => $breeding_url,
          ]
        );
      }
    }

    if (empty($warnings)) {
      return [];
    }

    return [
      '#theme'      => 'item_list',
      '#title'      => $this->t('⚠ Possible return to estrus detected'),
      '#items'      => $warnings,
      '#attributes' => ['class' => ['messages', 'messages--warning', 'farm-heat-return-warning']],
    ];
  }

}
