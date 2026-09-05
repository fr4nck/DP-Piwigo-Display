<?php

declare(strict_types=1);

namespace Drupal\piwigo_display\Plugin\Field\FieldFormatter;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\media\MediaInterface;
use Drupal\piwigo_display\Service\PiwigoClient;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the referenced Piwigo derivative without importing the original.
 */
#[FieldFormatter(
  id: 'piwigo_image',
  label: new TranslatableMarkup('Piwigo image'),
  description: new TranslatableMarkup('Render a derivative provided by Piwigo.'),
  field_types: ['string'],
)]
final class PiwigoImageFormatter extends FormatterBase implements ContainerFactoryPluginInterface {

  public function __construct(
    $plugin_id,
    $plugin_definition,
    FieldDefinitionInterface $field_definition,
    array $settings,
    $label,
    $view_mode,
    array $third_party_settings,
    private readonly PiwigoClient $piwigoClient,
    private readonly ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('piwigo_display.client'),
      $container->get('config.factory'),
    );
  }

  public static function defaultSettings(): array {
    return [
      'derivative' => 'large',
      'loading' => 'lazy',
      'link_to_piwigo' => FALSE,
    ] + parent::defaultSettings();
  }

  public function settingsForm(array $form, FormStateInterface $form_state): array {
    $element = parent::settingsForm($form, $form_state);
    $element['derivative'] = [
      '#type' => 'select',
      '#title' => $this->t('Piwigo derivative'),
      '#options' => [
        'square' => $this->t('Square'),
        'thumb' => $this->t('Thumbnail'),
        'xsmall' => $this->t('Extra small'),
        'small' => $this->t('Small'),
        'medium' => $this->t('Medium'),
        'large' => $this->t('Large'),
        'xlarge' => $this->t('Extra large'),
        'xxlarge' => $this->t('2× extra large'),
      ],
      '#default_value' => $this->getSetting('derivative'),
    ];
    $element['loading'] = [
      '#type' => 'select',
      '#title' => $this->t('Loading'),
      '#options' => ['lazy' => $this->t('Lazy'), 'eager' => $this->t('Eager')],
      '#default_value' => $this->getSetting('loading'),
    ];
    $element['link_to_piwigo'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Link to the Piwigo image page when Piwigo provides one'),
      '#default_value' => (bool) $this->getSetting('link_to_piwigo'),
    ];
    return $element;
  }

  public function settingsSummary(): array {
    return [
      $this->t('Derivative: @size', ['@size' => $this->getSetting('derivative')]),
      $this->t('Loading: @loading', ['@loading' => $this->getSetting('loading')]),
    ];
  }

  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $elements = [];
    $cacheTtl = max(0, (int) $this->configFactory->get('piwigo_display.settings')->get('cache_ttl'));
    $media = $items->getEntity();
    $authenticated = $this->piwigoClient->usesAuthentication();
    $derivative = (string) $this->getSetting('derivative');

    foreach ($items as $delta => $item) {
      $id = (int) $item->value;
      if ($id <= 0) {
        continue;
      }

      try {
        $image = $this->piwigoClient->getImage($id);
        if ($image === []) {
          continue;
        }

        if ($authenticated) {
          if (!$media instanceof MediaInterface || $media->isNew() || $media->id() === NULL) {
            continue;
          }
          $url = Url::fromRoute('piwigo_display.derivative', [
            'media' => $media->id(),
            'derivative' => $derivative,
          ])->toString();
        }
        else {
          $url = $this->piwigoClient->getDerivativeUrl($image, $derivative);
          if ($url === '') {
            continue;
          }
        }

        $image_element = [
          '#theme' => 'image',
          '#uri' => $url,
          '#alt' => (string) ($image['name'] ?? ''),
          '#attributes' => [
            'loading' => (string) $this->getSetting('loading'),
            'data-piwigo-id' => (string) $id,
          ],
        ];

        $page_url = (string) ($image['page_url'] ?? '');
        if ($this->getSetting('link_to_piwigo') && filter_var($page_url, FILTER_VALIDATE_URL)) {
          $elements[$delta] = [
            '#type' => 'link',
            '#title' => $image_element,
            '#url' => Url::fromUri($page_url),
          ];
        }
        else {
          $elements[$delta] = $image_element;
        }

        $elements[$delta]['#cache'] = [
          'max-age' => $cacheTtl,
          'tags' => ['config:piwigo_display.settings'],
        ];
      }
      catch (\Throwable) {
        $elements[$delta] = [
          '#markup' => '<span class="piwigo-display-error">' . $this->t('Piwigo image @id is temporarily unavailable.', ['@id' => $id]) . '</span>',
          '#cache' => ['max-age' => 0],
        ];
      }
    }

    return $elements;
  }

}
