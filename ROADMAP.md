# Roadmap — Piwigo Display for Drupal

## 0.1 — DAM bridge foundation

- [x] Piwigo API client
- [x] Piwigo 16 API key support
- [x] Drupal Media source
- [x] Media Library search
- [x] Album browsing
- [x] Multiple selection
- [x] Server-side thumbnail cache
- [x] Piwigo derivative formatter
- [ ] Real Drupal 10.3 integration test
- [ ] Real Drupal 11 integration test
- [ ] Test against a Piwigo 16 private library
- [ ] Test against URL-protected Piwigo derivatives

## 0.2 — editorial workflow

- [ ] Pagination / infinite browsing
- [ ] Tag filters
- [ ] Date / author filters
- [ ] Better album tree navigation
- [ ] Metadata mappings (author, copyright, description, tags)
- [ ] Duplicate detection: reuse an existing Drupal Media entity for an already referenced Piwigo ID
- [ ] Optional local display-derivative cache
- [ ] Authenticated proxy/session transport for protected binary assets

## 0.3 — non-destructive crop

- [ ] Drupal crop/focal-point metadata
- [ ] Local/proxied derived crop generation
- [ ] Responsive image integration
- [ ] Keep the Piwigo original immutable from Drupal crop actions

## 0.4 — DAM integration hardening

- [ ] Multiple Piwigo connections
- [ ] Per-role/per-media-type connection restrictions
- [ ] Cache invalidation / refresh controls
- [ ] Audit/diagnostics page
- [ ] PHPUnit/kernel/functional tests
- [ ] PHPStan/Drupal coding standards CI

## 1.0 candidate

- [ ] Drupal.org project readiness
- [ ] Security review of credential handling and proxy mode
- [ ] Accessibility review of Media Library browser
- [ ] Stable configuration schema and update hooks
- [ ] Installation/update documentation
