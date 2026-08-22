<?php

declare(strict_types=1);

namespace Drupal\piwigo_display\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\piwigo_display\Service\PiwigoClient;
use Drupal\piwigo_display\Service\ThumbnailManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Lazily serves one cached Piwigo thumbnail at a time.
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

  public function thumbnail(int $image_id): BinaryFileResponse {
    if ($image_id <= 0) {
      throw new NotFoundHttpException();
    }

    try {
      $image = $this->piwigoClient->getImage($image_id);
      $uri = $image ? $this->thumbnailManager->getLocalThumbnailUri($image) : NULL;
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
      ['Content-Type' => $this->contentType($real_path)],
      FALSE,
      ResponseHeaderBag::DISPOSITION_INLINE,
      TRUE,
      TRUE,
    );
    $response->setPrivate();
    $response->setMaxAge(3600);
    return $response;
  }

  private function contentType(string $path): string {
    return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
      'png' => 'image/png',
      'webp' => 'image/webp',
      'gif' => 'image/gif',
      default => 'image/jpeg',
    };
  }

}
