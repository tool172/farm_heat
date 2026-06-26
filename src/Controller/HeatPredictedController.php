<?php

declare(strict_types=1);

namespace Drupal\farm_heat\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\farm_heat\Service\HeatCyclePredictor;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists animals predicted to be in their heat window within the next 7 days.
 */
class HeatPredictedController extends ControllerBase {

  public function __construct(
    protected HeatCyclePredictor $heatCyclePredictor,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('farm_heat.heat_cycle_predictor'),
    );
  }

  /**
   * Renders the predicted-in-heat animal listing.
   *
   * @return array
   *   Render array.
   */
  public function content(): array {
    $animals = $this->heatCyclePredictor->getAnimalsInHeatWindow(7);
    $df      = \Drupal::service('date.formatter');
    $now     = \Drupal::time()->getRequestTime();

    if (empty($animals)) {
      return [
        '#markup' => '<p>' . $this->t('No animals are predicted to be in heat within the next 7 days.') . '</p>',
      ];
    }

    $rows = [];
    foreach ($animals as $asset_id => $prediction) {
      $asset = $prediction['asset'];

      $window_label = $df->format($prediction['window_start_ts'], 'custom', 'M j')
        . ' – '
        . $df->format($prediction['window_end_ts'], 'custom', 'M j Y');

      if ($prediction['in_window']) {
        $status = $this->t('In window now');
      }
      elseif ($prediction['days_until'] > 0) {
        $status = $this->t('In @days days', ['@days' => $prediction['days_until']]);
      }
      else {
        $status = $this->t('@days days overdue', ['@days' => abs($prediction['days_until'])]);
      }

      $last_heat_label = $df->format($prediction['last_heat_ts'], 'custom', 'M j Y');
      $days_ago = (int) round(($now - $prediction['last_heat_ts']) / 86400);

      $rows[] = [
        [
          'data' => [
            '#type'  => 'link',
            '#title' => $asset->label(),
            '#url'   => Url::fromRoute('entity.asset.canonical', ['asset' => $asset_id]),
          ],
        ],
        $last_heat_label . ' (' . $days_ago . ' days ago)',
        $window_label,
        $status,
        [
          'data' => [
            '#type'  => 'link',
            '#title' => $this->t('Record heat'),
            '#url'   => Url::fromRoute('farm_quick.form', ['id' => 'heat_observation'], ['query' => ['asset' => $asset_id]]),
          ],
        ],
      ];
    }

    return [
      '#type'   => 'table',
      '#header' => [
        $this->t('Animal'),
        $this->t('Last heat'),
        $this->t('Predicted window'),
        $this->t('Status'),
        $this->t('Actions'),
      ],
      '#rows'  => $rows,
      '#empty' => $this->t('No animals predicted in heat.'),
    ];
  }

}
