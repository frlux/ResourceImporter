<?php
 
namespace Drupal\fontanalib\Form;
 
use Drupal\Core\Database\Database;
use Drupal\Core\Form\FormBase;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Form\FormStateInterface;
use Drupal\fontanalib\FontanalibConnection;
use Drupal\fontanalib\FontanalibEvergreen;
 
/**
 * Defines a form that triggers batch operations to download and process Evergreen
 * data from the Fontanalib API.
 * Batch operations are included in this class as methods.
 */
class FontanalibEvergreenImportForm extends FormBase {
 
  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'fontanalib_evergreen_import_form';
  }
 
  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, Request $request = NULL) {
    $connection = new FontanalibConnection();
    $data       = $connection->queryEndpoint('evergreensDetailFull', [
      'limit'     => 1,
      'url_query' => [
        'sort' => 'gid asc',
      ]
    ]);
 
    if (empty($data->pagination->total_count)) {
      $msg  = 'A total count of Evergreens was not returned, indicating that there';
      $msg .= ' is a problem with the connection. See ';
      $msg .= '<a href="/admin/config/services/fontanalib">the Overview page</a>';
      $msg .= 'for more details.';
      drupal_set_message(t($msg), 'error');
    }
 
    $form['count_display'] = [
      '#type'  => 'item',
      '#title' => t('Evergreens Found'),
      'markup'  => [
        '#markup' => $data->pagination->total_count,
      ]
    ];
 
    $form['count'] = [
      '#type'  => 'value',
      '#value' => $data->pagination->total_count,
    ];
 
    $nums   = [
      5, 10, 25, 50, 75, 100, 150, 200, 250, 300, 400, 500, 600, 700, 800, 900,
    ];
    $limits = array_combine($nums, $nums);
    $desc   = 'This is the number of Evergreens the API should return each call ' .
      'as the operation pages through the data.';
    $form['download_limit'] = [
      '#type'          => 'select',
      '#title'         => t('API Download Throttle'),
      '#options'       => $limits,
      '#default_value' => 200,
      '#description'   => t($desc),
    ];
    $desc = 'This is the number of Evergreens to analyze and save to Drupal as ' .
      'the operation pages through the data.<br />This is labor intensive so ' .
      'usually a lower number than the above throttle';
    $form['process_limit'] = [
      '#type'          => 'select',
      '#title'         => t('Node Process Throttle'),
      '#options'       => $limits,
      '#default_value' => 50,
      '#description'   => t($desc),
    ];
 
    $form['actions']['#type'] = 'actions';
 
    $form['actions']['submit'] = [
      '#type'     => 'submit',
      '#value'    => t('Import All Evergreens'),
      '#disabled' => empty($data->pagination->total_count),
    ];
 
    return $form;
  }
 
  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $connection = Database::getConnection();
    $queue      = \Drupal::queue('fontanalib_evergreen_import_worker');
    $class      = 'Drupal\fontanalib\Form\FontanalibEvergreenImportForm';
    $batch      = [
      'title'      => t('Downloading & Processing Fontanalib Evergreen Data'),
      'operations' => [
        [ // Operation to download all of the evergreens
          [$class, 'downloadEvergreens'], // Static method notation
          [
            $form_state->getValue('count', 0),
            $form_state->getValue('download_limit', 0),
          ],
        ],
        [ // Operation to process & save the evergreen data
          [$class, 'processEvergreens'], // Static method notation
          [
            $form_state->getValue('process_limit', 0),
          ],
        ],
      ],
      'finished' => [$class, 'finishedBatch'], // Static method notation
    ];
    batch_set($batch);
    // Lock cron out of processing while these batch operations are being
    // processed
    \Drupal::state()->set('fontanalib.evergreen_import_semaphore', TRUE);
    // Delete existing queue
    while ($worker = $queue->claimItem()) {
      $queue->deleteItem($worker);
    }
    // Clear out the staging table for fresh, whole data
    $connection->truncate('fontanalib_evergreen_staging')->execute();
  }

  /**
   * Batch operation to download all of the Evergreen data from Fontanalib and store
   * it in the fontanalib_evergreen_staging database table.
   *
   * @param int   $api_count
   * @param array $context
   */
  public static function downloadEvergreens($api_count, $limit, &$context) {
    $database = Database::getConnection();
    if (!isset($context['sandbox']['progress'])) {
      $context['sandbox'] = [
        'progress' => 0,
        'limit'    => $limit,
        'max'      => $api_count,
      ];
      $context['results']['downloaded'] = 0;
    }
    $sandbox = &$context['sandbox'];
 
    $fontanalib = new FontanalibConnection();
    $data   = $fontanalib->queryEndpoint('evergreensDetailFull', [
      'limit'     => $sandbox['limit'],
      'url_query' => [
        'offset' => (string) $sandbox['progress'],
        'sort'   => 'gid asc',
      ],
    ]);
 
    foreach ($data->response_data as $evergreen_data) {
      // Check for empty or non-numeric GIDs
      if (empty($evergreen_data->gid)) {
        $msg = t('Empty GID at progress @p for the data:', [
          '@p' => $sandbox['progress'],
        ]);
        $msg .= '<br /><pre>' . print_r($evergreen_data, TRUE) . '</pre>';
        \Drupal::logger('fontanalib')->warning($msg);
        $sandbox['progress']++;
        continue;
      } elseif (!is_numeric($evergreen_data->gid)) {
        $msg = t('Non-numeric GID at progress progress @p for the data:', [
          '@p' => $sandbox['progress'],
        ]);
        $msg .= '<br /><pre>' . print_r($evergreen_data, TRUE) . '</pre>';
        \Drupal::logger('fontanalib')->warning($msg);
        $sandbox['progress']++;
        continue;
      }
      // Store the data
      $database->merge('fontanalib_evergreen_staging')
        ->key(['gid' => (int) $evergreen_data->gid])
        ->insertFields([
          'gid'  => (int) $evergreen_data->gid,
          'data' => serialize($evergreen_data),
        ])
        ->updateFields(['data' => serialize($evergreen_data)])
        ->execute()
      ;
      $context['results']['downloaded']++;
      $sandbox['progress']++;
      // Build a message so this isn't entirely boring for admins
      $context['message'] = '<h2>' . t('Downloading API data...') . '</h2>';
      $context['message'] .= t('Queried @c of @t Evergreen entries.', [
        '@c' => $sandbox['progress'],
        '@t' => $sandbox['max'],
      ]);
    }
 
    if ($sandbox['max']) {
      $context['finished'] = $sandbox['progress'] / $sandbox['max'];
    }
    // If completely done downloading, set the last time it was done, so that
    // cron can keep the data up to date with smaller queries
    if ($context['finished'] >= 1) {
      $last_time = \Drupal::time()->getRequestTime();
      \Drupal::state()->set('fontanalib.evergreen_import_last', $last_time);
    }
  }

  /**
   * Batch operation to extra data from the fontanalib_evergreen_staging table and
   * save it to a new node or one found via GID.
   *
   * @param array $context
   */
  public static function processEvergreens($limit, &$context) {
    $connection = Database::getConnection();
    if (!isset($context['sandbox']['progress'])) {
      $context['sandbox'] = [
        'progress' => 0,
        'limit'    => $limit,
        'max'      => (int)$connection->select('fontanalib_evergreen_staging', 'its')
          ->countQuery()->execute()->fetchField(),
      ];
      $context['results']['evergreens'] = 0;
      $context['results']['nodes']  = 0;
      // Count new versus existing
      $context['results']['nodes_inserted'] = 0;
      $context['results']['nodes_updated']  = 0;
    }
    $sandbox = &$context['sandbox'];
 
    $query = $connection->select('fontanalib_evergreen_staging', 'its')
      ->fields('its')
      ->range(0, $sandbox['limit'])
    ;
    $results = $query->execute();
 
    foreach ($results as $row) {
      $gid        = (int) $row->gid;
      $evergreen_data   = unserialize($row->data);
      $evergreen        = new FontanalibEvergreen($evergreen_data);
      $node_saved = $evergreen->processEvergreen(); // Custom data-to-node processing
 
      $connection->merge('fontanalib_evergreen_previous')
        ->key(['gid' => $gid])
        ->insertFields([
          'gid'  => $gid,
          'data' => $row->data,
        ])
        ->updateFields(['data' => $row->data])
        ->execute()
      ;
 
      $query = $connection->delete('fontanalib_evergreen_staging');
      $query->condition('gid', $gid);
      $query->execute();
 
      $sandbox['progress']++;
      $context['results']['evergreens']++;
      // Tally only the nodes saved
      if ($node_saved) {
        $context['results']['nodes']++;
        $context['results']['nodes_' . $node_saved]++;
      }
 
      // Build a message so this isn't entirely boring for admins
      $msg = '<h2>' . t('Processing API data to site content...') . '</h2>';
      $msg .= t('Processed @p of @t Evergreens, @n new & @u updated', [
        '@p' => $sandbox['progress'],
        '@t' => $sandbox['max'],
        '@n' => $context['results']['nodes_inserted'],
        '@u' => $context['results']['nodes_updated'],
      ]);
      $msg .= '<br />';
      $msg .= t('Last evergreen: %t %g %n', [
        '%t' => $evergreen->getTitle(),
        '%g' => '(GID:' . $gid . ')',
        '%n' => '(node:' . $evergreen->getNode()->id() . ')',
      ]);
      $context['message'] = $msg;
    }
 
    if ($sandbox['max']) {
      $context['finished'] = $sandbox['progress'] / $sandbox['max'];
    }
  }

  /**
   * Reports the results of the Evergreen import operations.
   *
   * @param bool  $success
   * @param array $results
   * @param array $operations
   */
  public static function finishedBatch($success, $results, $operations) {
    // Unlock to allow cron to update the data later
    \Drupal::state()->set('fontanalib.evergreen_import_semaphore', FALSE);
    // The 'success' parameter means no fatal PHP errors were detected. All
    // other error management should be handled using 'results'.
    $downloaded = t('Finished with an error.');
    $processed  = FALSE;
    $saved      = FALSE;
    $inserted   = FALSE;
    $updated    = FALSE;
    if ($success) {
      $downloaded = \Drupal::translation()->formatPlural(
        $results['downloaded'],
        'One evergreen downloaded.',
        '@count evergreens downloaded.'
      );
      $processed  = \Drupal::translation()->formatPlural(
        $results['evergreens'],
        'One evergreen processed.',
        '@count evergreens processed.'
      );
      $saved      = \Drupal::translation()->formatPlural(
        $results['nodes'],
        'One node saved.',
        '@count nodes saved.'
      );
      $inserted   = \Drupal::translation()->formatPlural(
        $results['nodes_inserted'],
        'One was created.',
        '@count were created.'
      );
      $updated    = \Drupal::translation()->formatPlural(
        $results['nodes_updated'],
        'One was updated.',
        '@count were updated.'
      );
    }
    drupal_set_message($downloaded);
    if ($processed) {
      drupal_set_message($processed);
    };
    if ($saved) {
      drupal_set_message($saved);
    };
    if ($inserted) {
      drupal_set_message($inserted);
    };
    if ($updated) {
      drupal_set_message($updated);
    };
  }
}