<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Composer\InstalledVersions;
use Drupal\media\MediaSourceBase;
use Drupal\media_library\Form\AddFormBase;
use Drupal\piwigo_display\Form\PiwigoLibraryForm;
use Drupal\piwigo_display\Form\SettingsForm;
use Drupal\piwigo_display\Plugin\Field\FieldFormatter\PiwigoImageFormatter;
use Drupal\piwigo_display\Plugin\media\Source\PiwigoImage;
use Drupal\piwigo_display\Service\PiwigoClient;
use Drupal\piwigo_display\Service\ThumbnailManager;

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
  fwrite(STDERR, "vendor/autoload.php is missing. Run Composer first.\n");
  exit(1);
}

/** @var ClassLoader $loader */
$loader = require $autoload;
$core = $root . '/vendor/drupal/core';

// A full Drupal kernel discovers extension namespaces dynamically. This smoke
// test intentionally runs without a site, so register the core extension
// namespaces required by Piwigo Display exactly where Drupal ships them.
foreach ([
  'Drupal\\media\\' => $core . '/modules/media/src',
  'Drupal\\media_library\\' => $core . '/modules/media_library/src',
  'Drupal\\image\\' => $core . '/modules/image/src',
] as $namespace => $directory) {
  if (!is_dir($directory)) {
    fwrite(STDERR, "Required Drupal extension directory is missing: {$directory}\n");
    exit(1);
  }
  $loader->addPsr4($namespace, $directory);
}

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
  try {
    if (!class_exists($class)) {
      $failures[] = "Unable to autoload {$class}.";
      continue;
    }

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
  try {
    if (!is_subclass_of($class, $parent)) {
      $failures[] = "{$class} is no longer compatible with {$parent}.";
    }
  }
  catch (Throwable $e) {
    $failures[] = "Unable to verify {$class} inheritance: {$e->getMessage()}";
  }
}

$requiredCoreMethods = [
  [AddFormBase::class, 'processInputValues'],
  [AddFormBase::class, 'updateFormCallback'],
  [MediaSourceBase::class, 'getSourceFieldDefinition'],
];
foreach ($requiredCoreMethods as [$class, $method]) {
  try {
    if (!method_exists($class, $method)) {
      $failures[] = "Required Drupal API {$class}::{$method}() is missing.";
    }
  }
  catch (Throwable $e) {
    $failures[] = "Unable to inspect {$class}::{$method}(): {$e->getMessage()}";
  }
}

$coreVersion = InstalledVersions::getPrettyVersion('drupal/core') ?? 'unknown';
if ($failures) {
  fwrite(STDERR, "Drupal compatibility smoke test failed on core {$coreVersion}:\n- " . implode("\n- ", $failures) . "\n");
  exit(1);
}

fwrite(STDOUT, "Drupal compatibility smoke test passed on core {$coreVersion}.\n");
