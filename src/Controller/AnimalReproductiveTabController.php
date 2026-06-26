<?php

declare(strict_types=1);

namespace Drupal\farm_heat\Controller;

use Drupal\asset\Entity\AssetInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\farm_heat\Service\HeatCyclePredictor;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the Reproductive history tab on Animal asset pages.
 */
class AnimalReproductiveTabController extends ControllerBase {

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
   * Checks access to the reproductive tab.
   *
   * Only allowed for animal assets the user may view. This both scopes the
   * task link (the tab only appears on animal asset pages) and protects the
   * route itself.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user.
   * @param \Drupal\asset\Entity\AssetInterface $asset
   *   The asset from the upcast route parameter.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   The access result.
   */
  public function access(AccountInterface $account, AssetInterface $asset): AccessResult {
    return AccessResult::allowedIf($asset->bundle() === 'animal' && $asset->access('view', $account))
      ->addCacheableDependency($asset);
  }

  /**
   * Renders the reproductive history page for an animal asset.
   *
   * @param \Drupal\asset\Entity\AssetInterface $asset
   *   The animal asset from the upcast route parameter.
   *
   * @return array
   *   Render array.
   */
  public function content(AssetInterface $asset): array {
    $entity = $asset;

    $build = [];
    $asset_id = (int) $entity->id();
    $species  = $this->heatCyclePredictor->resolveSpeciesFromAsset($entity);

    // ── Section 1: Reproductive summary ────────────────────────────────── //
    $build['summary'] = [
      '#type'  => 'fieldset',
      '#title' => $this->t('Reproductive summary'),
    ];

    $now        = \Drupal::time()->getRequestTime();
    $df         = \Drupal::service('date.formatter');
    $last_heat  = $this->heatCyclePredictor->getLastHeat($asset_id);
    $prediction = $this->heatCyclePredictor->predictNextHeat($asset_id, $species);

    $summary_items = [];

    if ($last_heat) {
      $last_ts  = (int) $last_heat->get('timestamp')->value;
      $days_ago = (int) round(($now - $last_ts) / 86400);
      $summary_items[] = $this->t('Last heat: @date (@days days ago)', [
        '@date' => $df->format($last_ts, 'custom', 'M j Y'),
        '@days' => $days_ago,
      ]);
    }
    else {
      $summary_items[] = $this->t('Last heat: not recorded');
    }

    if ($prediction) {
      $label = $df->format($prediction['predicted_ts'], 'custom', 'M j Y');
      if ($prediction['in_window']) {
        $label .= ' — IN HEAT WINDOW';
      }
      elseif ($prediction['days_until'] > 0) {
        $label .= ' (in ' . $prediction['days_until'] . ' days)';
      }
      else {
        $label .= ' (' . abs($prediction['days_until']) . ' days overdue)';
      }
      $summary_items[] = $this->t('Next predicted heat: @label', ['@label' => $label]);
    }
    else {
      $summary_items[] = $this->t('Next predicted heat: insufficient history');
    }

    // Current breeding status from most recent breeding log.
    $breeding_ids = $this->entityTypeManager()->getStorage('log')->getQuery()
      ->condition('type', 'breeding')
      ->condition('asset', $asset_id)
      ->condition('status', 'done')
      ->sort('timestamp', 'DESC')
      ->range(0, 1)
      ->accessCheck(FALSE)
      ->execute();

    if (!empty($breeding_ids)) {
      $breeding = $this->entityTypeManager()->getStorage('log')->load(reset($breeding_ids));
      $lifecycle = $breeding->hasField('breeding_lifecycle_status') ? $breeding->get('breeding_lifecycle_status')->value : '';
      if ($lifecycle) {
        $status_label = $lifecycle;
        if ($lifecycle === 'pregnant' && $breeding->hasField('breeding_due_date') && !$breeding->get('breeding_due_date')->isEmpty()) {
          $due = (int) $breeding->get('breeding_due_date')->value;
          $status_label .= ' — due ' . $df->format($due, 'custom', 'M j Y');
        }
        $summary_items[] = $this->t('Current status: @status', ['@status' => $status_label]);
      }
    }

    // Offspring count.
    $offspring_count = $this->entityTypeManager()->getStorage('asset')->getQuery()
      ->condition('type', 'animal')
      ->condition('parent', $asset_id)
      ->accessCheck(FALSE)
      ->count()
      ->execute();

    $summary_items[] = $this->t('Total offspring recorded: @count', ['@count' => (int) $offspring_count]);

    $build['summary']['items'] = [
      '#theme'      => 'item_list',
      '#items'      => $summary_items,
      '#attributes' => [
        'class' => [
          $prediction && $prediction['in_window'] ? 'farm-heat-in-window' : '',
        ],
      ],
    ];

    // ── Section 2: Heat observation history ────────────────────────────── //
    $build['heat_history'] = [
      '#type'  => 'fieldset',
      '#title' => $this->t('Heat observation history'),
    ];

    $heat_ids = $this->entityTypeManager()->getStorage('log')->getQuery()
      ->condition('type', 'heat_observation')
      ->condition('asset', $asset_id)
      ->sort('timestamp', 'DESC')
      ->accessCheck(FALSE)
      ->execute();

    if (!empty($heat_ids)) {
      $heat_logs = $this->entityTypeManager()->getStorage('log')->loadMultiple($heat_ids);
      $rows = [];
      foreach ($heat_logs as $log) {
        $ts         = (int) $log->get('timestamp')->value;
        $score      = $log->hasField('heat_score') ? $log->get('heat_score')->value : '';
        $days_since = $log->hasField('heat_days_since_last') ? $log->get('heat_days_since_last')->value : '';
        $return_flag = ($log->hasField('heat_return_suspected') && $log->get('heat_return_suspected')->value) ? $this->t('Yes') : '—';
        $notes = $log->hasField('notes') ? strip_tags($log->get('notes')->value ?? '') : '';
        $rows[] = [
          $df->format($ts, 'custom', 'M j Y'),
          $score ? 'Score ' . $score : '—',
          $days_since !== NULL && $days_since !== '' ? (string) $days_since : '—',
          $return_flag,
          $notes ? substr($notes, 0, 80) : '—',
        ];
      }
      $build['heat_history']['table'] = [
        '#type'   => 'table',
        '#header' => [
          $this->t('Date'),
          $this->t('Score'),
          $this->t('Days since last'),
          $this->t('Return suspected'),
          $this->t('Notes'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No heat observations recorded.'),
      ];
    }
    else {
      $build['heat_history']['empty'] = [
        '#markup' => '<p>' . $this->t('No heat observations recorded.') . '</p>',
      ];
    }

    // ── Section 3: Breeding history ─────────────────────────────────────── //
    $build['breeding_history'] = [
      '#type'  => 'fieldset',
      '#title' => $this->t('Breeding history'),
    ];

    $breeding_all_ids = $this->entityTypeManager()->getStorage('log')->getQuery()
      ->condition('type', 'breeding')
      ->condition('asset', $asset_id)
      ->sort('timestamp', 'DESC')
      ->accessCheck(FALSE)
      ->execute();

    if (!empty($breeding_all_ids)) {
      $breeding_logs = $this->entityTypeManager()->getStorage('log')->loadMultiple($breeding_all_ids);
      $rows = [];
      foreach ($breeding_logs as $log) {
        $ts = (int) $log->get('timestamp')->value;

        $sire_label = '—';
        if ($log->hasField('breeding_sire') && !$log->get('breeding_sire')->isEmpty()) {
          $sire = $log->get('breeding_sire')->entity;
          if ($sire) {
            $sire_label = $sire->label();
          }
        }

        $method    = $log->hasField('breeding_method') ? ($log->get('breeding_method')->value ?? '—') : '—';
        $lifecycle = $log->hasField('breeding_lifecycle_status') ? ($log->get('breeding_lifecycle_status')->value ?? '—') : '—';

        $due = '—';
        if ($log->hasField('breeding_due_date') && !$log->get('breeding_due_date')->isEmpty()) {
          $due = $df->format((int) $log->get('breeding_due_date')->value, 'custom', 'M j Y');
        }

        $calved = '—';
        if ($log->hasField('breeding_calving_date') && !$log->get('breeding_calving_date')->isEmpty()) {
          $calved = $df->format((int) $log->get('breeding_calving_date')->value, 'custom', 'M j Y');
        }

        $rows[] = [
          $df->format($ts, 'custom', 'M j Y'),
          $sire_label,
          $method,
          $lifecycle,
          $due,
          $calved,
        ];
      }
      $build['breeding_history']['table'] = [
        '#type'   => 'table',
        '#header' => [
          $this->t('Date'),
          $this->t('Sire'),
          $this->t('Method'),
          $this->t('Status'),
          $this->t('Due date'),
          $this->t('Calved'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No breeding records found.'),
      ];
    }
    else {
      $build['breeding_history']['empty'] = [
        '#markup' => '<p>' . $this->t('No breeding records found.') . '</p>',
      ];
    }

    // ── Section 4: Offspring ─────────────────────────────────────────────── //
    $build['offspring'] = [
      '#type'  => 'fieldset',
      '#title' => $this->t('Offspring'),
    ];

    $offspring_ids = $this->entityTypeManager()->getStorage('asset')->getQuery()
      ->condition('type', 'animal')
      ->condition('parent', $asset_id)
      ->sort('birthdate', 'DESC')
      ->accessCheck(FALSE)
      ->execute();

    if (!empty($offspring_ids)) {
      $offspring = $this->entityTypeManager()->getStorage('asset')->loadMultiple($offspring_ids);

      $active_count  = 0;
      $rows          = [];
      foreach ($offspring as $calf) {
        $sex = $calf->hasField('sex') ? ($calf->get('sex')->value ?? '—') : '—';
        $dob = '—';
        if ($calf->hasField('birthdate') && !$calf->get('birthdate')->isEmpty()) {
          $dob = $df->format((int) $calf->get('birthdate')->value, 'custom', 'M j Y');
        }
        $stage = $calf->hasField('animal_stage') ? ($calf->get('animal_stage')->value ?? '—') : '—';
        $disposition = '—';
        if ($calf->hasField('disposition') && !$calf->get('disposition')->isEmpty()) {
          $disposition = $calf->get('disposition')->value;
        }
        if ($disposition === 'active' || $disposition === '—') {
          $active_count++;
        }
        $rows[] = [
          [
            'data' => [
              '#type'  => 'link',
              '#title' => $calf->label(),
              '#url'   => Url::fromRoute('entity.asset.canonical', ['asset' => $calf->id()]),
            ],
          ],
          $sex,
          $dob,
          $stage,
          $disposition,
        ];
      }

      $build['offspring']['count'] = [
        '#markup' => '<p>' . $this->t('@total offspring total, @active active', [
          '@total'  => count($offspring_ids),
          '@active' => $active_count,
        ]) . '</p>',
      ];
      $build['offspring']['table'] = [
        '#type'   => 'table',
        '#header' => [
          $this->t('Name / tag'),
          $this->t('Sex'),
          $this->t('Date of birth'),
          $this->t('Stage'),
          $this->t('Disposition'),
        ],
        '#rows' => $rows,
      ];
    }
    else {
      $build['offspring']['empty'] = [
        '#markup' => '<p>' . $this->t('No offspring recorded.') . '</p>',
      ];
    }

    return $build;
  }

}
