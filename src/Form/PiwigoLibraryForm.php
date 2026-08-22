<?php

declare(strict_types=1);

namespace Drupal\piwigo_display\Form;

use Drupal\Component\Utility\Html;
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

  public function getFormId(): string {
    return 'piwigo_display_media_library_add_form';
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
      '#tree' => TRUE,
      '#attributes' => [
        'class' => ['piwigo-display-browser'],
        'data-piwigo-display-browser' => '1',
      ],
    ];

    $form['browser']['header'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['piwigo-display-browser__header']],
    ];
    $form['browser']['header']['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h3',
      '#value' => $this->t('Piwigo library'),
      '#attributes' => ['class' => ['piwigo-display-browser__title']],
    ];
    $form['browser']['header']['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Browse your Piwigo albums or search the full library, then select the images to add to Drupal.'),
      '#attributes' => ['class' => ['piwigo-display-browser__description']],
    ];

    $form['browser']['filters'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['piwigo-display-browser__filters'],
        'aria-label' => $this->t('Piwigo filters'),
      ],
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
      '#attributes' => ['class' => ['piwigo-display-browser__search-button']],
    ];

    $results = $form_state->get('piwigo_results');
    if (is_array($results)) {
      $images = is_array($results['images'] ?? NULL) ? $results['images'] : [];
      if (!$images) {
        $form['browser']['empty'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['piwigo-display-browser__empty']],
          'title' => [
            '#type' => 'html_tag',
            '#tag' => 'strong',
            '#value' => $this->t('No image found'),
          ],
          'text' => [
            '#type' => 'html_tag',
            '#tag' => 'p',
            '#value' => $this->t('Try another search term or choose a different album.'),
          ],
        ];
      }
      else {
        $form['browser']['results_header'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['piwigo-display-browser__results-header']],
          'title' => [
            '#type' => 'html_tag',
            '#tag' => 'h4',
            '#value' => $this->t('Images'),
          ],
          'count' => [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => $this->formatPlural(count($images), '1 result', '@count results'),
            '#attributes' => ['class' => ['piwigo-display-browser__result-count']],
          ],
        ];

        $form['browser']['results'] = $this->buildImageCards($images);

        $form['browser']['selection_actions'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['piwigo-display-browser__selection-actions']],
        ];
        $form['browser']['selection_actions']['selection_status'] = [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $this->t('No image selected'),
          '#attributes' => [
            'class' => ['piwigo-display-browser__selection-status'],
            'data-piwigo-display-selection-status' => '1',
            'aria-live' => 'polite',
          ],
        ];
        $form['browser']['selection_actions']['add_selected'] = [
          '#type' => 'submit',
          '#button_type' => 'primary',
          '#value' => $this->t('Add selected images'),
          '#submit' => ['::addSelectedSubmit'],
          '#ajax' => [
            'callback' => '::updateFormCallback',
            'wrapper' => 'media-library-add-form-wrapper',
          ],
          '#attributes' => ['class' => ['piwigo-display-browser__add-selected']],
        ];
      }
    }
    else {
      $form['browser']['hint'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['piwigo-display-browser__hint']],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'strong',
          '#value' => $this->t('Find an image in Piwigo'),
        ],
        'text' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Search the full library, or choose an album and click Search.'),
        ],
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
    $rows = (array) $form_state->getValue(['browser', 'results'], []);
    $selected = [];
    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }
      $value = $row['selection'] ?? NULL;
      if (is_scalar($value) && (int) $value > 0) {
        $selected[] = (string) (int) $value;
      }
    }

    if (!$selected) {
      $this->messenger()->addWarning($this->t('Select at least one Piwigo image.'));
      $form_state->setRebuild();
      return;
    }

    $this->processInputValues(array_values(array_unique($selected)), $form, $form_state);
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
   * Builds an accessible visual image grid without replacing Drupal controls.
   *
   * @param array<int, array<string, mixed>> $images
   *
   * @return array<string, mixed>
   */
  private function buildImageCards(array $images): array {
    $cards = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#attributes' => [
        'class' => ['piwigo-display-browser__grid'],
        'aria-label' => $this->t('Piwigo images'),
      ],
    ];

    foreach ($images as $image) {
      $id = (int) ($image['id'] ?? 0);
      if ($id <= 0) {
        continue;
      }

      $key = 'image_' . $id;
      $name = trim((string) ($image['name'] ?? '')) ?: (string) $this->t('Piwigo image @id', ['@id' => $id]);
      $author = trim((string) ($image['author'] ?? ''));
      $width = (int) ($image['width'] ?? 0);
      $height = (int) ($image['height'] ?? 0);
      $dimensions = $width > 0 && $height > 0 ? $width . ' × ' . $height : (string) $this->t('Dimensions unavailable');
      $thumbnail = $this->thumbnailManager->getLocalThumbnailUri($image) ?? (string) ($image['thumbnail_url'] ?? '');

      $cards[$key] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['piwigo-display-card'],
          'data-piwigo-display-card' => (string) $id,
        ],
      ];

      $cards[$key]['selection'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Select @name', ['@name' => $name]),
        '#title_display' => 'invisible',
        '#return_value' => (string) $id,
        '#attributes' => [
          'class' => ['piwigo-display-card__checkbox'],
          'data-piwigo-display-selection' => (string) $id,
        ],
      ];

      if ($thumbnail !== '') {
        $cards[$key]['preview'] = [
          '#theme' => 'image',
          '#uri' => $thumbnail,
          '#alt' => $name,
          '#attributes' => [
            'class' => ['piwigo-display-card__image'],
            'loading' => 'lazy',
          ],
          '#prefix' => '<div class="piwigo-display-card__preview">',
          '#suffix' => '</div>',
        ];
      }
      else {
        $cards[$key]['preview'] = [
          '#markup' => '<div class="piwigo-display-card__preview piwigo-display-card__preview--empty" aria-hidden="true"></div>',
        ];
      }

      $cards[$key]['meta'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['piwigo-display-card__meta']],
      ];
      $cards[$key]['meta']['name'] = [
        '#type' => 'html_tag',
        '#tag' => 'strong',
        '#value' => Html::escape($name),
        '#attributes' => ['class' => ['piwigo-display-card__name']],
      ];
      $cards[$key]['meta']['details'] = [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => Html::escape($author !== '' ? $dimensions . ' · ' . $author : $dimensions),
        '#attributes' => ['class' => ['piwigo-display-card__details']],
      ];
    }

    return $cards;
  }

}
