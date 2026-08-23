# Security

## Supported versions

Piwigo Display is currently in development. Security fixes are applied to the latest development branch until a stable release policy is published.

## Reporting a vulnerability

Please do not publish credentials, API keys, private Piwigo URLs or proof-of-concept material containing sensitive data in a public issue.

Use GitHub's private vulnerability reporting feature when it is enabled for this repository. If it is not available, open a minimal public issue without sensitive details so a private contact channel can be established.

## Credential handling

- Prefer Piwigo personal API keys over username/password authentication.
- Production secrets can be supplied through Drupal `settings.php`; this remains the recommended option for deployment-managed credentials.
- Secrets entered through the Piwigo Display administration form are stored in Drupal local state, not in exportable configuration.
- Drupal state is not encryption: database backups can still contain locally stored secrets and must be protected accordingly.
- Existing installations are migrated automatically so legacy `api_key` and `legacy_password` config values are removed from exported configuration.
- The administration form never redisplays a stored secret and provides explicit controls to remove locally stored credentials.
- Authenticated connections require HTTPS.
- API keys and session cookies are never forwarded outside the exact configured Piwigo origin (scheme, host and effective port).
- HTTP redirects are disabled for authenticated API and server-side asset requests.

## Private image handling

- Anonymous/public Piwigo thumbnails may be cached in Drupal's public file system for performance.
- When Piwigo authentication is configured, Media Library preview bytes are never persisted in `public://` or `temporary://`; they are fetched server-side and streamed in memory through the permission-protected thumbnail route.
- Update hook `10002` removes thumbnail files produced by older development builds before this distinction existed.
- This hardening currently covers Media Library previews. Frontend formatters still reference Piwigo derivative URLs directly; installations requiring Drupal access control to govern the binary image itself need the planned access-aware proxy transport.
