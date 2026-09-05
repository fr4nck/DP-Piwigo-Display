<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$targets = [
  $root . '/src',
  $root . '/js',
];

$rootFiles = [
  $root . '/piwigo_display.libraries.yml',
  $root . '/piwigo_display.routing.yml',
  $root . '/piwigo_display.services.yml',
];

$forbidden = [
  'piwigo-openstreetmap' => 'Piwigo Display core must not depend on the Piwigo OpenStreetMap plugin.',
  'tile.openstreetmap.org' => 'Piwigo Display core must not hard-code the public OpenStreetMap tile service.',
  'nominatim.openstreetmap.org' => 'Piwigo Display core must not call the public Nominatim service directly.',
  'unpkg.com/leaflet' => 'Leaflet must not be loaded from a public CDN by Piwigo Display core.',
  'cdn.jsdelivr.net/npm/leaflet' => 'Leaflet must not be loaded from a public CDN by Piwigo Display core.',
];

$files = [];
foreach ($targets as $target) {
  if (!is_dir($target)) {
    continue;
  }
  $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target));
  foreach ($iterator as $file) {
    if (!$file->isFile()) {
      continue;
    }
    $extension = strtolower($file->getExtension());
    if (!in_array($extension, ['php', 'js', 'yml', 'yaml'], true)) {
      continue;
    }
    $files[] = $file->getPathname();
  }
}
foreach ($rootFiles as $file) {
  if (is_file($file)) {
    $files[] = $file;
  }
}

$violations = [];
foreach (array_unique($files) as $file) {
  $content = file_get_contents($file);
  if (!is_string($content)) {
    $violations[] = sprintf('Unable to read %s.', $file);
    continue;
  }
  foreach ($forbidden as $needle => $message) {
    if (stripos($content, $needle) !== false) {
      $violations[] = sprintf('%s Found "%s" in %s.', $message, $needle, str_replace($root . '/', '', $file));
    }
  }
}

if ($violations !== []) {
  fwrite(STDERR, implode(PHP_EOL, $violations) . PHP_EOL);
  exit(1);
}

echo "Cartography boundary regression test passed.\n";
