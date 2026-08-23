<?php

declare(strict_types=1);

namespace Drupal\piwigo_display\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\piwigo_display\Service\PiwigoClient;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Administrative settings for the Piwigo connection.
 */
final class SettingsForm extends ConfigFormBase {

  private const API_KEY_STATE = 'piwigo_display.api_key';

  private const LEGACY_PASSWORD_STATE = 'piwigo_display.legacy_password';

  public function __construct(
    ConfigFactoryInterface $config_factory,
    private readonly StateInterface $state,
    private readonly PiwigoClient $piwigoClient,
  ) {
    parent::__construct($config_factory);
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('state'),
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
    $stored_api_key = $this->getStoredSecret(self::API_KEY_STATE, 'api_key');

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
        : $this->t('Example: https://photos.example.org/piwigo. Use only the Piwigo base URL, without credentials, query string or fragment.'),
    ];

    $form['connection']['api_key'] = [
      '#type' => 'password',
      '#title' => $this->t('Piwigo API key'),
      '#default_value' => '',
      '#disabled' => $api_key_managed,
      '#attributes' => ['autocomplete' => 'new-password'],
      '#description' => $api_key_managed
        ? $this->t('Managed in settings.php with $settings[\'piwigo_display.api_key\']. The secret is never displayed here.')
        : $this->t('Piwigo 16+: personal API key. Leave blank to keep the currently stored key. Secrets entered here are stored in local Drupal state and are not included in configuration exports.'),
    ];
    if ($stored_api_key !== '') {
      $form['connection']['clear_api_key'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Remove the locally stored API key'),
        '#description' => $api_key_managed
          ? $this->t('The settings.php API key remains active; this only removes the local fallback secret.')
          : $this->t('Removes the saved API key when this form is submitted.'),
      ];
    }

    $legacy_username_managed = Settings::get('piwigo_display.legacy_username', NULL) !== NULL;
    $legacy_password_managed = Settings::get('piwigo_display.legacy_password', NULL) !== NULL;
    $stored_legacy_password = $this->getStoredSecret(self::LEGACY_PASSWORD_STATE, 'legacy_password');

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
        : $this->t('Leave blank to keep the stored password. Secrets entered here are stored in local Drupal state and are not included in configuration exports.'),
    ];
    if ($stored_legacy_password !== '') {
      $form['connection']['legacy']['clear_legacy_password'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Remove the locally stored Piwigo password'),
        '#description' => $legacy_password_managed
          ? $this->t('The settings.php password remains active; this only removes the local fallback secret.')
          : $this->t('Removes the saved service-account password when this form is submitted.'),
      ];
    }

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
      '#markup' => $this->t('API keys authenticate Web API requests. When binary derivative URLs also require authentication, the optional service-account mode can provide a Piwigo session cookie for server-side thumbnail retrieval. Credentials are never forwarded outside the configured Piwigo origin. Secrets saved through this form use Drupal local state, which keeps them out of configuration exports but does not encrypt them in the database; settings.php remains recommended for deployment-managed secrets.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $url = trim((string) Settings::get('piwigo_display.base_url', $form_state->getValue('base_url')));
    $parts = parse_url($url);
    $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
    $has_unsafe_parts = is_array($parts) && (
      isset($parts['user'])
      || isset($parts['pass'])
      || isset($parts['query'])
      || isset($parts['fragment'])
    );
    $has_control_characters = preg_match('/[\x00-\x20\x7f]/', $url) === 1;

    if (
      !is_array($parts)
      || !in_array($scheme, ['http', 'https'], TRUE)
      || empty($parts['host'])
      || $has_unsafe_parts
      || $has_control_characters
    ) {
      $form_state->setErrorByName('base_url', $this->t('Enter a valid HTTP or HTTPS Piwigo base URL without credentials, query string or fragment.'));
      return;
    }

    $new_api_key = trim((string) $form_state->getValue('api_key'));
    $clear_api_key = (bool) $form_state->getValue('clear_api_key');
    if ($new_api_key !== '' && $clear_api_key) {
      $form_state->setErrorByName('api_key', $this->t('Enter a new API key or remove the stored key, not both.'));
    }

    $new_legacy_password = (string) $form_state->getValue('legacy_password');
    $clear_legacy_password = (bool) $form_state->getValue('clear_legacy_password');
    if ($new_legacy_password !== '' && $clear_legacy_password) {
      $form_state->setErrorByName('legacy_password', $this->t('Enter a new password or remove the stored password, not both.'));
    }

    $settings_api_key = Settings::get('piwigo_display.api_key', NULL);
    $effective_api_key = $settings_api_key !== NULL
      ? trim((string) $settings_api_key)
      : ($clear_api_key ? '' : ($new_api_key !== '' ? $new_api_key : $this->getStoredSecret(self::API_KEY_STATE, 'api_key')));

    $effective_legacy_username = trim((string) Settings::get(
      'piwigo_display.legacy_username',
      $form_state->getValue('legacy_username') ?? $this->config('piwigo_display.settings')->get('legacy_username') ?? '',
    ));

    $settings_legacy_password = Settings::get('piwigo_display.legacy_password', NULL);
    $effective_legacy_password = $settings_legacy_password !== NULL
      ? (string) $settings_legacy_password
      : ($clear_legacy_password ? '' : ($new_legacy_password !== '' ? $new_legacy_password : $this->getStoredSecret(self::LEGACY_PASSWORD_STATE, 'legacy_password')));

    if (($effective_api_key !== '' || ($effective_legacy_username !== '' && $effective_legacy_password !== '')) && $scheme !== 'https') {
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

    if ((bool) $form_state->getValue('clear_api_key')) {
      $this->state->delete(self::API_KEY_STATE);
    }
    elseif (Settings::get('piwigo_display.api_key', NULL) === NULL) {
      $api_key = trim((string) $form_state->getValue('api_key'));
      if ($api_key !== '') {
        $this->state->set(self::API_KEY_STATE, $api_key);
      }
      elseif ($this->state->get(self::API_KEY_STATE, NULL) === NULL) {
        $legacy_api_key = trim((string) ($config->get('api_key') ?? ''));
        if ($legacy_api_key !== '') {
          $this->state->set(self::API_KEY_STATE, $legacy_api_key);
        }
      }
    }

    if (Settings::get('piwigo_display.legacy_username', NULL) === NULL) {
      $config->set('legacy_username', trim((string) $form_state->getValue('legacy_username')));
    }

    if ((bool) $form_state->getValue('clear_legacy_password')) {
      $this->state->delete(self::LEGACY_PASSWORD_STATE);
    }
    elseif (Settings::get('piwigo_display.legacy_password', NULL) === NULL) {
      $legacy_password = (string) $form_state->getValue('legacy_password');
      if ($legacy_password !== '') {
        $this->state->set(self::LEGACY_PASSWORD_STATE, $legacy_password);
      }
      elseif ($this->state->get(self::LEGACY_PASSWORD_STATE, NULL) === NULL) {
        $legacy_config_password = (string) ($config->get('legacy_password') ?? '');
        if ($legacy_config_password !== '') {
          $this->state->set(self::LEGACY_PASSWORD_STATE, $legacy_config_password);
        }
      }
    }

    // Remove legacy secret keys from exportable configuration even if an
    // administrator saves the form before running the database update hook.
    $config
      ->clear('api_key')
      ->clear('legacy_password')
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

  /**
   * Reads a locally stored secret, with a one-release fallback for old config.
   */
  private function getStoredSecret(string $state_key, string $legacy_config_key): string {
    $stored = $this->state->get($state_key, NULL);
    if ($stored !== NULL) {
      return (string) $stored;
    }

    return (string) ($this->config('piwigo_display.settings')->get($legacy_config_key) ?? '');
  }

}
