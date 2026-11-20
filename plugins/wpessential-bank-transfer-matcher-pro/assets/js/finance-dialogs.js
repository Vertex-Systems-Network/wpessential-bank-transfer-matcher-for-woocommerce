/* Progressive-enhancement for explicit WPEssential finance action panels. */
(() => {
  'use strict';
  document.addEventListener('DOMContentLoaded', () => {
    if (typeof HTMLDialogElement === 'undefined' ||
        typeof HTMLDialogElement.prototype.showModal !== 'function') {
      return; // Native <details> remains fully usable without dialog support.
    }
    for (const details of document.querySelectorAll('details[data-wbm-finance-dialog]')) {
      const summary = details.querySelector(':scope > summary');
      if (!summary) continue;
      const label = (summary.textContent || '').trim();
      if (!label) continue;

      const launch = document.createElement('button');
      launch.type = 'button';
      launch.className = 'button button-secondary wbm-finance-dialog-launch';
      launch.textContent = label;
      launch.setAttribute('aria-haspopup', 'dialog');

      const dialog = document.createElement('dialog');
      dialog.className = 'wbm-finance-dialog';
      dialog.setAttribute('aria-label', label);

      const dismiss = document.createElement('button');
      dismiss.type = 'button';
      dismiss.className = 'button';
      dismiss.textContent = 'Close review';
      dismiss.addEventListener('click', () => dialog.close());

      const header = document.createElement('div');
      header.className = 'wbm-finance-dialog-head';
      const heading = document.createElement('h2');
      heading.textContent = label;
      header.append(heading, dismiss);
      dialog.append(header);

      // Move (do not clone) the original secure WordPress form. Its nonce,
      // idempotency key, source/order identity and POST semantics remain intact.
      for (const child of Array.from(details.childNodes)) {
        if (child !== summary) dialog.append(child);
      }
      details.replaceWith(launch, dialog);
      launch.addEventListener('click', () => dialog.showModal());
      dialog.addEventListener('close', () => launch.focus());
      dialog.addEventListener('click', (event) => {
        if (event.target === dialog) dialog.close();
      });
    }
  });
})();
