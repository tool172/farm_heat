<?php

declare(strict_types=1);

namespace Drupal\farm_heat\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\farm_heat\Service\HeatCyclePredictor;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the Reproductive Activity dashboard block.
 */
#[Block(
  id: 'farm_heat_reproductive_activity',
  admin_label: new TranslatableMarkup('Reproductive activity this week'),
  category: new TranslatableMarkup('Farm'),
)]
class ReproductiveActivityBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected HeatCyclePredictor $heatCyclePredictor,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
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
      $container->get('farm_heat.heat_cycle_predictor'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $now = \Drupal::time()->getRequestTime();
    $log_storage = $this->entityTypeManager->getStorage('log');

    // Pregnancy checks due in the next 7 days.
    $preg_due = $log_storage->getQuery()
      ->condition('type', 'pregnancy_check')
      ->condition('status', 'pending')
      ->condition('timestamp', $now + (7 * 86400), '<=')
      ->accessCheck(FALSE)
      ->count()
      ->execute();

    // Animals predicted in heat within 7 days.
    $in_heat_window = count($this->heatCyclePredictor->getAnimalsInHeatWindow(7));

    // Suspected returns to estrus in the last 7 days.
    $return_ids = $log_storage->getQuery()
      ->condition('type', 'heat_observation')
      ->condition('status', 'done')
      ->condition('timestamp', $now - (7 * 86400), '>=')
      ->condition('heat_return_suspected', TRUE)
      ->accessCheck(FALSE)
      ->count()
      ->execute();

    // Pending protocol steps (activity logs in a group) due today.
    $end_of_today = mktime(23, 59, 59, (int) date('n', $now), (int) date('j', $now), (int) date('Y', $now));
    $protocol_due = $log_storage->getQuery()
      ->condition('type', 'activity')
      ->condition('status', 'pending')
      ->condition('timestamp', $end_of_today, '<=')
      ->exists('group')
      ->accessCheck(FALSE)
      ->count()
      ->execute();

    $rows = [
      [
        'label'  => $this->t('Pregnancy checks due this week'),
        'count'  => (int) $preg_due,
        'url'    => Url::fromUserInput('/logs/pregnancy-check', ['query' => ['status' => 'pending']]),
      ],
      [
        'label'  => $this->t('Animals predicted in heat'),
        'count'  => $in_heat_window,
        'url'    => Url::fromRoute('farm_heat.heat_predicted'),
      ],
      [
        'label'  => $this->t('Possible returns to estrus (last 7 days)'),
        'count'  => (int) $return_ids,
        'url'    => Url::fromUserInput('/logs/heat-observation', ['query' => ['heat_return_suspected' => '1']]),
      ],
      [
        'label'  => $this->t('Protocol steps due today'),
        'count'  => (int) $protocol_due,
        'url'    => Url::fromUserInput('/farm/breeding/protocols'),
      ],
    ];

    $items = [];
    foreach ($rows as $row) {
      $items[] = [
        '#type'  => 'link',
        '#title' => $this->t('@label: @count', [
          '@label' => $row['label'],
          '@count' => $row['count'],
        ]),
        '#url' => $row['url'],
      ];
    }

    return [
      '#theme'      => 'item_list',
      '#title'      => $this->t('Reproductive activity this week'),
      '#items'      => $items,
      '#attributes' => ['class' => ['farm-heat-activity-block']],
      '#cache'      => ['max-age' => 3600],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge(): int {
    return 3600;
  }

}
