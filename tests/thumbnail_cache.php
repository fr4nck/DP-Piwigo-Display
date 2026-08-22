<?php

declare(strict_types=1);

$manager = file_get_contents(__DIR__ . '/../src/Service/ThumbnailManager.php');
if ($manager === false) {
  fwrite(STDERR, "Unable to read ThumbnailManager.php\n");
  exit(1);
}

$required = [
  'private const MAX_AGE = 3600;',
  "substr(hash('sha256', $url), 0, 16)",
  '$id . \'-\' . $fingerprint',
  '$cached && $this->isFresh($real_path)',
  '$fallback = $cached ? $destination : NULL;',
  'return $fallback;',
  'filemtime($realPath)',
  'time() - self::MAX_AGE',
];

foreach ($required as $expected) {
  if (!str_contains($manager, $expected)) {
    fwrite(STDERR, "Thumbnail cache regression guard missing: {$expected}\n");
    exit(1);
  }
}

if (str_contains($manager, "$id . '.' . $extension")) {
  fwrite(STDERR, "Thumbnail cache filenames must not be keyed by Piwigo ID alone.\n");
  exit(1);
}

echo "Piwigo thumbnail cache regression test passed.\n";
