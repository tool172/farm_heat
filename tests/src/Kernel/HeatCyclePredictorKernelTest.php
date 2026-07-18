<?php

declare(strict_types=1);

namespace Drupal\Tests\farm_heat\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\farm_breeding\Service\SpeciesResolver;
use Drupal\farm_heat\Service\HeatCyclePredictor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Pins the log-driven heat predictions in HeatCyclePredictor.
 *
 * Covers the two methods that query real logs:
 *  - predictNextHeat(): the next heat window is projected from the animal's
 *    most recent done heat_observation log using the species cycle length;
 *  - checkReturnToEstrus(): an observed heat is flagged a suspected return to
 *    estrus when a qualifying breeding log (bred / pending_check / pregnant)
 *    falls in the return window (roughly one cycle before the observation) —
 *    the "open cow" early warning.
 *
 * The heat_observation and breeding log bundles are created directly (with the
 * breeding_lifecycle_status field), and the predictor is instantiated with a
 * mocked SpeciesResolver — the two methods take the species key as an argument
 * and never touch the resolver — so the heavy farm_breeding / farm_quick /
 * farm_ui_views module chain is not needed.
 */
#[Group('farm')]
#[RunTestsInSeparateProcesses]
class HeatCyclePredictorKernelTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * farm_animal_disposition ships no config schema for its settings, and the
   * hand-built log types are intentionally minimal.
   *
   * {@inheritdoc}
   */
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'asset',
    'entity',
    'entity_reference_revisions',
    'farm_animal',
    'farm_animal_disposition',
    'farm_breeding',
    'farm_entity',
    'farm_entity_fields',
    'farm_field',
    'farm_flag',
    'farm_format',
    'farm_heat',
    'farm_id_tag',
    'farm_log',
    'farm_log_asset',
    'field',
    'file',
    'filter',
    'fraction',
    'image',
    'log',
    'options',
    'quantity',
    'state_machine',
    'system',
    'taxonomy',
    'text',
    'user',
    'views',
  ];

  protected HeatCyclePredictor $predictor;
  protected int $now;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setUpCurrentUser([], [], TRUE);
    $this->installEntitySchema('asset');
    $this->installEntitySchema('log');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('user');
    $this->installEntitySchema('user_role');
    $this->installConfig([
      'farm_format',
      'farm_animal',
      'farm_animal_disposition',
      'farm_log_asset',
      'system',
    ]);

    // Install the heat_observation (farm_heat) and breeding (farm_breeding) log
    // types. Their FarmLogType plugins install the bundle fields, including
    // breeding_lifecycle_status which checkReturnToEstrus() filters on.
    $this->installConfig(['farm_heat', 'farm_breeding']);

    $this->now = (int) $this->container->get('datetime.time')->getRequestTime();

    $this->predictor = new HeatCyclePredictor(
      $this->container->get('entity_type.manager'),
      $this->container->get('config.factory'),
      $this->createMock(SpeciesResolver::class),
      $this->container->get('datetime.time'),
    );
  }

  /**
   * Creates a female animal and returns its id.
   */
  protected function animal(string $name = 'Cow'): int {
    $asset = $this->container->get('entity_type.manager')->getStorage('asset')->create([
      'type' => 'animal',
      'name' => $name,
      'sex' => 'F',
    ]);
    $asset->save();
    return (int) $asset->id();
  }

  /**
   * Creates a done heat_observation log for an animal at a time.
   */
  protected function heatLog(int $animal_id, int $timestamp): void {
    $this->container->get('entity_type.manager')->getStorage('log')->create([
      'type' => 'heat_observation',
      'name' => 'Heat',
      'timestamp' => $timestamp,
      'status' => 'done',
      'asset' => [$animal_id],
    ])->save();
  }

  /**
   * Creates a done breeding log with a lifecycle status.
   */
  protected function breedingLog(int $animal_id, int $timestamp, string $status): void {
    $this->container->get('entity_type.manager')->getStorage('log')->create([
      'type' => 'breeding',
      'name' => 'Breeding',
      'timestamp' => $timestamp,
      'status' => 'done',
      'asset' => [$animal_id],
      'breeding_lifecycle_status' => $status,
    ])->save();
  }

  /**
   * With no prior heat, there is nothing to predict.
   */
  public function testPredictReturnsNullWithoutHistory(): void {
    $cow = $this->animal();
    $this->assertNull($this->predictor->predictNextHeat($cow, 'cattle'));
  }

  /**
   * The next heat projects one cattle cycle (21d, window 18–24d) from the last.
   */
  public function testPredictNextHeatWindow(): void {
    $day = 86400;
    $cow = $this->animal();
    // Last heat 20 days ago → next predicted in 1 day, and today is inside the
    // 18–24 day window (which opened 2 days ago).
    $last = $this->now - (20 * $day);
    $this->heatLog($cow, $last);

    $p = $this->predictor->predictNextHeat($cow, 'cattle');
    $this->assertNotNull($p);
    $this->assertSame($last + (21 * $day), $p['predicted_ts'], 'Predicted = last + typical cycle.');
    $this->assertSame($last + (18 * $day), $p['window_start_ts']);
    $this->assertSame($last + (24 * $day), $p['window_end_ts']);
    $this->assertSame(1, $p['days_until']);
    $this->assertTrue($p['in_window'], 'Today falls inside the fertile window.');
  }

  /**
   * The latest done heat is the basis, not an older one.
   */
  public function testPredictUsesMostRecentHeat(): void {
    $day = 86400;
    $cow = $this->animal();
    $this->heatLog($cow, $this->now - (40 * $day));
    $recent = $this->now - (3 * $day);
    $this->heatLog($cow, $recent);

    $p = $this->predictor->predictNextHeat($cow, 'cattle');
    $this->assertSame($recent + (21 * $day), $p['predicted_ts']);
  }

  /**
   * A qualifying breeding ~one cycle earlier flags a suspected return.
   */
  public function testReturnToEstrusSuspected(): void {
    $day = 86400;
    $cow = $this->animal();
    // Bred 21 days ago; a heat observed today falls in the return window.
    $this->breedingLog($cow, $this->now - (21 * $day), 'bred');

    $r = $this->predictor->checkReturnToEstrus($cow, $this->now, 'cattle');
    $this->assertTrue($r['suspected'], 'A breeding one cycle ago suggests a return to estrus.');
    $this->assertSame(21, $r['days_since_bred']);
    $this->assertNotNull($r['breeding_log']);
  }

  /**
   * A breeding too recent to be a return (5 days ago) is not flagged.
   */
  public function testReturnToEstrusNotFlaggedWhenTooRecent(): void {
    $day = 86400;
    $cow = $this->animal();
    $this->breedingLog($cow, $this->now - (5 * $day), 'bred');

    $r = $this->predictor->checkReturnToEstrus($cow, $this->now, 'cattle');
    $this->assertFalse($r['suspected'], 'A breeding only 5 days ago is outside the return window.');
  }

  /**
   * A non-qualifying status (open) in the window is ignored.
   */
  public function testReturnToEstrusIgnoresNonQualifyingStatus(): void {
    $day = 86400;
    $cow = $this->animal();
    // In the window by date, but already recorded open — not a pending breeding.
    $this->breedingLog($cow, $this->now - (21 * $day), 'open');

    $r = $this->predictor->checkReturnToEstrus($cow, $this->now, 'cattle');
    $this->assertFalse($r['suspected'], 'Only bred / pending_check / pregnant breedings qualify.');
  }

}
