<?php

declare(strict_types=1);

namespace Drupal\farm_heat\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\farm_animal_disposition\DispositionManager;
use Drupal\farm_breeding\Service\SpeciesResolver;

/**
 * Predicts heat cycles and detects returns to estrus.
 *
 * Cycle lengths are read from farm_breeding.settings (heat_cycle_days.*),
 * falling back to built-in defaults for cattle, sheep, goat, and pig.
 */
class HeatCyclePredictor {

  /**
   * Default cycle lengths (days) by base species, used as fallback.
   */
  const DEFAULTS = [
    'cattle' => ['typical' => 21, 'min' => 18, 'max' => 24],
    'sheep'  => ['typical' => 17, 'min' => 14, 'max' => 19],
    'goat'   => ['typical' => 21, 'min' => 18, 'max' => 23],
    'pig'    => ['typical' => 21, 'min' => 19, 'max' => 23],
    'horse'  => ['typical' => 21, 'min' => 19, 'max' => 23],
  ];

  /**
   * Breed variants that map to 'cattle' for cycle lookup.
   */
  const CATTLE_VARIANTS = [
    'beef_cattle', 'dairy_cattle', 'angus', 'hereford',
    'simmental', 'charolais', 'holstein', 'jersey',
  ];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    protected SpeciesResolver $speciesResolver,
    protected TimeInterface $time,
  ) {}

  /**
   * Returns the most recent done heat_observation log for an animal.
   *
   * @param int $asset_id
   *   Animal asset ID.
   * @param int $before_ts
   *   Only consider logs before this timestamp. 0 = no limit.
   *
   * @return object|null
   *   Log entity or NULL.
   */
  public function getLastHeat(int $asset_id, int $before_ts = 0): ?object {
    $query = $this->entityTypeManager->getStorage('log')->getQuery()
      ->condition('type', 'heat_observation')
      ->condition('asset', $asset_id)
      ->condition('status', 'done')
      ->sort('timestamp', 'DESC')
      ->range(0, 1)
      ->accessCheck(FALSE);

    if ($before_ts > 0) {
      $query->condition('timestamp', $before_ts, '<');
    }

    $ids = $query->execute();
    if (empty($ids)) {
      return NULL;
    }
    return $this->entityTypeManager->getStorage('log')->load(reset($ids));
  }

  /**
   * Returns the cycle length data for a given species key.
   *
   * Reads from farm_breeding.settings heat_cycle_days first, falls back to
   * DEFAULTS constants.
   *
   * @param string $species
   *   Canonical species key (e.g. 'cattle', 'sheep').
   *
   * @return array
   *   ['typical' => int, 'min' => int, 'max' => int]
   */
  public function getCycleLength(string $species): array {
    $base     = $this->getBaseSpecies($species);
    $settings = $this->configFactory->get('farm_breeding.settings');

    $typical = $settings->get("heat_cycle_days.$base.typical");
    if ($typical) {
      return [
        'typical' => (int) $typical,
        'min'     => (int) ($settings->get("heat_cycle_days.$base.min") ?? $typical - 3),
        'max'     => (int) ($settings->get("heat_cycle_days.$base.max") ?? $typical + 3),
      ];
    }

    return self::DEFAULTS[$base] ?? self::DEFAULTS['cattle'];
  }

  /**
   * Predicts the next heat window for an animal.
   *
   * @param int    $asset_id Animal asset ID.
   * @param string $species  Canonical species key.
   *
   * @return array|null
   *   Prediction array or NULL if no previous heat recorded. Keys:
   *   last_heat_ts, predicted_ts, window_start_ts, window_end_ts,
   *   days_until, in_window.
   */
  public function predictNextHeat(int $asset_id, string $species): ?array {
    $last = $this->getLastHeat($asset_id);
    if (!$last) {
      return NULL;
    }

    $last_ts = (int) $last->get('timestamp')->value;
    $cycle   = $this->getCycleLength($species);
    $now     = $this->time->getRequestTime();

    $predicted_ts    = $last_ts + ($cycle['typical'] * 86400);
    $window_start_ts = $last_ts + ($cycle['min'] * 86400);
    $window_end_ts   = $last_ts + ($cycle['max'] * 86400);
    $days_until      = (int) round(($predicted_ts - $now) / 86400);
    $in_window       = $now >= $window_start_ts && $now <= $window_end_ts;

    return [
      'last_heat_ts'    => $last_ts,
      'predicted_ts'    => $predicted_ts,
      'window_start_ts' => $window_start_ts,
      'window_end_ts'   => $window_end_ts,
      'days_until'      => $days_until,
      'in_window'       => $in_window,
    ];
  }

  /**
   * Checks if a heat observation is a suspected return to estrus.
   *
   * A return is suspected if the animal was bred within the typical
   * return-to-estrus window (cycle min-3 to cycle max+3 days ago).
   *
   * @param int    $asset_id    Animal asset ID.
   * @param int    $observed_ts Timestamp of the current heat observation.
   * @param string $species     Canonical species key.
   *
   * @return array
   *   ['suspected' => bool, 'breeding_log' => log|null, 'days_since_bred' => int]
   */
  public function checkReturnToEstrus(int $asset_id, int $observed_ts, string $species): array {
    $cycle        = $this->getCycleLength($species);
    $window_start = $observed_ts - (($cycle['max'] + 3) * 86400);
    $window_end   = $observed_ts - (($cycle['min'] - 3) * 86400);

    $log_storage = $this->entityTypeManager->getStorage('log');
    $ids = $log_storage->getQuery()
      ->condition('type', 'breeding')
      ->condition('asset', $asset_id)
      ->condition('timestamp', [$window_start, $window_end], 'BETWEEN')
      ->condition('breeding_lifecycle_status', ['bred', 'pending_check', 'pregnant'], 'IN')
      ->sort('timestamp', 'DESC')
      ->range(0, 1)
      ->accessCheck(FALSE)
      ->execute();

    if (empty($ids)) {
      return ['suspected' => FALSE, 'breeding_log' => NULL, 'days_since_bred' => 0];
    }

    $breeding_log    = $log_storage->load(reset($ids));
    $days_since_bred = (int) round(($observed_ts - (int) $breeding_log->get('timestamp')->value) / 86400);

    return [
      'suspected'       => TRUE,
      'breeding_log'    => $breeding_log,
      'days_since_bred' => $days_since_bred,
    ];
  }

  /**
   * Returns animals predicted to be in their heat window within the next N days.
   *
   * @param int $days
   *   Lookahead window in days. Default 7.
   *
   * @return array
   *   Keyed by asset ID, each value is a prediction array merged with 'asset'.
   */
  public function getAnimalsInHeatWindow(int $days = 7): array {
    $now    = $this->time->getRequestTime();
    $cutoff = $now + ($days * 86400);

    $asset_storage = $this->entityTypeManager->getStorage('asset');

    $asset_ids = $asset_storage->getQuery()
      ->condition('type', 'animal')
      ->condition('sex', 'F')
      ->condition('archived', 0)
      ->accessCheck(FALSE)
      ->execute();

    if (empty($asset_ids)) {
      return [];
    }

    $assets  = $asset_storage->loadMultiple($asset_ids);
    $results = [];

    foreach ($assets as $asset_id => $asset) {
      // Respect disposition — skip off-farm animals if field is set.
      if ($asset->hasField('disposition') && !$asset->get('disposition')->isEmpty()) {
        $disposition = $asset->get('disposition')->value;
        if (!in_array($disposition, DispositionManager::BREEDING_ELIGIBLE, TRUE)) {
          continue;
        }
      }

      $species    = $this->resolveSpeciesFromAsset($asset);
      $prediction = $this->predictNextHeat((int) $asset_id, $species);

      if (!$prediction) {
        continue;
      }

      if ($prediction['window_start_ts'] <= $cutoff && $prediction['window_end_ts'] >= $now) {
        $results[$asset_id] = $prediction + ['asset' => $asset];
      }
    }

    uasort($results, fn($a, $b) => $a['predicted_ts'] <=> $b['predicted_ts']);

    return $results;
  }

  /**
   * Resolves the canonical species key from an animal asset's animal_type field.
   *
   * Falls back to 'cattle' if not resolvable.
   *
   * @param object $asset
   *   Animal asset entity.
   *
   * @return string
   *   Canonical species key.
   */
  public function resolveSpeciesFromAsset(object $asset): string {
    if (!$asset->hasField('animal_type') || $asset->get('animal_type')->isEmpty()) {
      return 'cattle';
    }

    foreach ($asset->get('animal_type') as $term_ref) {
      $term = $term_ref->entity;
      if (!$term && !empty($term_ref->target_id)) {
        $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($term_ref->target_id);
      }
      if (!$term) {
        continue;
      }
      $resolved = $this->speciesResolver->resolve($term->label());
      if ($resolved !== NULL) {
        return $this->getBaseSpecies($resolved);
      }
    }

    return 'cattle';
  }

  /**
   * Maps breed-specific canonical keys to a base species for cycle lookup.
   *
   * e.g. 'angus' → 'cattle', 'dairy_cattle' → 'cattle'.
   *
   * @param string $species
   *   Canonical species key from SpeciesResolver.
   *
   * @return string
   *   Base species key usable as a DEFAULTS or config key.
   */
  public function getBaseSpecies(string $species): string {
    if (in_array($species, self::CATTLE_VARIANTS, TRUE)) {
      return 'cattle';
    }
    return $species;
  }

}
