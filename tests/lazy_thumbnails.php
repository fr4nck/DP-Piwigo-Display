<?php

declare(strict_types=1);

$form = file_get_contents(__DIR__ . '/../src/Form/PiwigoLibraryForm.php');
$routing = file_get_contents(__DIR__ . '/../piwigo_display.routing.yml');
$controller = @file_get_contents(__DIR__ . '/../src/Controller/ThumbnailController.php');

if ($form === false || $routing === false || $controller === false) {
  fwrite(STDERR, "Lazy-thumbnail implementation files are incomplete.\n");
  exit(1);
}

if (str_contains($form, 'getLocalThumbnailUri($image)')) {
  fwrite(STDERR, "Media Library search must not synchronously download every Piwigo thumbnail.\n");
  exit(1);
}

if (!str_contains($form, "Url::fromRoute('piwigo_display.thumbnail'")) {
  fwrite(STDERR, "Media Library cards must use the lazy thumbnail route.\n");
  exit(1);
}

if (!str_contains($routing, 'piwigo_display.thumbnail:') || !str_contains($routing, "_permission: 'create media'") || !str_contains($routing, "image_id: '\\d+'")) {
  fwrite(STDERR, "The lazy thumbnail route must exist, require media creation permission, and constrain image IDs to digits.\n");
  exit(1);
}

foreach ([
  'getLocalThumbnailUri($image)',
  'new BinaryFileResponse(',
  '$response->setPrivate();',
  '$response->setMaxAge(3600);',
  'ResponseHeaderBag::DISPOSITION_INLINE',
] as $expected) {
  if (!str_contains($controller, $expected)) {
    fwrite(STDERR, "ThumbnailController regression guard missing: {$expected}\n");
    exit(1);
  }
}

echo "Lazy Media Library thumbnail regression test passed.\n";
