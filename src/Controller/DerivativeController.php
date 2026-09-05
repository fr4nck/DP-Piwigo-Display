<?php

declare(strict_types=1);

namespace Drupal\piwigo_display\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\media\MediaInterface;
use Drupal\piwigo_display\Service\PiwigoClient;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Streams protected Piwigo derivatives through Drupal Media access control.
 */
final class DerivativeController implements ContainerInjectionInterface {

  private const ALLOWED_DERIVATIVES = [
    'square',
    'thumb',
    'xsmall',
    'small',
    'medium',
    'large',
    'xlarge',
    'xxlarge',
  ];

  public function __construct(
    private readonly PiwigoClient $piwigoClient,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('piwigo_display.client'));
  }

  public function derivative(MediaInterface $media, string $derivative): Response {
    // This route only exists to bridge authenticated Piwigo assets. Public
    // libraries should keep using their direct derivative URLs.
    if (!$this->piwigoClient->usesAuthentication()) {
      throw new NotFoundHttpException();
    }
    if (!in_array($derivative, self::ALLOWED_DERIVATIVES, TRUE)) {
      throw new NotFoundHttpException();
    }

    $source = $media->getSource();
    if ($source->getPluginId() !== 'piwigo_image') {
      throw new NotFoundHttpException();
    }

    $imageId = (int) $source->getSourceFieldValue($media);
    if ($imageId <= 0) {
      throw new NotFoundHttpException();
    }

    try {
      $image = $this->piwigoClient->getImage($imageId);
      if ($image === []) {
        throw new NotFoundHttpException();
      }

      $url = $this->piwigoClient->getDerivativeUrl($image, $derivative);
      if ($url === '') {
        throw new NotFoundHttpException();
      }

      $data = $this->piwigoClient->fetchAsset($url);
      if ($data === '') {
        throw new NotFoundHttpException();
      }

      $contentType = $this->detectContentType($data);
      if ($contentType === NULL) {
        // Deliberately reject unknown image formats instead of reflecting an
        // upstream Content-Type. In particular, SVG is never served here.
        throw new NotFoundHttpException();
      }
    }
    catch (NotFoundHttpException $e) {
      throw $e;
    }
    catch (\Throwable) {
      throw new NotFoundHttpException();
    }

    $response = new Response($data, 200, [
      'Content-Type' => $contentType,
      'Content-Disposition' => 'inline',
      'X-Content-Type-Options' => 'nosniff',
      'Content-Security-Policy' => "default-src 'none'; sandbox",
      'Referrer-Policy' => 'no-referrer',
    ]);
    $response->setPrivate();
    $response->setMaxAge(300);
    return $response;
  }

  private function detectContentType(string $data): ?string {
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
    return NULL;
  }

}
