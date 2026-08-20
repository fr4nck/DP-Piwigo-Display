# Architecture

## Product boundary

Piwigo Display is a Drupal-native DAM bridge. It is deliberately separate from WP-Piwigo-Display and from Piwigo Display Studio.

- Piwigo remains the canonical photo library and permission source.
- Drupal stores a reusable Media entity whose source value is the Piwigo image ID.
- Drupal resolves metadata and derivative URLs through the Piwigo Web API.
- Originals are not copied into Drupal by default.

## Components

### `PiwigoClient`

Single HTTP client for:

- `pwg.getVersion`;
- `pwg.session.getStatus`;
- `pwg.categories.getList`;
- `pwg.categories.getImages`;
- `pwg.images.search`;
- `pwg.images.getInfo`.

Authenticated transports require HTTPS. Piwigo 16+ personal API keys are sent with `X-PIWIGO-API`. If no API key is configured, an optional classic `pwg.session.login` service account can provide a request-local cookie session.

### `PiwigoImage` media source

Drupal Media source plugin. The source field is a required string field containing the Piwigo image ID.

Metadata exposed to Drupal:

- default name;
- thumbnail URI;
- width/height;
- author;
- description;
- display derivative URL;
- Piwigo ID.

### `PiwigoLibraryForm`

Custom `media_library_add` form built on Drupal core's `AddFormBase`:

1. search globally or select an album;
2. display results;
3. select one or more Piwigo IDs;
4. pass the IDs to Drupal core's `processInputValues()`;
5. let the standard Media Library save/select workflow continue.

### `ThumbnailManager`

Media Library thumbnails need a Drupal-readable URI because core renders them through the `media_library` image style. The manager therefore downloads the chosen Piwigo thumbnail server-side and stores it under:

`public://piwigo_display/thumbnails/{piwigo-id}.{ext}`

This is a cache, not the canonical asset.

### `PiwigoImageFormatter`

Field formatter that resolves the Piwigo ID and renders a selected Piwigo derivative URL directly.

## Private library transport

There are two distinct authorization layers:

1. Piwigo Web API authorization — handled with Piwigo 16+ API keys.
2. Binary derivative URL authorization — deployment-dependent.

Default Piwigo derivatives are often directly reachable, but Piwigo can be configured to protect original/derivative URLs. The first release sends `X-PIWIGO-API` during server-side thumbnail retrieval as a best effort. For binary downloads, an optional service-account session cookie is also attached when configured. Server-side asset retrieval is restricted to the configured Piwigo host and to HTTP(S), with HTTPS mandatory when credentials are used. A dedicated proxy transport is still planned before claiming universal support for every protected-binary deployment.

## Crop model

Crop must be non-destructive:

- source image stays in Piwigo;
- crop/focal point is Drupal editorial metadata;
- Drupal generates/caches a derived rendition only;
- no crop action overwrites the Piwigo original.

## Next architectural decisions

- binary proxy vs authenticated Piwigo session for strictly protected derivatives;
- duplicate Media lookup by Piwigo ID;
- per-connection configuration entity for multiple Piwigo instances;
- cache invalidation strategy;
- integration with Drupal Crop/Focal Point and Responsive Image.
