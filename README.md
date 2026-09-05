# Piwigo Display for Drupal

Development module that connects Drupal Media Library to a Piwigo photo library.

The first goal is DAM-style editorial use: editors can browse/search Piwigo, select existing images and create Drupal Media entities that reference the Piwigo image ID. The original file does not need to be downloaded to and uploaded again from the editor's workstation.

## Status

`0.1.0-dev` — initial functional scaffold for Drupal 10.3 and Drupal 11.

Implemented:

- Piwigo Web API client;
- anonymous access to public Piwigo libraries;
- Piwigo 16+ personal API key authentication (`X-PIWIGO-API`, required since Piwigo 16.1);
- optional legacy Piwigo username/password session for pre-16 private libraries and protected binary URLs;
- album discovery;
- global image search with `pwg.images.search`;
- album browsing with `pwg.categories.getImages`;
- image metadata with `pwg.images.getInfo`;
- Drupal Media source plugin storing the Piwigo image ID in a string source field;
- Media Library add form with search, album selector, previews and multi-selection;
- server-side thumbnail cache under `public://piwigo_display/thumbnails` for anonymous/public Piwigo libraries only;
- authenticated Media Library previews streamed in memory through the protected Drupal route, without persisting their bytes in `public://`;
- field formatter rendering a chosen Piwigo derivative;
- access-aware Drupal proxy for frontend derivatives from authenticated Piwigo libraries, protected by the Media entity `view` access check;
- connection/settings administration;
- non-exportable local secret storage for credentials entered through the administration form;
- validated WGS84 latitude/longitude metadata with explicit Leaflet and GeoJSON axis-order contracts.

Not implemented yet:

- non-destructive crop/focal-point workflow;
- optional local cache/import of display derivatives;
- pagination beyond the first result page in the Media Library UI;
- advanced tag/date filters;
- optional cartographic integration;
- automated Drupal.org packaging/tests.

## Installation

1. Copy the `piwigo_display` directory to `web/modules/custom/` (or `modules/custom/`).
2. Enable **Piwigo Display**, **Media**, **Media Library** and **Image**.
3. Open `Administration > Configuration > Media > Piwigo Display`.
4. Enter the Piwigo base URL, for example `https://photos.example.org`.
5. For a private Piwigo 16+ library, create a dedicated personal API key in Piwigo and configure it here.
6. Save, then use **Test saved connection**.
7. In `Structure > Media types`, create a media type and select **Piwigo image** as its source. Drupal will create the string source field automatically.
8. Add this media type to the Media Library and use the library to search/browse Piwigo.

## Secrets

Credentials entered through the Piwigo Display administration form are stored in Drupal local state. They are therefore not included in Drupal configuration exports. Local state is not encrypted, however: database backups can still contain these values and must be protected.

For deployment-managed production credentials, `settings.php` remains the recommended option:

```php
$settings['piwigo_display.base_url'] = 'https://photos.example.org';
$settings['piwigo_display.api_key'] = 'pkid-…:…';

// Optional compatibility/session account:
$settings['piwigo_display.legacy_username'] = 'drupal-service';
$settings['piwigo_display.legacy_password'] = '…';
```

Values from `settings.php` override locally stored credentials and exported configuration. Never commit the API key or password. Authenticated connections require HTTPS.

Existing development installations that stored `api_key` or `legacy_password` in configuration are migrated automatically to local state by the module update hook and the obsolete config values are removed.

## Piwigo authentication note

Piwigo 16 introduced personal API keys. Starting with Piwigo 16.1, keys are sent in the `X-PIWIGO-API` header.

The key authenticates Web API calls. Some installations also protect binary derivative URLs. Media Library previews therefore use two different paths: anonymous/public libraries may use the local public thumbnail cache, while authenticated libraries are fetched server-side and streamed in memory through the permission-protected Drupal thumbnail route. Authenticated preview bytes are not stored in `public://`; update `10002` also purges thumbnails generated there by earlier development builds.

Frontend rendering follows the same trust boundary. Public Piwigo libraries keep direct derivative URLs. For an authenticated Piwigo connection, the formatter emits a Drupal derivative route tied to the Media entity. Drupal checks `media.view` before the controller resolves the Piwigo image ID, fetches the derivative server-side and streams known raster formats with private-cache, `nosniff`, CSP and no-referrer headers. Piwigo credentials and session cookies never reach the browser.

## Rendering model

The Drupal Media entity stores only the canonical Piwigo image ID. Metadata and derivative URLs are resolved from Piwigo. This keeps Piwigo as the source of truth and avoids duplicating originals by default.

The formatter can render `square`, `thumb`, `xsmall`, `small`, `medium`, `large`, `xlarge` or `xxlarge` derivatives when available. Authenticated frontend derivatives are proxied without persistent binary storage. The proxy deliberately accepts only known raster signatures (JPEG, PNG, GIF, WebP); unknown formats such as SVG are not reflected to the browser.

## Geolocation and cartography

Piwigo Display can expose validated `latitude` and `longitude` metadata returned by `pwg.images.getInfo`. Latitude must be between -90 and 90, longitude between -180 and 180, and zero is valid on both axes.

The module core does not depend on the Piwigo OpenStreetMap plugin, does not call the public OSM tile or Nominatim services directly, and does not load Leaflet from a public CDN. Future cartography remains an optional Drupal integration. See `docs/GEOLOCATION.md` and `docs/CARTOGRAPHY-SECURITY.md`.

## Crop roadmap

Cropping should remain non-destructive. The intended design is:

1. keep the Piwigo original canonical and untouched;
2. store crop/focal-point instructions in Drupal;
3. generate/cache only the derivative needed by Drupal, or proxy a transformed derivative;
4. never overwrite the Piwigo source image from an editorial crop action.

This avoids turning Drupal into a second unmanaged DAM.

## Relationship to WP-Piwigo-Display

This is a separate Drupal integration, not a port of the WordPress rendering UI. It reuses the proven Piwigo API concepts but follows Drupal's native Media/Media Library architecture.

## License

Copyright © 2026 fr4nck.

GPL-2.0-or-later, in accordance with Drupal's licensing requirements for distributed modules.
