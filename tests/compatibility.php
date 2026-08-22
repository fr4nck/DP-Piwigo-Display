<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Drupal\media\MediaSourceBase;
use Drupal\media_library\Form\AddFormBase;
use Drupal\piwigo_display\Form\PiwigoLibraryForm;
use Drupal\piwigo_display\Form\SettingsForm;
use Drupal\piwigo_display\Plugin\Field\FieldFormatter\PiwigoImageFormatter;
use Drupal\piwigo_display\Plugin\media\Source\PiwigoImage;
use Drupal\piwigo_display\Service\PiwigoClient;
use Drupal\piwigo_display\Service\ThumbnailManager;

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
  fwrite(STDERR, "vendor/autoload.php is missing. Run Composer first.\n");
  exit(1);
}
require $autoload;

$failures = [];

$classes = [
  PiwigoClient::class,
  ThumbnailManager::class,
  SettingsForm::class,
  PiwigoLibraryForm::class,
  PiwigoImage::class,
  PiwigoImageFormatter::class,
];

foreach ($classes as $class) {
  if (!class_exists($class)) {
    $failures[] = "Unable to autoload {$class}.";
    continue;
  }

  try {
    $reflection = new ReflectionClass($class);
    foreach ($reflection->getAttributes() as $attribute) {
      // Instantiating plugin attributes catches renamed/removed named arguments
      // between supported Drupal branches.
      $attribute->newInstance();
    }
  }
  catch (Throwable $e) {
    $failures[] = "{$class}: {$e->getMessage()}";
  }
}

$inheritance = [
  PiwigoLibraryForm::class => AddFormBase::class,
  PiwigoImage::class => MediaSourceBase::class,
];
foreach ($inheritance as $class => $parent) {
  if (!is_subclass_of($class, $parent)) {
    $failures[] = "{$class} is no longer compatible with {$parent}.";
  }
}

$requiredCoreMethods = [
  [AddFormBase::class, 'processInputValues'],
  [AddFormBase::class, 'updateFormCallback'],
  [MediaSourceBase::class, 'getSourceFieldDefinition'],
];
foreach ($requiredCoreMethods as [$class, $method]) {
  if (!method_exists($class, $method)) {
    $failures[] = "Required Drupal API {$class}::{$method}() is missing.";
  }
}

$coreVersion = InstalledVersions::getPrettyVersion('drupal/core') ?? 'unknown';
if ($failures) {
  fwrite(STDERR, "Drupal compatibility smoke test failed on core {$coreVersion}:\n- " . implode("\n- ", $failures) . "\n");
  exit(1);
}

fwrite(STDOUT, "Drupal compatibility smoke test passed on core {$coreVersion}.\n");
