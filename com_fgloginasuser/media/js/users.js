/**
 * FG Login as User - impersonation buttons.
 * Fills a dedicated hidden POST form (not nested in the list form) and submits it,
 * optionally into a new tab. A dialog asks for confirmation (backend) and for the reason (when enabled).
 */
(() => {
  'use strict';

  const form = document.getElementById('fgLasForm');
  const dialog = document.getElementById('fgLasDialog');

  if (!form) {
    return;
  }

  const canDialog = dialog && typeof dialog.showModal === 'function';
  const reasonMode = dialog ? parseInt(dialog.dataset.reasonMode || '0', 10) : 0;
  const reasonField = document.getElementById('fgLasReason');
  const reasonSelect = document.getElementById('fgLasReasonSelect');
  const reasonLabel = document.getElementById('fgLasReasonLabel');

  // With predefined reasons the text field is a note to a preset, or the text of "Other".
  const syncReason = () => {
    if (!reasonSelect || !reasonField) {
      return;
    }

    const value = reasonSelect.value;

    reasonField.hidden = value === '';
    reasonLabel.hidden = value === '';
    reasonField.required = value === 'other';
    reasonLabel.textContent = value === 'other' ? reasonField.dataset.labelOther : reasonField.dataset.labelNote;
  };

  const currentReason = () => {
    const text = reasonField ? reasonField.value.trim() : '';

    if (!reasonSelect) {
      return text;
    }

    const value = reasonSelect.value;

    if (value === 'other') {
      return text;
    }

    if (value.startsWith('p:')) {
      return value.slice(2) + (text ? ': ' + text : '');
    }

    return '';
  };

  if (reasonSelect) {
    reasonSelect.addEventListener('change', syncReason);
  }

  document.addEventListener('click', (event) => {
    const button = event.target.closest('.fg-las-btn');

    if (!button || button.disabled) {
      return;
    }

    event.preventDefault();

    const client = button.dataset.client;
    const start = (reason) => {
      form.elements.id.value = button.dataset.id;
      form.elements.client.value = client;
      form.elements.reason.value = reason || '';
      form.target = button.dataset.newTab === '1' ? '_blank' : '';
      form.submit();
    };

    const confirmText = button.dataset.confirm;

    // Frontend without a reason question starts at once; the backend switch always asks first.
    if (!confirmText && reasonMode === 0) {
      start();

      return;
    }

    if (canDialog) {
      dialog.querySelector('.fg-las-dialog-title').textContent = (dialog.dataset['title' + (client === 'site' ? 'Site' : 'Administrator')] || '%s')
        .replace('%s', button.dataset.username || '');
      dialog.querySelector('#fgLasDialogOk').textContent = dialog.dataset['ok' + (client === 'site' ? 'Site' : 'Administrator')] || 'OK';
      dialog.querySelectorAll('.fg-las-dialog-body').forEach((p) => {
        p.hidden = p.dataset.for !== client;
      });

      if (reasonField) {
        reasonField.value = '';
      }

      if (reasonSelect) {
        reasonSelect.value = '';
        syncReason();
      }

      dialog.returnValue = '';
      dialog.addEventListener('close', () => {
        if (dialog.returnValue === 'ok') {
          start(currentReason());
        }
      }, { once: true });
      dialog.showModal();

      return;
    }

    // Old browsers without <dialog>.
    if (confirmText && !window.confirm(confirmText)) {
      return;
    }

    let reason = '';

    if (reasonMode > 0) {
      reason = window.prompt(button.dataset.username || '', '');

      if (reason === null || (reasonMode === 2 && reason.trim() === '')) {
        return;
      }
    }

    start(reason);
  });
})();
