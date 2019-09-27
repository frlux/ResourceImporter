<?php
 
namespace Drupal\fontanalib\Plugin\QueueWorker;
 
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Database\Database;
use Drupal\fontanalib\FontanalibEvergreen;
 
/**
 * Updates Evergreen(s) from Fontanalib API data.
 *
 * @QueueWorker(
 *   id = "fontanalib_evergreen_import_worker",
 *   title = @Translation("Fontanalib Evergreen Import Worker"),
 *   cron = {"time" = 60}
 * )
 */
class FontanalibEvergreenImportWorker extends QueueWorkerBase {
 
  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    $connection = Database::getConnection();
    $gids       = $data['gids'];
 
    if (empty($gids)) {
      \Drupal::logger('fontanalib')->warning(t('FontanalibEvergreenImportWorker queue with no GPMS IDs!'));
      return;
    }
 
    $query = $connection->select('fontanalib_evergreen_staging', 'its');
    $query->fields('its');
    $query->condition('its.gid', $gids, 'IN');
    $results = $query->execute();
 
    foreach ($results as $row) {
      $gid      = (int) $row->gid;
      $evergreen_data = unserialize($row->data);
 
      try {
        $evergreen = new FontanalibEvergreen($evergreen_data);
        $evergreen->processEvergreen(); // Custom data-to-node processing
 
        $connection->merge('fontanalib_evergreen_previous')
          ->key(['gid' => $gid])
          ->insertFields([
            'gid'  => $gid,
            'data' => $row->data,
          ])
          ->updateFields(['data' => $row->data])
          ->execute();
 
        $query = $connection->delete('fontanalib_evergreen_staging');
        $query->condition('gid', $gid);
        $query->execute();
      } catch (\Exception $e) {
        watchdog_exception('fontanalib', $e);
      }
    }
  }
 
}