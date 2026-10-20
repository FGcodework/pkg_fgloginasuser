/**
 * FG Login as User - impersonation buttons.
 * Fills a dedicated hidden POST form (not nested in the list form) and submits it,
 * optionally into a new tab.
 */
(() => {
  'use strict';

  const form = document.getElementById('fgLasForm');
  const dialog = document.getElementById('fgLasDialog');

  if (!form) {
    return;
  }

  document.addEventListener('click', (event) => {
    const button = event.target.closest('.fg-las-btn');

    if (!button || button.disabled) {
      return;
    }

    event.preventDefault();

    const start = () => {
      form.elements.id.value = button.dataset.id;
      form.elements.client.value = button.dataset.client;
      form.target = button.dataset.newTab === '1' ? '_blank' : '';
      form.submit();
    };

    const confirmText = button.dataset.confirm;

    if (!confirmText) {
      start();

      return;
    }

    // Styled confirmation; plain window.confirm only when <dialog> is unavailable.
    if (dialog && typeof dialog.showModal === 'function') {
      dialog.querySelector('.fg-las-dialog-title').textContent = (dialog.dataset.title || '%s')
        .replace('%s', button.dataset.username || '');
      dialog.returnValue = '';
      dialog.addEventListener('close', () => {
        if (dialog.returnValue === 'ok') {
          start();
        }
      }, { once: true });
      dialog.showModal();
    } else if (window.confirm(confirmText)) {
      start();
    }
  });
})();
