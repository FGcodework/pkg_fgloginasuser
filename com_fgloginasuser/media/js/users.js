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

  // Browsers without <dialog>.showModal get the very same dialog (reason list, note, identity fields),
  // opened as a fixed overlay by the code below, so the options never differ from the native dialog.
  const native = !!dialog && typeof dialog.showModal === 'function';
  const canDialog = !!dialog;
  const reasonMode = dialog ? parseInt(dialog.dataset.reasonMode || '0', 10) : 0;
  const reasonField = document.getElementById('fgLasReason');
  const reasonSelect = document.getElementById('fgLasReasonSelect');
  const reasonLabel = document.getElementById('fgLasReasonLabel');
  const stepOn = dialog ? dialog.dataset.stepup === '1' : false;
  const stepPw = document.getElementById('fgLasStepPw');
  const stepCode = document.getElementById('fgLasStepCode');

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

  const openDialog = () => {
    if (native) {
      dialog.showModal();

      return;
    }

    dialog.classList.add('fg-las-dialog-fallback');
    dialog.setAttribute('open', '');
    const first = dialog.querySelector('select, textarea, input, button');

    if (first) {
      first.focus();
    }
  };

  if (dialog && !native) {
    const closeFallback = (value) => {
      dialog.returnValue = value;
      dialog.removeAttribute('open');
      dialog.dispatchEvent(new Event('close'));
    };

    dialog.querySelector('form').addEventListener('submit', (event) => event.preventDefault());
    dialog.querySelectorAll('button[type="submit"]').forEach((btn) => {
      btn.addEventListener('click', (event) => {
        event.preventDefault();

        if (btn.value === 'ok' && !btn.form.checkValidity()) {
          btn.form.reportValidity();

          return;
        }

        closeFallback(btn.value);
      });
    });
    dialog.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        closeFallback('cancel');
      }
    });
  }

  document.addEventListener('click', (event) => {
    const button = event.target.closest('.fg-las-btn');

    if (!button || button.disabled) {
      return;
    }

    event.preventDefault();

    const client = button.dataset.client;
    const start = (reason) => {
      form.elements.stepup_password.value = stepPw ? stepPw.value : '';
      form.elements.stepup_code.value = stepCode ? stepCode.value.trim() : '';
      form.elements.id.value = button.dataset.id;
      form.elements.client.value = client;
      form.elements.reason.value = reason || '';
      form.target = button.dataset.newTab === '1' ? '_blank' : '';
      form.submit();
      // The secrets are only needed for the submit itself.
      setTimeout(() => {
        form.elements.stepup_password.value = '';
        form.elements.stepup_code.value = '';
      }, 1000);
    };

    const confirmText = button.dataset.confirm;

    // Frontend without a reason question starts at once; the backend switch always asks first.
    if (!confirmText && reasonMode === 0 && !stepOn) {
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

      [stepPw, stepCode].forEach((input) => {
        if (input) {
          input.value = '';
        }
      });

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
      openDialog();

      return;
    }

    // Last resort, only if the dialog markup is missing altogether.
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
