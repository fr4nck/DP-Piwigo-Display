<?php

declare(strict_types=1);

namespace Drupal\piwigo_display\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\media_library\Form\AddFormBase;
use Drupal\media_library\MediaLibraryUiBuilder;
use Drupal\media_library\OpenerResolverInterface;
use Drupal\piwigo_display\Service\PiwigoClient;
use Drupal\piwigo_display\Service\ThumbnailManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Piwigo browser embedded in Drupal Media Library's add workflow.
 */
final class PiwigoLibraryForm extends AddFormBase {

  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    MediaLibraryUiBuilder $library_ui_builder,
    OpenerResolverInterface $opener_resolver,
    private readonly PiwigoClient $piwigoClient,
    private readonly ThumbnailManager $thumbnailManager,
  ) {
    parent::__construct($entity_type_manager, $library_ui_builder, $opener_resolver);
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('media_library.ui_builder'),
      $container->get('media_library.opener_resolver'),
      $container->get('piwigo_display.client'),
      $container->get('piwigo_display.thumbnail_manager'),
    );
  }

  protected function buildInputElement(array $form, FormStateInterface $form_state): array {
    $form['#attached']['library'][] = 'piwigo_display/media_library';

    if (!$this->piwigoClient->isConfigured()) {
      $form['not_configured'] = [
        '#type' => 'status_messages',
      ];
      $form['message'] = [
        '#markup' => '<p>' . $this->t('Piwigo Display is not configured yet. Configure the Piwigo URL before browsing the library.') . '</p>',
      ];
      return $form;
    }

    try {
      $albums = $this->piwigoClient->getAlbums(TRUE);
    }
    catch (\Throwable $e) {
      $form['message'] = [
        '#markup' => '<p>' . $this->t('Unable to load Piwigo albums: @message', ['@message' => $e->getMessage()]) . '</p>',
      ];
      return $form;
    }

    $form['browser'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['piwigo-display-browser']],
    ];

    $form['browser']['filters'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['piwigo-display-browser__filters']],
    ];

    $form['browser']['filters']['piwigo_query'] = [
      '#type' => 'search',
      '#title' => $this->t('Search Piwigo'),
      '#default_value' => (string) $form_state->get('piwigo_query'),
      '#placeholder' => $this->t('Title, tag, author…'),
    ];

    $form['browser']['filters']['piwigo_album'] = [
      '#type' => 'select',
      '#title' => $this->t('Album'),
      '#empty_option' => $this->t('- Any album -'),
      '#options' => $this->buildAlbumOptions($albums),
      '#default_value' => (string) ($form_state->get('piwigo_album') ?? ''),
    ];

    $form['browser']['filters']['search'] = [
      '#type' => 'submit',
      '#value' => $this->t('Search'),
      '#submit' => ['::searchSubmit'],
      '#ajax' => [
        'callback' => '::updateFormCallback',
        'wrapper' => 'media-library-add-form-wrapper',
      ],
      '#limit_validation_errors' => [
        ['browser', 'filters', 'piwigo_query'],
        ['browser', 'filters', 'piwigo_album'],
      ],
    ];

    $results = $form_state->get('piwigo_results');
    if (is_array($results)) {
      $images = is_array($results['images'] ?? NULL) ? $results['images'] : [];
      if (!$images) {
        $form['browser']['empty'] = [
          '#markup' => '<p>' . $this->t('No Piwigo image matches this search.') . '</p>',
        ];
      }
      else {
        $form['browser']['results'] = [
          '#type' => 'tableselect',
          '#header' => [
            'preview' => $this->t('Preview'),
            'name' => $this->t('Image'),
            'dimensions' => $this->t('Dimensions'),
          ],
          '#options' => $this->buildImageOptions($images),
          '#empty' => $this->t('No images found.'),
        ];

        $form['browser']['add_selected'] = [
          '#type' => 'submit',
          '#button_type' => 'primary',
          '#value' => $this->t('Add selected images'),
          '#submit' => ['::addSelectedSubmit'],
          '#ajax' => [
            'callback' => '::updateFormCallback',
            'wrapper' => 'media-library-add-form-wrapper',
          ],
        ];
      }
    }
    else {
      $form['browser']['hint'] = [
        '#markup' => '<p>' . $this->t('Search the full Piwigo library, or choose an album and click Search.') . '</p>',
      ];
    }

    return $form;
  }

  public function searchSubmit(array &$form, FormStateInterface $form_state): void {
    $query = trim((string) $form_state->getValue(['browser', 'filters', 'piwigo_query']));
    $album_id = (int) $form_state->getValue(['browser', 'filters', 'piwigo_album']);

    try {
      // When a query is present, use Piwigo's global quick search. Album browsing
      // remains a distinct, predictable path in the first release.
      $results = $query !== ''
        ? $this->piwigoClient->searchImages($query, 0, 60)
        : $this->piwigoClient->getAlbumImages($album_id, 0, 60, FALSE);

      if ($query === '' && $album_id <= 0) {
        $results = ['paging' => [], 'images' => []];
        $this->messenger()->addWarning($this->t('Enter a search term or select an album.'));
      }

      $form_state
        ->set('piwigo_query', $query)
        ->set('piwigo_album', $album_id > 0 ? (string) $album_id : '')
        ->set('piwigo_results', $results)
        ->setRebuild();
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Piwigo search failed: @message', ['@message' => $e->getMessage()]));
      $form_state->setRebuild();
    }
  }

  public function addSelectedSubmit(array &$form, FormStateInterface $form_state): void {
    $selected = array_values(array_filter(
      (array) $form_state->getValue(['browser', 'results'], []),
      static fn ($value): bool => is_scalar($value) && (int) $value > 0,
    ));
    $selected = array_map('strval', $selected);

    if (!$selected) {
      $this->messenger()->addWarning($this->t('Select at least one Piwigo image.'));
      $form_state->setRebuild();
      return;
    }

    $this->processInputValues($selected, $form, $form_state);
  }

  /**
   * @return array<string, string>
   */
  private function buildAlbumOptions(array $albums): array {
    $names = [];
    foreach ($albums as $album) {
      $id = (int) ($album['id'] ?? 0);
      if ($id > 0) {
        $names[$id] = trim(strip_tags((string) ($album['name'] ?? ('Album ' . $id))));
      }
    }

    $options = [];
    foreach ($albums as $album) {
      $id = (int) ($album['id'] ?? 0);
      if ($id <= 0) {
        continue;
      }
      $uppercats = (string) ($album['uppercats'] ?? $id);
      $ids = array_values(array_filter(array_map('intval', explode(',', $uppercats))));
      $parts = [];
      foreach ($ids ?: [$id] as $path_id) {
        if (isset($names[$path_id])) {
          $parts[] = $names[$path_id];
        }
      }
      $options[(string) $id] = implode(' / ', $parts ?: [$names[$id] ?? ('Album ' . $id)]);
    }

    natcasesort($options);
    return $options;
  }

  /**
   * @return array<string, array<string, mixed>>
   */
  private function buildImageOptions(array $images): array {
    $options = [];
    foreach ($images as $image) {
      $id = (int) ($image['id'] ?? 0);
      if ($id <= 0) {
        continue;
      }
      $thumbnail = $this->thumbnailManager->getLocalThumbnailUri($image) ?? (string) ($image['thumbnail_url'] ?? '');
      $options[(string) $id] = [
        'preview' => $thumbnail !== '' ? [
          'data' => [
            '#theme' => 'image',
            '#uri' => $thumbnail,
            '#alt' => (string) ($image['name'] ?? ''),
            '#attributes' => ['class' => ['piwigo-display-browser__thumbnail']],
          ],
        ] : '',
        'name' => (string) ($image['name'] ?? ('Piwigo image ' . $id)),
        'dimensions' => ((int) ($image['width'] ?? 0) > 0 && (int) ($image['height'] ?? 0) > 0)
          ? ((int) $image['width'] . ' × ' . (int) $image['height'])
          : '—',
      ];
    }
    return $options;
  }

}
