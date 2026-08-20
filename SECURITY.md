# Security

## Supported versions

Piwigo Display is currently in development. Security fixes are applied to the latest development branch until a stable release policy is published.

## Reporting a vulnerability

Please do not publish credentials, API keys, private Piwigo URLs or proof-of-concept material containing sensitive data in a public issue.

Use GitHub's private vulnerability reporting feature when it is enabled for this repository. If it is not available, open a minimal public issue without sensitive details so a private contact channel can be established.

## Credential handling

- Prefer Piwigo personal API keys over username/password authentication.
- Put production secrets in Drupal `settings.php` rather than exported configuration.
- Authenticated connections require HTTPS.
- Piwigo binary assets fetched server-side are restricted to the configured Piwigo host.
