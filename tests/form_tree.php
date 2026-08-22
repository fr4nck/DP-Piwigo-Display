<?php

declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../src/Form/PiwigoLibraryForm.php');
if ($source === false) {
  fwrite(STDERR, "Unable to read PiwigoLibraryForm.php\n");
  exit(1);
}

if (!preg_match('/\$form\[\'browser\'\]\s*=\s*\[.*?\'#tree\'\s*=>\s*TRUE/s', $source)) {
  fwrite(STDERR, "The Piwigo browser container must set #tree = TRUE so nested filter and selection values keep their form-state paths.\n");
  exit(1);
}

foreach ([
  "getValue(['browser', 'filters', 'piwigo_query'])",
  "getValue(['browser', 'filters', 'piwigo_album'])",
  "getValue(['browser', 'results'], [])",
] as $expected) {
  if (!str_contains($source, $expected)) {
    fwrite(STDERR, "Expected nested form-state access not found: {$expected}\n");
    exit(1);
  }
}

echo "Piwigo Media Library form tree regression test passed.\n";
