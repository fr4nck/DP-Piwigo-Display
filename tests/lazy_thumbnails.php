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

if (!str_contains($routing, 'piwigo_display.thumbnail:') || !str_contains($routing, "_permission: 'create media'")) {
  fwrite(STDERR, "The lazy thumbnail route must exist and require media creation permission.\n");
  exit(1);
}

if (!str_contains($controller, 'getLocalThumbnailUri($image)')) {
  fwrite(STDERR, "ThumbnailController must resolve/cache one requested thumbnail at a time.\n");
  exit(1);
}

echo "Lazy Media Library thumbnail regression test passed.\n";
