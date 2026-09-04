<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Value/GeoCoordinates.php';

use Drupal\piwigo_display\Value\GeoCoordinates;

function expect(bool $condition, string $message): void {
  if (!$condition) {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
  }
}

$rennes = GeoCoordinates::fromPiwigo('48.1173', '-1.6778');
expect($rennes instanceof GeoCoordinates, 'Expected ordinary Piwigo coordinates to be accepted.');
expect($rennes->toLeaflet() === [48.1173, -1.6778], 'Leaflet axis order must stay latitude, longitude.');
expect($rennes->toGeoJson() === [-1.6778, 48.1173], 'GeoJSON axis order must be longitude, latitude.');

$zero = GeoCoordinates::fromPiwigo('0', 0);
expect($zero instanceof GeoCoordinates, 'Zero latitude/longitude are valid coordinates and must not be treated as empty.');
expect($zero->latitude === 0.0 && $zero->longitude === 0.0, 'Zero coordinates must be preserved exactly.');

$bounds = GeoCoordinates::fromPiwigo(90, -180);
expect($bounds instanceof GeoCoordinates, 'Exact WGS84 bounds must be accepted.');

$invalid = [
  GeoCoordinates::fromPiwigo(90.0001, 0),
  GeoCoordinates::fromPiwigo(-90.0001, 0),
  GeoCoordinates::fromPiwigo(0, 180.0001),
  GeoCoordinates::fromPiwigo(0, -180.0001),
  GeoCoordinates::fromPiwigo('', 1),
  GeoCoordinates::fromPiwigo(1, NULL),
  GeoCoordinates::fromPiwigo('NaN', 1),
  GeoCoordinates::fromPiwigo(INF, 1),
];

foreach ($invalid as $index => $coordinates) {
  expect($coordinates === NULL, 'Invalid geolocation fixture #' . $index . ' must be rejected.');
}

$source = file_get_contents(__DIR__ . '/../src/Plugin/media/Source/PiwigoImage.php');
expect(is_string($source), 'Unable to read Piwigo media source.');
expect(str_contains($source, "'latitude' => \$this->t('Latitude')"), 'Media source must expose latitude metadata.');
expect(str_contains($source, "'longitude' => \$this->t('Longitude')"), 'Media source must expose longitude metadata.');
expect(str_contains($source, 'GeoCoordinates::fromPiwigo('), 'Media source must validate Piwigo coordinates through GeoCoordinates.');

fwrite(STDOUT, "Geolocation contract OK\n");
