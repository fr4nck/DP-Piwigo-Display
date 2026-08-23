<?php

declare(strict_types=1);

$settings = file_get_contents(__DIR__ . '/../config/install/piwigo_display.settings.yml');
$schema = file_get_contents(__DIR__ . '/../config/schema/piwigo_display.schema.yml');
$services = file_get_contents(__DIR__ . '/../piwigo_display.services.yml');
$client = file_get_contents(__DIR__ . '/../src/Service/PiwigoClient.php');
$form = file_get_contents(__DIR__ . '/../src/Form/SettingsForm.php');
$install = file_get_contents(__DIR__ . '/../piwigo_display.install');

foreach (compact('settings', 'schema', 'services', 'client', 'form', 'install') as $name => $contents) {
  if ($contents === false) {
    fwrite(STDERR, "Unable to read secret-storage regression input: {$name}\n");
    exit(1);
  }
}

foreach ([$settings, $schema] as $exportableConfig) {
  if (preg_match('/^\s*(api_key|legacy_password):/m', $exportableConfig)) {
    fwrite(STDERR, "Piwigo secrets must not be declared in exportable Drupal configuration.\n");
    exit(1);
  }
}

$required = [
  [$services, "- '@state'"],
  [$client, 'use Drupal\\Core\\State\\StateInterface;'],
  [$client, "\$this->state->get('piwigo_display.api_key'"],
  [$client, "\$this->state->get('piwigo_display.legacy_password'"],
  [$form, "\$this->state->set(self::API_KEY_STATE"],
  [$form, "\$this->state->delete(self::API_KEY_STATE)"],
  [$form, "\$this->state->set(self::LEGACY_PASSWORD_STATE"],
  [$form, "\$this->state->delete(self::LEGACY_PASSWORD_STATE)"],
  [$form, "\$legacy_api_key = trim((string) (\$config->get('api_key') ?? ''))"],
  [$form, "\$legacy_config_password = (string) (\$config->get('legacy_password') ?? '')"],
  [$form, "->clear('api_key')"],
  [$form, "->clear('legacy_password')"],
  [$install, 'function piwigo_display_update_10001()'],
  [$install, "\$state->set('piwigo_display.api_key'"],
  [$install, "\$state->set('piwigo_display.legacy_password'"],
  [$install, "->clear('api_key')"],
  [$install, "->clear('legacy_password')"],
  [$install, 'function piwigo_display_uninstall(): void'],
  [$install, "\$state->delete('piwigo_display.api_key')"],
  [$install, "\$state->delete('piwigo_display.legacy_password')"],
  [$install, "\$file_system->deleteRecursive(\$directory)"],
];

foreach ($required as [$haystack, $needle]) {
  if (!str_contains($haystack, $needle)) {
    fwrite(STDERR, "Secret-storage regression guard missing: {$needle}\n");
    exit(1);
  }
}

echo "Piwigo secret-storage regression test passed.\n";
