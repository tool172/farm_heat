<?php

declare(strict_types=1);

namespace Drupal\farm_heat\Plugin\Log\LogType;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\farm_entity\Attribute\LogType;
use Drupal\farm_entity\Plugin\Log\LogType\FarmLogType;

/**
 * Provides the heat_observation log type.
 */
#[LogType(
  id: 'heat_observation',
  label: new TranslatableMarkup('Heat Observation'),
)]
class HeatObservation extends FarmLogType {

  /**
   * {@inheritdoc}
   */
  public function buildFieldDefinitions(): array {
    $fields = parent::buildFieldDefinitions();
    $f = \Drupal::service('farm_field.factory');

    $fields['heat_score'] = $f->bundleFieldDefinition([
      'type'        => 'list_string',
      'label'       => t('Heat expression (EDS score)'),
      'description' => t('Estrus Detection Score. Score 3 (standing heat) is the primary sign and reliable indicator for AI timing.'),
      'allowed_values' => [
        '1' => t('1 — Pre-heat signs only (restless, near other cows, chin resting)'),
        '2' => t('2 — Approaching heat (mounting others, not standing, mucus discharge)'),
        '3' => t('3 — Standing heat (will stand to be mounted) ← primary sign'),
        '4' => t('4 — Post-heat (heat recently passed, swollen vulva, calm)'),
      ],
      'weight' => ['form' => 10, 'view' => 10],
    ]);

    $fields['heat_days_since_last'] = $f->bundleFieldDefinition([
      'type'        => 'integer',
      'label'       => t('Days since previous heat'),
      'description' => t('Auto-calculated from the previous heat observation for the same animal.'),
      'weight'      => ['form' => 20, 'view' => 20],
    ]);

    $fields['heat_return_suspected'] = $f->bundleFieldDefinition([
      'type'        => 'boolean',
      'label'       => t('Suspected return to estrus'),
      'description' => t('Set automatically if this animal was bred within the typical return-to-estrus window, suggesting possible non-conception.'),
      'weight'      => ['form' => 30, 'view' => 30],
    ]);

    $fields['heat_breeding_log'] = $f->bundleFieldDefinition([
      'type'          => 'entity_reference',
      'label'         => t('Related breeding log'),
      'description'   => t('The breeding log this heat observation may be a return from.'),
      'target_type'   => 'log',
      'target_bundle' => 'breeding',
      'multiple'      => FALSE,
      'weight'        => ['form' => 40, 'view' => 40],
    ]);

    return $fields;
  }

}
