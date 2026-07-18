<?php

declare(strict_types=1);

namespace Drupal\Tests\farm_heat\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\farm_breeding\Service\SpeciesResolver;
use Drupal\farm_heat\Service\HeatCyclePredictor;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins the pure heat-cycle logic of HeatCyclePredictor.
 *
 * Covers the domain constants that drive every prediction — the base-species
 * mapping (breed variants collapse to their species for cycle lookup) and the
 * cycle-length resolution (farm_breeding.settings override, else the built-in
 * defaults, with the min/max window derived when only 'typical' is configured).
 * These are pure functions of their inputs, so they are unit-tested with mocked
 * services rather than a booted kernel.
 */
#[Group('farm')]
class HeatCyclePredictorTest extends UnitTestCase {

  /**
   * Builds the predictor with a config factory backed by the given key map.
   *
   * @param array $config_map
   *   Map of farm_breeding.settings config keys to values (missing = NULL).
   */
  protected function predictor(array $config_map = []): HeatCyclePredictor {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(fn($key) => $config_map[$key] ?? NULL);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('farm_breeding.settings')->willReturn($config);

    return new HeatCyclePredictor(
      $this->createMock(EntityTypeManagerInterface::class),
      $factory,
      $this->createMock(SpeciesResolver::class),
      $this->createMock(TimeInterface::class),
    );
  }

  /**
   * Breed variants collapse to their base species; others pass through.
   */
  public function testBaseSpeciesMapping(): void {
    $p = $this->predictor();
    $this->assertSame('cattle', $p->getBaseSpecies('angus'));
    $this->assertSame('cattle', $p->getBaseSpecies('dairy_cattle'));
    $this->assertSame('cattle', $p->getBaseSpecies('holstein'));
    $this->assertSame('cattle', $p->getBaseSpecies('cattle'));
    $this->assertSame('sheep', $p->getBaseSpecies('sheep'));
    $this->assertSame('goat', $p->getBaseSpecies('goat'));
  }

  /**
   * With no config, cycle lengths fall back to the built-in defaults.
   */
  public function testCycleLengthDefaults(): void {
    $p = $this->predictor();
    $this->assertSame(['typical' => 21, 'min' => 18, 'max' => 24], $p->getCycleLength('cattle'));
    $this->assertSame(['typical' => 17, 'min' => 14, 'max' => 19], $p->getCycleLength('sheep'));
    // A breed variant resolves to its species' defaults.
    $this->assertSame(['typical' => 21, 'min' => 18, 'max' => 24], $p->getCycleLength('angus'));
    // An unknown species falls back to cattle.
    $this->assertSame(['typical' => 21, 'min' => 18, 'max' => 24], $p->getCycleLength('llama'));
  }

  /**
   * A full config override wins over the defaults.
   */
  public function testCycleLengthConfigOverride(): void {
    $p = $this->predictor([
      'heat_cycle_days.cattle.typical' => 22,
      'heat_cycle_days.cattle.min' => 19,
      'heat_cycle_days.cattle.max' => 25,
    ]);
    $this->assertSame(['typical' => 22, 'min' => 19, 'max' => 25], $p->getCycleLength('cattle'));
  }

  /**
   * When only 'typical' is configured, the window derives as typical ± 3.
   */
  public function testCycleLengthDerivesWindowFromTypical(): void {
    $p = $this->predictor(['heat_cycle_days.cattle.typical' => 20]);
    $this->assertSame(['typical' => 20, 'min' => 17, 'max' => 23], $p->getCycleLength('cattle'));
  }

}
