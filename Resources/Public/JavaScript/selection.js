/*
 * "Register for all webinars": ticks every bookable webinar of the list and
 * moves on to the first field of the personal details.
 */
document.addEventListener('click', (event) => {
  const button = event.target.closest('[data-gotowebinar-select-all]');
  if (!button) {
    return;
  }
  const selection = document.getElementById(button.getAttribute('aria-controls'));
  if (!selection) {
    return;
  }
  selection.querySelectorAll('input.btn-check:not(:disabled)').forEach((input) => {
    if (!input.checked) {
      input.checked = true;
      input.dispatchEvent(new Event('change', { bubbles: true }));
    }
  });

  const form = selection.closest('form');
  const next = form && Array.from(form.querySelectorAll('input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]), select, textarea'))
    .find((field) => !selection.contains(field)
      && (selection.compareDocumentPosition(field) & Node.DOCUMENT_POSITION_FOLLOWING)
      && field.offsetParent !== null);
  if (next) {
    const smooth = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    next.scrollIntoView({ behavior: smooth ? 'smooth' : 'auto', block: 'center' });
    next.focus({ preventScroll: true });
  }
});
