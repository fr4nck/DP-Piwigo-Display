<?php

declare(strict_types=1);

namespace Drupal\piwigo_display\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\piwigo_display\Service\PiwigoClient;
use Drupal\piwigo_display\Service\ThumbnailManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Lazily serves one Piwigo thumbnail at a time.
 */
final class ThumbnailController implements ContainerInjectionInterface {

  public function __construct(
    private readonly PiwigoClient $piwigoClient,
    private readonly ThumbnailManager $thumbnailManager,
    private readonly FileSystemInterface $fileSystem,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('piwigo_display.client'),
      $container->get('piwigo_display.thumbnail_manager'),
      $container->get('file_system'),
    );
  }

  public function thumbnail(int $image_id): Response {
    if ($image_id <= 0) {
      throw new NotFoundHttpException();
    }

    try {
      $image = $this->piwigoClient->getImage($image_id);
      if (!$image) {
        throw new NotFoundHttpException();
      }

      // Never persist authenticated Piwigo assets in public://. The protected
      // Drupal route fetches the small derivative in memory and sends it only
      // to an authorized Media editor.
      if ($this->piwigoClient->usesAuthentication()) {
        return $this->authenticatedThumbnailResponse($image);
      }

      $uri = $this->thumbnailManager->getLocalThumbnailUri($image);
    }
    catch (NotFoundHttpException $e) {
      throw $e;
    }
    catch (\Throwable) {
      throw new NotFoundHttpException();
    }

    if ($uri === NULL) {
      throw new NotFoundHttpException();
    }

    $real_path = $this->fileSystem->realpath($uri);
    if (!is_string($real_path) || $real_path === '' || !is_file($real_path) || !is_readable($real_path)) {
      throw new NotFoundHttpException();
    }

    $response = new BinaryFileResponse(
      $real_path,
      200,
      [
        'Content-Type' => $this->contentTypeFromPath($real_path),
        'X-Content-Type-Options' => 'nosniff',
      ],
      FALSE,
      ResponseHeaderBag::DISPOSITION_INLINE,
      TRUE,
      TRUE,
    );
    $response->setPrivate();
    $response->setMaxAge(3600);
    return $response;
  }

  /**
   * Streams an authenticated Piwigo thumbnail without persisting its bytes.
   *
   * @param array<string, mixed> $image
   */
  private function authenticatedThumbnailResponse(array $image): Response {
    $url = $this->piwigoClient->getThumbnailUrl($image);
    if ($url === '') {
      throw new NotFoundHttpException();
    }

    $data = $this->piwigoClient->fetchAsset($url);
    if ($data === '') {
      throw new NotFoundHttpException();
    }

    $response = new Response($data, 200, [
      'Content-Type' => $this->contentTypeFromData($data, $url),
      'X-Content-Type-Options' => 'nosniff',
    ]);
    $response->setPrivate();
    $response->setMaxAge(300);
    return $response;
  }

  private function contentTypeFromData(string $data, string $url): string {
    if (str_starts_with($data, "\xFF\xD8\xFF")) {
      return 'image/jpeg';
    }
    if (str_starts_with($data, "\x89PNG\r\n\x1A\n")) {
      return 'image/png';
    }
    if (str_starts_with($data, 'GIF87a') || str_starts_with($data, 'GIF89a')) {
      return 'image/gif';
    }
    if (strlen($data) >= 12 && substr($data, 0, 4) === 'RIFF' && substr($data, 8, 4) === 'WEBP') {
      return 'image/webp';
    }

    $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
    return $this->contentTypeFromPath($path);
  }

  private function contentTypeFromPath(string $path): string {
    return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
      'png' => 'image/png',
      'webp' => 'image/webp',
      'gif' => 'image/gif',
      default => 'image/jpeg',
    };
  }

}
