<?php

declare(strict_types=1);

namespace Drupal\piwigo_display\Value;

/**
 * Validated WGS84 latitude/longitude coordinates from Piwigo metadata.
 *
 * Piwigo and Leaflet use latitude, longitude ordering. GeoJSON uses the
 * opposite axis order: longitude, latitude. Keeping both conversions here
 * prevents silent axis inversions when cartography support is added later.
 */
final class GeoCoordinates {

  private function __construct(
    public readonly float $latitude,
    public readonly float $longitude,
  ) {}

  /**
   * Builds coordinates from Piwigo metadata, or NULL when invalid/incomplete.
   */
  public static function fromPiwigo(mixed $latitude, mixed $longitude): ?self {
    if (!is_numeric($latitude) || !is_numeric($longitude)) {
      return NULL;
    }

    $lat = (float) $latitude;
    $lon = (float) $longitude;

    if (!is_finite($lat) || !is_finite($lon)) {
      return NULL;
    }
    if ($lat < -90.0 || $lat > 90.0) {
      return NULL;
    }
    if ($lon < -180.0 || $lon > 180.0) {
      return NULL;
    }

    return new self($lat, $lon);
  }

  /**
   * Leaflet L.latLng() axis order: [latitude, longitude].
   *
   * @return array{0: float, 1: float}
   */
  public function toLeaflet(): array {
    return [$this->latitude, $this->longitude];
  }

  /**
   * RFC 7946 GeoJSON position axis order: [longitude, latitude].
   *
   * @return array{0: float, 1: float}
   */
  public function toGeoJson(): array {
    return [$this->longitude, $this->latitude];
  }

}
