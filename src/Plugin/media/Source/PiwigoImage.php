<?php

declare(strict_types=1);

namespace Drupal\piwigo_display\Plugin\media\Source;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\media\Attribute\MediaSource;
use Drupal\media\MediaInterface;
use Drupal\media\MediaSourceBase;
use Drupal\media\MediaTypeInterface;
use Drupal\piwigo_display\Form\PiwigoLibraryForm;
use Drupal\piwigo_display\Service\PiwigoClient;
use Drupal\piwigo_display\Service\ThumbnailManager;
use Drupal\piwigo_display\Value\GeoCoordinates;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Media source whose canonical value is a Piwigo image ID.
 */
#[MediaSource(
  id: 'piwigo_image',
  label: new TranslatableMarkup('Piwigo image'),
  description: new TranslatableMarkup('Reference an image stored in a Piwigo photo library.'),
  allowed_field_types: ['string'],
  forms: ['media_library_add' => PiwigoLibraryForm::class],
  default_thumbnail_filename: 'generic.png',
  thumbnail_uri_metadata_attribute: 'thumbnail_uri',
  thumbnail_width_metadata_attribute: 'width',
  thumbnail_height_metadata_attribute: 'height',
  thumbnail_alt_metadata_attribute: 'default_name',
  default_name_metadata_attribute: 'default_name',
)]
final class PiwigoImage extends MediaSourceBase {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    EntityFieldManagerInterface $entity_field_manager,
    FieldTypePluginManagerInterface $field_type_manager,
    ConfigFactoryInterface $config_factory,
    private readonly PiwigoClient $piwigoClient,
    private readonly ThumbnailManager $thumbnailManager,
  ) {
    parent::__construct(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $entity_type_manager,
      $entity_field_manager,
      $field_type_manager,
      $config_factory,
    );
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('plugin.manager.field.field_type'),
      $container->get('config.factory'),
      $container->get('piwigo_display.client'),
      $container->get('piwigo_display.thumbnail_manager'),
    );
  }

  public function getMetadataAttributes(): array {
    return [
      'default_name' => $this->t('Image name'),
      'thumbnail_uri' => $this->t('Media thumbnail'),
      'width' => $this->t('Width'),
      'height' => $this->t('Height'),
      'author' => $this->t('Author'),
      'description' => $this->t('Description'),
      'display_url' => $this->t('Piwigo derivative URL'),
      'piwigo_id' => $this->t('Piwigo image ID'),
      'latitude' => $this->t('Latitude'),
      'longitude' => $this->t('Longitude'),
    ];
  }

  public function getMetadata(MediaInterface $media, $attribute_name): mixed {
    $id = (int) $this->getSourceFieldValue($media);
    if ($id <= 0) {
      return parent::getMetadata($media, $attribute_name);
    }

    // Drupal persists the Media thumbnail URI as a File entity. For private
    // Piwigo connections, never give core a URI that points to sensitive bytes
    // in a publicly addressable stream wrapper. The add browser still displays
    // the real image through our permission-protected thumbnail route.
    if ($attribute_name === 'thumbnail_uri' && $this->piwigoClient->usesAuthentication()) {
      return parent::getMetadata($media, 'thumbnail_uri');
    }

    try {
      $image = $this->piwigoClient->getImage($id);
    }
    catch (\Throwable) {
      return parent::getMetadata($media, $attribute_name);
    }

    $coordinates = GeoCoordinates::fromPiwigo(
      $image['latitude'] ?? NULL,
      $image['longitude'] ?? NULL,
    );

    return match ($attribute_name) {
      'default_name' => (string) ($image['name'] ?? ('Piwigo image ' . $id)),
      'thumbnail_uri' => $this->thumbnailManager->getLocalThumbnailUri($image) ?? parent::getMetadata($media, 'thumbnail_uri'),
      'width' => (int) ($image['width'] ?? 0),
      'height' => (int) ($image['height'] ?? 0),
      'author' => (string) ($image['author'] ?? ''),
      'description' => trim(strip_tags((string) ($image['comment'] ?? ''))),
      'display_url' => (string) ($image['display_url'] ?? ''),
      'piwigo_id' => $id,
      'latitude' => $coordinates?->latitude,
      'longitude' => $coordinates?->longitude,
      default => parent::getMetadata($media, $attribute_name),
    };
  }

  public function prepareViewDisplay(MediaTypeInterface $type, EntityViewDisplayInterface $display): void {
    $source_field = $this->getSourceFieldDefinition($type);
    if ($source_field) {
      $display->setComponent($source_field->getName(), [
        'type' => 'piwigo_image',
        'label' => 'visually_hidden',
        'settings' => [
          'derivative' => 'large',
          'loading' => 'lazy',
          'link_to_piwigo' => FALSE,
        ],
      ]);
    }
  }

}
