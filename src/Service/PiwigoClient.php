<?php

declare(strict_types=1);

namespace Drupal\piwigo_display\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Cookie\CookieJar;
use Psr\Log\LoggerInterface;

/**
 * Small, stateless client for the Piwigo Web API.
 */
final class PiwigoClient {

  private ?CookieJar $legacyCookieJar = NULL;

  private bool $legacyAuthenticated = FALSE;

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly CacheBackendInterface $cache,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Returns TRUE when a Piwigo base URL is configured.
   */
  public function isConfigured(): bool {
    return $this->getBaseUrl() !== '';
  }

  /**
   * Tests the configured endpoint and returns basic server information.
   *
   * @return array<string, mixed>
   */
  public function testConnection(): array {
    $version = $this->request('pwg.getVersion');
    $status = $this->request('pwg.session.getStatus');

    return [
      'version' => is_scalar($version) ? (string) $version : ($version['version'] ?? 'unknown'),
      'status' => $status,
    ];
  }

  /**
   * Lists albums visible to the configured Piwigo identity.
   *
   * @return array<int, array<string, mixed>>
   */
  public function getAlbums(bool $recursive = TRUE): array {
    $result = $this->request('pwg.categories.getList', [
      'recursive' => $recursive ? 'true' : 'false',
    ], TRUE);

    return $this->extractList($result['categories'] ?? []);
  }

  /**
   * Searches images globally using Piwigo quick search.
   *
   * @return array{paging: array<string, mixed>, images: array<int, array<string, mixed>>}
   */
  public function searchImages(string $query, int $page = 0, int $perPage = 48): array {
    $query = trim($query);
    if ($query === '') {
      return ['paging' => [], 'images' => []];
    }

    $result = $this->request('pwg.images.search', [
      'query' => $query,
      'page' => max(0, $page),
      'per_page' => max(1, min(200, $perPage)),
    ]);

    return [
      'paging' => is_array($result['paging'] ?? NULL) ? $result['paging'] : [],
      'images' => array_map([$this, 'normalizeImage'], $this->extractList($result['images'] ?? [])),
    ];
  }

  /**
   * Lists images from one Piwigo album.
   *
   * @return array{paging: array<string, mixed>, images: array<int, array<string, mixed>>}
   */
  public function getAlbumImages(int $albumId, int $page = 0, int $perPage = 48, bool $recursive = FALSE): array {
    if ($albumId <= 0) {
      return ['paging' => [], 'images' => []];
    }

    $result = $this->request('pwg.categories.getImages', [
      'cat_id' => $albumId,
      'recursive' => $recursive ? 'true' : 'false',
      'page' => max(0, $page),
      'per_page' => max(1, min(200, $perPage)),
    ]);

    return [
      'paging' => is_array($result['paging'] ?? NULL) ? $result['paging'] : [],
      'images' => array_map([$this, 'normalizeImage'], $this->extractList($result['images'] ?? [])),
    ];
  }

  /**
   * Gets normalized metadata for one Piwigo image.
   *
   * @return array<string, mixed>
   */
  public function getImage(int|string $imageId): array {
    $id = (int) $imageId;
    if ($id <= 0) {
      return [];
    }

    $result = $this->request('pwg.images.getInfo', ['image_id' => $id], TRUE);
    if (isset($result['image']) && is_array($result['image'])) {
      $result = $result['image'];
    }

    return $this->normalizeImage(is_array($result) ? $result : []);
  }

  /**
   * Gets a derivative URL from normalized Piwigo image metadata.
   */
  public function getDerivativeUrl(array $image, ?string $size = NULL): string {
    $size ??= (string) $this->getSetting('default_derivative', 'large');
    $derivatives = is_array($image['derivatives'] ?? NULL) ? $image['derivatives'] : [];

    $candidates = array_values(array_unique(array_filter([
      $size,
      'large',
      'medium',
      'small',
      'xsmall',
      'thumb',
      'square',
    ])));

    foreach ($candidates as $candidate) {
      $value = $derivatives[$candidate] ?? NULL;
      if (is_array($value) && !empty($value['url'])) {
        return (string) $value['url'];
      }
      if (is_string($value) && $value !== '') {
        return $value;
      }
    }

    foreach (['element_url', 'url', 'tn_url'] as $key) {
      if (!empty($image[$key]) && is_string($image[$key])) {
        return $image[$key];
      }
    }

    return '';
  }

  /**
   * Gets the thumbnail URL for normalized image metadata.
   */
  public function getThumbnailUrl(array $image): string {
    $preferred = (string) $this->getSetting('thumbnail_derivative', 'thumb');
    $url = $this->getDerivativeUrl($image, $preferred);
    if ($url !== '') {
      return $url;
    }
    return (string) ($image['tn_url'] ?? '');
  }

  /**
   * Fetches a Piwigo binary asset for server-side thumbnail caching.
   *
   * The API key header is sent as a best-effort credential. Standard Piwigo
   * derivative URLs are generally directly readable. When configured, a legacy
   * service account also contributes a request-local Piwigo session cookie; a
   * dedicated proxy mode remains a future hardening option.
   */
  public function fetchAsset(string $url): string {
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
      throw new \RuntimeException('Invalid Piwigo asset URL.');
    }

    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    $baseHost = strtolower((string) parse_url($this->getBaseUrl(), PHP_URL_HOST));
    $assetHost = strtolower((string) parse_url($url, PHP_URL_HOST));
    if (!in_array($scheme, ['http', 'https'], TRUE) || $baseHost === '' || $assetHost === '' || !hash_equals($baseHost, $assetHost)) {
      throw new \RuntimeException('Piwigo assets must use HTTP(S) on the configured Piwigo host.');
    }
    if (($this->getApiKey() !== '' || $this->hasLegacyCredentials()) && $scheme !== 'https') {
      throw new \RuntimeException('Authenticated Piwigo assets require HTTPS.');
    }

    $headers = [
      'Accept' => 'image/*,*/*;q=0.8',
      'User-Agent' => 'Drupal Piwigo Display/0.1.0',
    ];
    $apiKey = $this->getApiKey();
    $sendApiKey = $apiKey !== '';
    if ($sendApiKey) {
      $headers['X-PIWIGO-API'] = $apiKey;
    }

    // A legacy service account can additionally provide a Piwigo session cookie
    // for deployments that protect i.php/original binary URLs. API keys remain
    // the preferred credential for Web API calls.
    $cookies = NULL;
    if ($this->hasLegacyCredentials()) {
      try {
        $this->ensureLegacySession();
        $cookies = $this->legacyCookieJar;
      }
      catch (\Throwable $e) {
        $this->logger->warning('Piwigo legacy session could not be established for asset retrieval: @message', [
          '@message' => $e->getMessage(),
        ]);
      }
    }

    $options = [
      'headers' => $headers,
      'timeout' => $this->getTimeout(),
      'connect_timeout' => min(5, $this->getTimeout()),
      'allow_redirects' => ($sendApiKey || $cookies instanceof CookieJar) ? FALSE : ['max' => 3, 'strict' => TRUE],
      'http_errors' => FALSE,
    ];
    if ($cookies instanceof CookieJar) {
      $options['cookies'] = $cookies;
    }

    $response = $this->httpClient->request('GET', $url, $options);

    $status = $response->getStatusCode();
    if ($status < 200 || $status >= 300) {
      throw new \RuntimeException(sprintf('Piwigo asset returned HTTP %d.', $status));
    }

    $contentType = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0] ?? ''));
    if ($contentType !== '' && !str_starts_with($contentType, 'image/')) {
      throw new \RuntimeException('Piwigo asset did not return an image content type.');
    }

    $maxBytes = 10 * 1024 * 1024;
    $contentLength = (int) $response->getHeaderLine('Content-Length');
    if ($contentLength > $maxBytes) {
      throw new \RuntimeException('Piwigo thumbnail asset exceeds the 10 MB safety limit.');
    }

    $stream = $response->getBody();
    $data = $stream->read($maxBytes + 1);
    if (strlen($data) > $maxBytes || !$stream->eof()) {
      throw new \RuntimeException('Piwigo thumbnail asset exceeds the 10 MB safety limit.');
    }
    return $data;
  }

  /**
   * Performs one Piwigo API request.
   *
   * @return mixed
   *   The API result payload (without stat/result wrapper).
   */
  private function request(string $method, array $parameters = [], bool $cacheable = FALSE): mixed {
    $baseUrl = $this->getBaseUrl();
    if ($baseUrl === '') {
      throw new \RuntimeException('Piwigo Display is not configured: missing base URL.');
    }

    if (($this->getApiKey() !== '' || $this->hasLegacyCredentials()) && parse_url($baseUrl, PHP_URL_SCHEME) !== 'https') {
      throw new \RuntimeException('Authenticated Piwigo API access requires HTTPS.');
    }

    $cacheKey = 'piwigo_display:' . hash('sha256', $baseUrl . '|' . $method . '|' . serialize($parameters) . '|' . $this->getIdentityFingerprint());
    if ($cacheable && ($cached = $this->cache->get($cacheKey))) {
      return $cached->data;
    }

    $headers = [
      'Accept' => 'application/json',
      'User-Agent' => 'Drupal Piwigo Display/0.1.0',
    ];
    $apiKey = $this->getApiKey();
    if ($apiKey !== '') {
      // Piwigo >= 16.1 uses X-PIWIGO-API for personal API keys.
      $headers['X-PIWIGO-API'] = $apiKey;
    }
    elseif ($this->hasLegacyCredentials()) {
      $this->ensureLegacySession();
    }

    $options = [
      'headers' => $headers,
      'form_params' => ['method' => $method] + $parameters,
      'timeout' => $this->getTimeout(),
      'connect_timeout' => min(5, $this->getTimeout()),
      'allow_redirects' => ($apiKey !== '' || $this->legacyCookieJar instanceof CookieJar) ? FALSE : ['max' => 3, 'strict' => TRUE],
      'http_errors' => FALSE,
    ];
    if ($apiKey === '' && $this->legacyCookieJar instanceof CookieJar) {
      $options['cookies'] = $this->legacyCookieJar;
    }

    try {
      $response = $this->httpClient->request('POST', $baseUrl . '/ws.php?format=json', $options);
    }
    catch (\Throwable $e) {
      $this->logger->error('Piwigo API request @method failed: @message', [
        '@method' => $method,
        '@message' => $e->getMessage(),
      ]);
      throw new \RuntimeException('Unable to contact the Piwigo server.', 0, $e);
    }

    $status = $response->getStatusCode();
    if ($status < 200 || $status >= 300) {
      throw new \RuntimeException(sprintf('Piwigo returned HTTP %d.', $status));
    }

    $decoded = json_decode((string) $response->getBody(), TRUE);
    if (!is_array($decoded)) {
      throw new \RuntimeException('Piwigo returned invalid JSON.');
    }

    if (($decoded['stat'] ?? '') !== 'ok') {
      $message = is_scalar($decoded['message'] ?? NULL) ? (string) $decoded['message'] : 'Unknown Piwigo API error.';
      throw new \RuntimeException($message);
    }

    $result = $decoded['result'] ?? [];
    if ($cacheable) {
      $ttl = max(0, (int) $this->getSetting('cache_ttl', 300));
      if ($ttl > 0) {
        $this->cache->set($cacheKey, $result, time() + $ttl);
      }
    }

    return $result;
  }

  /**
   * Normalizes list responses from old and current Piwigo JSON shapes.
   *
   * @return array<int, array<string, mixed>>
   */
  private function extractList(mixed $value): array {
    if (!is_array($value)) {
      return [];
    }
    if (isset($value['_content']) && is_array($value['_content'])) {
      $value = $value['_content'];
    }
    return array_values(array_filter($value, 'is_array'));
  }

  /**
   * Normalizes metadata fields used by Drupal integration.
   *
   * @param array<string, mixed> $image
   *
   * @return array<string, mixed>
   */
  private function normalizeImage(array $image): array {
    $image['id'] = (int) ($image['id'] ?? 0);
    $image['name'] = trim(strip_tags((string) ($image['name'] ?? $image['file'] ?? ('Piwigo image ' . $image['id']))));
    $image['width'] = (int) ($image['width'] ?? 0);
    $image['height'] = (int) ($image['height'] ?? 0);
    $image['comment'] = (string) ($image['comment'] ?? '');
    $image['author'] = (string) ($image['author'] ?? '');
    $image['derivatives'] = is_array($image['derivatives'] ?? NULL) ? $image['derivatives'] : [];
    $image['thumbnail_url'] = $this->getThumbnailUrl($image);
    $image['display_url'] = $this->getDerivativeUrl($image);
    return $image;
  }

  /**
   * Opens a session using the classic Piwigo service-account login.
   */
  private function ensureLegacySession(): void {
    if ($this->legacyAuthenticated) {
      return;
    }
    if (!$this->hasLegacyCredentials()) {
      throw new \RuntimeException('Legacy Piwigo credentials are not configured.');
    }

    $baseUrl = $this->getBaseUrl();
    if ($baseUrl === '') {
      throw new \RuntimeException('Piwigo Display is not configured: missing base URL.');
    }
    if (parse_url($baseUrl, PHP_URL_SCHEME) !== 'https') {
      throw new \RuntimeException('Piwigo service-account authentication requires HTTPS.');
    }

    $this->legacyCookieJar = new CookieJar();
    $response = $this->httpClient->request('POST', $baseUrl . '/ws.php?format=json', [
      'headers' => [
        'Accept' => 'application/json',
        'User-Agent' => 'Drupal Piwigo Display/0.1.0',
      ],
      'form_params' => [
        'method' => 'pwg.session.login',
        'username' => $this->getLegacyUsername(),
        'password' => $this->getLegacyPassword(),
      ],
      'cookies' => $this->legacyCookieJar,
      'timeout' => $this->getTimeout(),
      'connect_timeout' => min(5, $this->getTimeout()),
      'allow_redirects' => FALSE,
      'http_errors' => FALSE,
    ]);

    $status = $response->getStatusCode();
    if ($status < 200 || $status >= 300) {
      throw new \RuntimeException(sprintf('Piwigo login returned HTTP %d.', $status));
    }

    $decoded = json_decode((string) $response->getBody(), TRUE);
    if (!is_array($decoded) || ($decoded['stat'] ?? '') !== 'ok') {
      $message = is_array($decoded) && is_scalar($decoded['message'] ?? NULL)
        ? (string) $decoded['message']
        : 'Piwigo service-account login failed.';
      throw new \RuntimeException($message);
    }

    $this->legacyAuthenticated = TRUE;
  }

  private function hasLegacyCredentials(): bool {
    return $this->getLegacyUsername() !== '' && $this->getLegacyPassword() !== '';
  }

  private function getLegacyUsername(): string {
    return trim((string) $this->getSetting('legacy_username', ''));
  }

  private function getLegacyPassword(): string {
    return (string) $this->getSetting('legacy_password', '');
  }

  private function getBaseUrl(): string {
    $value = trim((string) $this->getSetting('base_url', ''));
    if ($value === '') {
      return '';
    }
    $parts = parse_url($value);
    if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], TRUE) || empty($parts['host'])) {
      return '';
    }
    return rtrim($value, '/');
  }

  private function getApiKey(): string {
    return trim((string) Settings::get('piwigo_display.api_key', $this->configFactory->get('piwigo_display.settings')->get('api_key') ?? ''));
  }

  private function getTimeout(): int {
    return max(1, min(60, (int) $this->getSetting('request_timeout', 10)));
  }

  private function getSetting(string $name, mixed $default = NULL): mixed {
    $settingsOverride = Settings::get('piwigo_display.' . $name, NULL);
    if ($settingsOverride !== NULL) {
      return $settingsOverride;
    }
    return $this->configFactory->get('piwigo_display.settings')->get($name) ?? $default;
  }

  private function getIdentityFingerprint(): string {
    $apiKey = $this->getApiKey();
    if ($apiKey !== '') {
      return 'api:' . hash('sha256', $apiKey);
    }
    if ($this->hasLegacyCredentials()) {
      return 'legacy:' . hash('sha256', $this->getLegacyUsername());
    }
    return 'anonymous';
  }

}
