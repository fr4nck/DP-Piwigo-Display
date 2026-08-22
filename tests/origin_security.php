<?php

declare(strict_types=1);

use Drupal\piwigo_display\Service\PiwigoClient;

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
  fwrite(STDERR, "vendor/autoload.php is missing. Run Composer first.\n");
  exit(1);
}
require $autoload;

$client = (new ReflectionClass(PiwigoClient::class))->newInstanceWithoutConstructor();
$sanitize = new ReflectionMethod(PiwigoClient::class, 'sanitizeBaseUrl');
$origin = new ReflectionMethod(PiwigoClient::class, 'getOrigin');

$failures = [];

$sanitizationCases = [
  ['https://Photos.Example.org/piwigo/', 'https://photos.example.org/piwigo'],
  ['https://photos.example.org:443/piwigo/', 'https://photos.example.org:443/piwigo'],
  ['http://photos.example.org', 'http://photos.example.org'],
  ['https://user:pass@photos.example.org/piwigo', ''],
  ['https://photos.example.org/piwigo?redirect=1', ''],
  ['https://photos.example.org/piwigo#fragment', ''],
  ["https://photos.example.org/piwigo\n", ''],
];

foreach ($sanitizationCases as [$input, $expected]) {
  $actual = $sanitize->invoke($client, $input);
  if ($actual !== $expected) {
    $failures[] = "sanitizeBaseUrl({$input}) returned " . var_export($actual, TRUE) . ', expected ' . var_export($expected, TRUE) . '.';
  }
}

$originPairs = [
  ['https://photos.example.org/piwigo', 'https://photos.example.org/i.php?/upload/photo.jpg', TRUE],
  ['https://photos.example.org/piwigo', 'https://photos.example.org:443/i.php?/upload/photo.jpg', TRUE],
  ['https://photos.example.org:8443/piwigo', 'https://photos.example.org:8443/i.php?/upload/photo.jpg', TRUE],
  ['https://photos.example.org/piwigo', 'https://photos.example.org:8443/i.php?/upload/photo.jpg', FALSE],
  ['https://photos.example.org/piwigo', 'http://photos.example.org/i.php?/upload/photo.jpg', FALSE],
  ['https://photos.example.org/piwigo', 'https://cdn.example.org/i.php?/upload/photo.jpg', FALSE],
];

foreach ($originPairs as [$base, $asset, $expectedSame]) {
  $baseOrigin = $origin->invoke($client, $base);
  $assetOrigin = $origin->invoke($client, $asset);
  $same = $baseOrigin !== NULL && $baseOrigin === $assetOrigin;
  if ($same !== $expectedSame) {
    $failures[] = "Origin comparison failed for {$base} and {$asset}.";
  }
}

if ($failures) {
  fwrite(STDERR, "Piwigo origin security tests failed:\n- " . implode("\n- ", $failures) . "\n");
  exit(1);
}

fwrite(STDOUT, "Piwigo origin security tests passed.\n");
