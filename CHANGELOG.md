# Changelog

## 0.1.0-alpha1 — 2026-09-05

First public alpha for Drupal 10.3+ and Drupal 11.

### Media / editorial workflow

- native Drupal Media source storing canonical Piwigo image IDs;
- Media Library search, album browsing, previews and multi-selection;
- responsive visual photo grid with keyboard/focus, forced-colors and reduced-motion support;
- Media Library slot-limit and Form API value-tree guards;
- lazy thumbnail loading to avoid blocking AJAX rendering.

### Piwigo integration

- Piwigo Web API client with `pwg.categories.getList`, `pwg.categories.getImages`, `pwg.images.search`, `pwg.images.getInfo`, version/status checks and legacy session login;
- anonymous/public Piwigo support;
- Piwigo 16+ personal API-key support using `X-PIWIGO-API`;
- strict Piwigo origin validation using scheme + host + effective port;
- redirects disabled for API and authenticated binary fetches;
- validated WGS84 latitude/longitude metadata with explicit Leaflet `[lat, lng]` and GeoJSON `[lng, lat]` contracts.

### Security / private libraries

- credentials entered through Drupal administration stored in local State API instead of exportable configuration, with migration from older development configuration;
- authenticated Media Library previews streamed server-side without persisting private bytes under `public://`;
- signed Media Library thumbnail routes to reduce arbitrary Piwigo image-ID enumeration;
- authenticated frontend derivatives proxied through Drupal and protected by the Media entity `view` access check;
- derivative proxy restricted to known raster signatures (JPEG, PNG, GIF, WebP), with private-cache, `nosniff`, CSP and no-referrer headers;
- legacy public thumbnail cache cleanup update for installations upgraded from development builds.

### Cartography boundary

- Piwigo Display core remains independent from `piwigo-openstreetmap`;
- no direct OSM tile/Nominatim calls or public Leaflet CDN dependency in core;
- cartography stays optional and coordinates are treated as potentially sensitive data;
- public audit documents the Piwigo/Drupal/OpenStreetMap integration boundary while withholding upstream security details pending responsible disclosure.

### Quality

- PHP 8.1 and PHP 8.3 syntax/behavior matrix;
- real Composer-installed Drupal 10 and Drupal 11 class/API compatibility smoke tests;
- Composer, JavaScript and YAML validation;
- regression tests for origin security, Media Library form structure/slots, thumbnail privacy/capabilities/cache, secret storage, frontend derivative protection, geolocation and cartography boundaries.

### Alpha limitations

This release has not yet completed a full end-to-end validation on a bootstrapped Drupal site connected to a live Piwigo instance. In particular, final runtime validation is still required for the complete Media Library creation flow, theme rendering on real admin themes, and private/public Piwigo behavior under production hosting conditions.

Not implemented yet:

- non-destructive crop/focal-point workflow;
- pagination beyond the first Media Library result page;
- advanced tag/date filters;
- optional cartographic UI;
- mass-map GPS batch strategy;
- Drupal.org packaging/release automation.
