(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.piwigoDisplayLibrary = {
    attach(context) {
      once('piwigo-display-browser', '[data-piwigo-display-browser]', context).forEach((browser) => {
        const status = browser.querySelector('[data-piwigo-display-selection-status]');
        const submit = browser.querySelector('.piwigo-display-browser__add-selected');

        const updateSelectionState = () => {
          const count = browser.querySelectorAll('[data-piwigo-display-selection]:checked').length;
          browser.classList.toggle('has-selection', count > 0);

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
