<?php

declare(strict_types=1);

namespace Drupal\piwigo_display\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Site\Settings;
use Drupal\piwigo_display\Service\PiwigoClient;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Administrative settings for the Piwigo connection.
 */
final class SettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    private readonly PiwigoClient $piwigoClient,
  ) {
    parent::__construct($config_factory);
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('piwigo_display.client'),
    );
  }

  protected function getEditableConfigNames(): array {
    return ['piwigo_display.settings'];
  }

  public function getFormId(): string {
    return 'piwigo_display_settings_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('piwigo_display.settings');
    $api_key_managed = Settings::get('piwigo_display.api_key', NULL) !== NULL;
    $base_url_managed = Settings::get('piwigo_display.base_url', NULL) !== NULL;

    $form['connection'] = [
      '#type' => 'details',
      '#title' => $this->t('Piwigo connection'),
      '#open' => TRUE,
    ];

    $form['connection']['base_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Piwigo URL'),
      '#default_value' => (string) Settings::get('piwigo_display.base_url', $config->get('base_url') ?? ''),
      '#required' => TRUE,
      '#disabled' => $base_url_managed,
      '#description' => $base_url_managed
        ? $this->t('Managed in settings.php with $settings[\'piwigo_display.base_url\'].')
        : $this->t('Example: https://photos.example.org'),
    ];

    $form['connection']['api_key'] = [
      '#type' => 'password',
      '#title' => $this->t('Piwigo API key'),
      '#default_value' => '',
      '#disabled' => $api_key_managed,
      '#attributes' => ['autocomplete' => 'new-password'],
      '#description' => $api_key_managed
        ? $this->t('Managed in settings.php with $settings[\'piwigo_display.api_key\']. The secret is never displayed here.')
        : $this->t('Piwigo 16+: personal API key. Leave blank to keep the currently stored key. For production, settings.php is recommended.'),
    ];

    $legacy_username_managed = Settings::get('piwigo_display.legacy_username', NULL) !== NULL;
    $legacy_password_managed = Settings::get('piwigo_display.legacy_password', NULL) !== NULL;

    $form['connection']['legacy'] = [
      '#type' => 'details',
      '#title' => $this->t('Legacy service account / protected binary URLs'),
      '#open' => FALSE,
      '#description' => $this->t('Optional compatibility mode for Piwigo before API keys, or as a session cookie for installations that protect derivative/original URLs.'),
    ];
    $form['connection']['legacy']['legacy_username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Piwigo username'),
      '#default_value' => (string) Settings::get('piwigo_display.legacy_username', $config->get('legacy_username') ?? ''),
      '#disabled' => $legacy_username_managed,
      '#attributes' => ['autocomplete' => 'off'],
    ];
    $form['connection']['legacy']['legacy_password'] = [
      '#type' => 'password',
      '#title' => $this->t('Piwigo password'),
      '#default_value' => '',
      '#disabled' => $legacy_password_managed,
      '#attributes' => ['autocomplete' => 'new-password'],
      '#description' => $legacy_password_managed
        ? $this->t('Managed in settings.php. The secret is never displayed here.')
        : $this->t('Leave blank to keep the stored password. Prefer settings.php for production secrets.'),
    ];

    $form['connection']['request_timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('HTTP timeout'),
      '#default_value' => (int) $config->get('request_timeout'),
      '#min' => 1,
      '#max' => 60,
      '#field_suffix' => $this->t('seconds'),
    ];

    $form['connection']['test'] = [
      '#type' => 'submit',
      '#value' => $this->t('Test saved connection'),
      '#submit' => ['::testConnection'],
      '#limit_validation_errors' => [],
    ];

    $form['display'] = [
      '#type' => 'details',
      '#title' => $this->t('Images'),
      '#open' => TRUE,
    ];

    $derivatives = [
      'square' => $this->t('Square'),
      'thumb' => $this->t('Thumbnail'),
      'xsmall' => $this->t('Extra small'),
      'small' => $this->t('Small'),
      'medium' => $this->t('Medium'),
      'large' => $this->t('Large'),
      'xlarge' => $this->t('Extra large'),
      'xxlarge' => $this->t('2× extra large'),
    ];

    $form['display']['default_derivative'] = [
      '#type' => 'select',
      '#title' => $this->t('Default display derivative'),
      '#options' => $derivatives,
      '#default_value' => (string) $config->get('default_derivative'),
    ];

    $form['display']['thumbnail_derivative'] = [
      '#type' => 'select',
      '#title' => $this->t('Media Library thumbnail derivative'),
      '#options' => $derivatives,
      '#default_value' => (string) $config->get('thumbnail_derivative'),
    ];

    $form['display']['cache_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('Metadata cache lifetime'),
      '#default_value' => (int) $config->get('cache_ttl'),
      '#min' => 0,
      '#max' => 86400,
      '#field_suffix' => $this->t('seconds'),
      '#description' => $this->t('Set to 0 to disable Piwigo metadata caching.'),
    ];

    $form['security_note'] = [
      '#type' => 'item',
      '#title' => $this->t('Private libraries'),
      '#markup' => $this->t('The API key authenticates Web API requests. Piwigo installations that additionally protect binary derivative URLs may require a later proxy/session transport mode; this first version already caches Media Library thumbnails server-side when the derivative URL is reachable.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $url = trim((string) Settings::get('piwigo_display.base_url', $form_state->getValue('base_url')));
    $parts = parse_url($url);
    if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], TRUE) || empty($parts['host'])) {
      $form_state->setErrorByName('base_url', $this->t('Enter a valid HTTP or HTTPS Piwigo URL.'));
      return;
    }

    $config = $this->config('piwigo_display.settings');
    $effective_api_key = trim((string) Settings::get(
      'piwigo_display.api_key',
      trim((string) $form_state->getValue('api_key')) !== '' ? $form_state->getValue('api_key') : ($config->get('api_key') ?? ''),
    ));
    $effective_legacy_username = trim((string) Settings::get(
      'piwigo_display.legacy_username',
      $form_state->getValue('legacy_username') ?? $config->get('legacy_username') ?? '',
    ));
    $effective_legacy_password = (string) Settings::get(
      'piwigo_display.legacy_password',
      (string) $form_state->getValue('legacy_password') !== '' ? $form_state->getValue('legacy_password') : ($config->get('legacy_password') ?? ''),
    );

    if (($effective_api_key !== '' || ($effective_legacy_username !== '' && $effective_legacy_password !== '')) && ($parts['scheme'] ?? '') !== 'https') {
      $form_state->setErrorByName('base_url', $this->t('HTTPS is required when an API key or service-account credentials are configured.'));
    }

    if (($effective_legacy_username === '') !== ($effective_legacy_password === '')) {
      $form_state->setErrorByName('legacy_username', $this->t('Configure both the legacy Piwigo username and password, or leave both empty.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('piwigo_display.settings');

    if (Settings::get('piwigo_display.base_url', NULL) === NULL) {
      $config->set('base_url', rtrim(trim((string) $form_state->getValue('base_url')), '/'));
    }

    if (Settings::get('piwigo_display.api_key', NULL) === NULL) {
      $api_key = trim((string) $form_state->getValue('api_key'));
      if ($api_key !== '') {
        $config->set('api_key', $api_key);
      }
    }

    if (Settings::get('piwigo_display.legacy_username', NULL) === NULL) {
      $config->set('legacy_username', trim((string) $form_state->getValue('legacy_username')));
    }
    if (Settings::get('piwigo_display.legacy_password', NULL) === NULL) {
      $legacy_password = (string) $form_state->getValue('legacy_password');
      if ($legacy_password !== '') {
        $config->set('legacy_password', $legacy_password);
      }
    }

    $config
      ->set('request_timeout', (int) $form_state->getValue('request_timeout'))
      ->set('cache_ttl', (int) $form_state->getValue('cache_ttl'))
      ->set('default_derivative', (string) $form_state->getValue('default_derivative'))
      ->set('thumbnail_derivative', (string) $form_state->getValue('thumbnail_derivative'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Tests the currently saved Piwigo configuration.
   */
  public function testConnection(array &$form, FormStateInterface $form_state): void {
    try {
      $result = $this->piwigoClient->testConnection();
      $this->messenger()->addStatus($this->t('Piwigo connection succeeded. Server version: @version.', [
        '@version' => $result['version'] ?? 'unknown',
      ]));
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Piwigo connection failed: @message', [
        '@message' => $e->getMessage(),
      ]));
    }

    $form_state->setRebuild();
  }

}
