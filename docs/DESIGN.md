# Piwigo Display — direction UI/UX Drupal

This document records the visual direction used by the Drupal implementation of Piwigo Display.

## Principles

Piwigo Display must feel native to Drupal while providing a modern photo-oriented workflow. The module must not ship a second administration theme or imitate another platform pixel-for-pixel.

The visual hierarchy follows these references:

- Fluent 2 for desktop density, interaction states and functional layering;
- Material Design 3 for semantic design tokens and surface hierarchy;
- restrained translucent depth for toolbars and floating functional layers only.

The photos remain the dominant content.

## Semantic tokens

The Media Library browser uses a small semantic token layer instead of scattered hard-coded colors:

- `--pd-surface`
- `--pd-surface-low`
- `--pd-surface-high`
- `--pd-on-surface`
- `--pd-on-surface-variant`
- `--pd-primary`
- `--pd-primary-container`
- `--pd-outline`
- `--pd-outline-strong`
- `--pd-focus`

Tokens first reuse known Drupal/Gin CSS variables when present and otherwise fall back to browser system colors. This keeps Piwigo Display compatible with light and dark administration themes without coupling the module to Gin.

## Media Library browser

The Piwigo source is presented as a visual photo browser rather than a data table:

1. compact header and explanation;
2. sticky search/album toolbar;
3. responsive photo grid;
4. native Drupal checkboxes for selection;
5. explicit selected, hover and keyboard-focus states;
6. sticky selection/action layer;
7. clear empty and first-use states.

The module keeps Drupal Form API controls and Media Library workflows intact. Styling enhances them rather than replacing their behavior.

## Accessibility

Every design change must preserve or improve:

- visible keyboard focus;
- native form semantics;
- text labels for selection controls;
- information that does not depend on color alone;
- forced-colors compatibility;
- reduced-motion support;
- readable responsive layouts.

## Responsive behavior

Desktop density is the default. Container queries reduce the filter toolbar to one column when the Media Library pane becomes narrow. The photo grid adapts independently to the available pane width.

## Depth and transparency

Blur/transparency is restricted to functional layers such as the search toolbar and selection actions. Photo cards, forms and metadata remain opaque enough to preserve readability.

## Future work

The same token layer should progressively be reused for:

- the module configuration screens;
- image detail/metadata panels;
- pagination and sorting controls;
- optional list/grid density controls;
- future crop/focal-point tooling.

Changes should continue to favor central reusable components over screen-specific styling.
