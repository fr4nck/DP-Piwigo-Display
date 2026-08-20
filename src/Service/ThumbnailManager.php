<?php

declare(strict_types=1);

namespace Drupal\piwigo_display\Service;

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Psr\Log\LoggerInterface;

/**
 * Stores small Piwigo derivatives locally for Drupal Media Library previews.
 */
final class ThumbnailManager {

  private const DIRECTORY = 'public://piwigo_display/thumbnails';

  public function __construct(
    private readonly PiwigoClient $piwigoClient,
    private readonly FileSystemInterface $fileSystem,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Returns a local stream-wrapper URI for a Piwigo image thumbnail.
   */
  public function getLocalThumbnailUri(array $image): ?string {
    $id = (int) ($image['id'] ?? 0);
    $url = $this->piwigoClient->getThumbnailUrl($image);
    if ($id <= 0 || $url === '') {
      return NULL;
    }

    $extension = $this->guessExtension($url);
    $destination = self::DIRECTORY . '/' . $id . '.' . $extension;
    $real_path = $this->fileSystem->realpath($destination);
    if (is_string($real_path) && is_file($real_path) && filesize($real_path) > 0) {
      return $destination;
    }

    $directory = self::DIRECTORY;
    if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      $this->logger->warning('Unable to create Piwigo thumbnail directory @directory.', ['@directory' => $directory]);
      return NULL;
    }

    try {
      $data = $this->piwigoClient->fetchAsset($url);
      if ($data === '') {
        return NULL;
      }
      $saved = $this->fileSystem->saveData($data, $destination, FileExists::Replace);
      return is_string($saved) && $saved !== '' ? $saved : NULL;
    }
    catch (\Throwable $e) {
      $this->logger->warning('Unable to cache Piwigo thumbnail @id: @message', [
        '@id' => $id,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  private function guessExtension(string $url): string {
    $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], TRUE) ? $extension : 'jpg';
  }

}
