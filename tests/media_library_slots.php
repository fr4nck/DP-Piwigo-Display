<?php

declare(strict_types=1);

$form = file_get_contents(__DIR__ . '/../src/Form/PiwigoLibraryForm.php');
$javascript = file_get_contents(__DIR__ . '/../js/piwigo-display-library.js');

if ($form === false || $javascript === false) {
  fwrite(STDERR, "Unable to read Media Library slot-limit implementation files.\n");
  exit(1);
}

$formGuards = [
  '$state = $this->getMediaLibraryState($form_state);',
  'if (!$state->hasSlotsAvailable())',
  '$available_slots = $state->getAvailableSlots();',
  'data-piwigo-display-selection-limit',
  'if ($available_slots > 0 && count($selected) > $available_slots)',
  '$this->processInputValues($selected, $form, $form_state);',
];
foreach ($formGuards as $guard) {
  if (!str_contains($form, $guard)) {
    fwrite(STDERR, "Media Library slot regression guard missing from form: {$guard}\n");
    exit(1);
  }
}

$javascriptGuards = [
  'browser.dataset.piwigoDisplaySelectionLimit',
  'const atLimit = hasFiniteLimit && count >= parsedLimit;',
  'input.disabled = !input.checked && atLimit;',
];
foreach ($javascriptGuards as $guard) {
  if (!str_contains($javascript, $guard)) {
    fwrite(STDERR, "Media Library slot regression guard missing from JavaScript: {$guard}\n");
    exit(1);
  }
}

echo "Media Library slot-limit regression test passed.\n";
