<?php

declare(strict_types=1);

$client = file_get_contents(__DIR__ . '/../src/Service/PiwigoClient.php');
$controller = file_get_contents(__DIR__ . '/../src/Controller/ThumbnailController.php');
$source = file_get_contents(__DIR__ . '/../src/Plugin/media/Source/PiwigoImage.php');
$install = file_get_contents(__DIR__ . '/../piwigo_display.install');

foreach (compact('client', 'controller', 'source', 'install') as $name => $contents) {
  if ($contents === false) {
    fwrite(STDERR, "Unable to read protected-thumbnail regression input: {$name}\n");
    exit(1);
  }
}

$required = [
  [$client, 'public function usesAuthentication(): bool'],
  [$controller, 'if ($this->piwigoClient->usesAuthentication())'],
  [$controller, 'return $this->authenticatedThumbnailResponse($image);'],
  [$controller, '$data = $this->piwigoClient->fetchAsset($url);'],
  [$controller, 'new Response($data, 200'],
  [$source, "if (\$attribute_name === 'thumbnail_uri' && \$this->piwigoClient->usesAuthentication())"],
  [$source, "return parent::getMetadata(\$media, 'thumbnail_uri');"],
  [$install, 'function piwigo_display_update_10002(): string'],
  [$install, "\$directory = 'public://piwigo_display/thumbnails';"],
  [$install, "\$file_system->deleteRecursive(\$directory);"],
];

foreach ($required as [$haystack, $needle]) {
  if (!str_contains($haystack, $needle)) {
    fwrite(STDERR, "Protected-thumbnail regression guard missing: {$needle}\n");
    exit(1);
  }
}

$authBranch = strpos($controller, 'if ($this->piwigoClient->usesAuthentication())');
$publicCache = strpos($controller, '$this->thumbnailManager->getLocalThumbnailUri($image)');
if ($authBranch === false || $publicCache === false || $authBranch > $publicCache) {
  fwrite(STDERR, "Authenticated thumbnails must bypass the public thumbnail cache before it is used.\n");
  exit(1);
}

if (str_contains($controller, 'temporary://') || str_contains($source, 'temporary://')) {
  fwrite(STDERR, "temporary:// must not be used as a confidentiality boundary for authenticated Piwigo thumbnails.\n");
  exit(1);
}

echo "Protected Piwigo thumbnail regression test passed.\n";
