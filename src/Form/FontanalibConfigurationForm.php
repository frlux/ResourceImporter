<?php
 
namespace Drupal\fontanalib\Form;
 
use Drupal\Core\Form\ConfigFormBase;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Form\FormStateInterface;
 
/**
 * Defines a form that configures forms module settings.
 */
class FontanalibConfigurationForm extends ConfigFormBase {
 
  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'fontanalib_admin_settings';
  }
 
  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'fontanalib.settings',
    ];
  }
 
  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, Request $request = NULL) {
    $config = $this->config('fontanalib.settings');
    $state  = \Drupal::state();
    $form["#attributes"]["autocomplete"] = "off";
    $form['fontanalib'] = array(
      '#type'  => 'fieldset',
      '#title' => $this->t('Fontanalib settings'),
    );
    $form['fontanalib']['url'] = array(
      '#type'          => 'textfield',
      '#title'         => $this->t('Fontanalib API URL'),
      '#default_value' => $config->get('fontanalib.url'),
    );
    $form['fontanalib']['username'] = array(
      '#type'          => 'textfield',
      '#title'         => $this->t('Username'),
      '#default_value' => $config->get('fontanalib.username'),
    );
    $form['fontanalib']['password'] = array(
      '#type'          => 'textfield',
      '#title'         => $this->t('Password'),
      '#default_value' => '',
      '#description'   => t('Leave blank to make no changes, use an invalid string to disable if need be.')
    );
    $form['fontanalib']['public_key'] = array(
      '#type'          => 'textfield',
      '#title'         => $this->t('Public Key'),
      '#default_value' => $config->get('fontanalib.public_key'),
    );
    $form['fontanalib']['private_key'] = array(
      '#type'          => 'textfield',
      '#title'         => $this->t('Private Key'),
      '#default_value' => '',
      '#description'   => t('Leave blank to make no changes, use an invalid string to disable if need be.')
    );
    $form['fontanalib']['division'] = array(
      '#type'          => 'textfield',
      '#title'         => $this->t('Division'),
      '#default_value' => $config->get('fontanalib.division'),
    );
    $form['fontanalib']['territory'] = array(
      '#type'          => 'textfield',
      '#title'         => $this->t('Territory'),
      '#default_value' => $config->get('fontanalib.territory'),
    );
    $nums   = [
      5, 10, 25, 50, 75, 100, 150, 200, 250, 300, 400, 500, 600, 700, 800, 900,
    ];
    $limits = array_combine($nums, $nums);
    $form['cron_download_limit'] = [
      '#type'          => 'select',
      '#title'         => t('Cron API Download Throttle'),
      '#options'       => $limits,
      '#default_value' => $state->get('fontanalib.cron_download_limit', 100),
    ];
    $form['cron_process_limit'] = [
      '#type'          => 'select',
      '#title'         => t('Cron Queue Node Process Throttle'),
      '#options'       => $limits,
      '#default_value' => $state->get('fontanalib.cron_process_limit', 25),
    ];
    return parent::buildForm($form, $form_state);
  }
 
  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $config = $this->config('fontanalib.settings');
    $state  = \Drupal::state();
    $config->set('fontanalib.url', $values['url']);
    $config->set('fontanalib.username', $values['username']);
    $config->set('fontanalib.public_key', $values['public_key']);
    $config->set('fontanalib.division', $values['division']);
    $config->set('fontanalib.territory', $values['territory']);
    $config->save();
    if (!empty($values['private_key'])) {
      $state->set('fontanalib.private_key', $values['private_key']);
    }
    if (!empty($values['password'])) {
      $state->set('fontanalib.password', $values['password']);
    }
    $state->set('fontanalib.cron_download_limit', $values['cron_download_limit']);
    $state->set('fontanalib.cron_process_limit', $values['cron_process_limit']);
  }
 
}