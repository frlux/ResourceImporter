<?php

namespace Drupal\fontanalib\Plugin\migrate\process;
use Drupal\migrate\ProcessPluginBase;

/**
 * Perform custom value transformations.
 *
 * @MigrateProcessPlugin(
 *   id = "filter_concat_array"
 * )
 *
 * To do custom value transformations use the following:
 *
 * @code
 * field_text:
 *   plugin: transform_value
 *   source: text
 * @endcode
 *
 */

class FilterConcatArray extends ProcessPluginBase {
  /**
   * SubProcess constructor.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition) {
    $configuration += [
      'limit_return' => FALSE,
    ];
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    $array = array();
    foreach($value as $key => $item){
      $val = '';
      foreach($item as $k => $v){
        $val = !$v ? $val : $val . " " . $v;
      }
      $array[]=$val;
    }
    return !$this->configuration['limit_return'] ? $array : $array[0];
  }
}