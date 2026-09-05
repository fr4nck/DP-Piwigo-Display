<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$routing = file_get_contents($root . '/piwigo_display.routing.yml');
$form = file_get_contents($root . '/src/Form/PiwigoLibraryForm.php');

function expect_thumbnail_capability(bool $condition, string $message): void {
  if (!$condition) {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
  }
}

expect_thumbnail_capability(is_string($routing), 'Unable to read routing definition.');
expect_thumbnail_capability(is_string($form), 'Unable to read Piwigo Media Library form.');

$thumbnailRouteStart = strpos($routing, 'piwigo_display.thumbnail:');
$derivativeRouteStart = strpos($routing, 'piwigo_display.derivative:');
expect_thumbnail_capability($thumbnailRouteStart !== FALSE, 'Thumbnail route is missing.');
expect_thumbnail_capability($derivativeRouteStart !== FALSE, 'Derivative route is missing.');
expect_thumbnail_capability($derivativeRouteStart > $thumbnailRouteStart, 'Unexpected routing order.');

$thumbnailRoute = substr($routing, $thumbnailRouteStart, $derivativeRouteStart - $thumbnailRouteStart);
expect_thumbnail_capability(str_contains($thumbnailRoute, "_permission: 'create media'"), 'Thumbnail route must remain restricted to Media creators.');
expect_thumbnail_capability(str_contains($thumbnailRoute, "_csrf_token: 'TRUE'"), 'Thumbnail route must require Drupal signed URL tokens.');
expect_thumbnail_capability(str_contains($thumbnailRoute, "image_id: '\\d+'"), 'Thumbnail image IDs must remain numeric.');

expect_thumbnail_capability(str_contains($form, "Url::fromRoute('piwigo_display.thumbnail'"), 'Media Library must generate thumbnail URLs through Drupal routing so CSRF tokens are attached automatically.');
expect_thumbnail_capability(!str_contains($form, '/piwigo-display/thumbnail/'), 'Media Library must not hand-build thumbnail paths and bypass route token generation.');

fwrite(STDOUT, "Thumbnail capability contract OK\n");
