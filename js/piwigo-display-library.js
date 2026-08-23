(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.piwigoDisplayLibrary = {
    attach(context) {
      once('piwigo-display-browser', '[data-piwigo-display-browser]', context).forEach((browser) => {
        const status = browser.querySelector('[data-piwigo-display-selection-status]');
        const submit = browser.querySelector('.piwigo-display-browser__add-selected');
        const selections = Array.from(browser.querySelectorAll('[data-piwigo-display-selection]'));
        const parsedLimit = Number.parseInt(browser.dataset.piwigoDisplaySelectionLimit || '-1', 10);
        const hasFiniteLimit = Number.isInteger(parsedLimit) && parsedLimit > 0;

        const updateSelectionState = () => {
          const count = selections.filter((input) => input.checked).length;
          const atLimit = hasFiniteLimit && count >= parsedLimit;
          browser.classList.toggle('has-selection', count > 0);
          browser.classList.toggle('is-at-selection-limit', atLimit);

          selections.forEach((input) => {
            input.disabled = !input.checked && atLimit;
          });

          if (status) {
            status.textContent = Drupal.formatPlural(count, '1 image selected', '@count images selected');
          }
          if (submit) {
            submit.disabled = count === 0;
          }
        };

        browser.addEventListener('change', (event) => {
          if (event.target instanceof HTMLInputElement && event.target.matches('[data-piwigo-display-selection]')) {
            updateSelectionState();
          }
        });

        updateSelectionState();
      });
    },
  };
})(Drupal, once);
