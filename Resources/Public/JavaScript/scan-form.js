/**
 * Skills Inspector: checking every skill (and optionally the external
 * SkillSpector scanner) can take a while. While the request runs, announce
 * the progress to assistive technology, mark the form busy and ignore further
 * submits. The button stays enabled so "action=scanAll" is still submitted.
 */
const forms = document.querySelectorAll('form[data-skillspector-scan-form]');

const setBusy = (form, busy) => {
  const elements = [form, ...form.querySelectorAll('button[type="submit"]')];
  elements.forEach((element) => {
    const attribute = element === form ? 'aria-busy' : 'aria-disabled';
    if (busy) {
      element.setAttribute(attribute, 'true');
    } else {
      element.removeAttribute(attribute);
    }
  });
  const progress = form.querySelector('[data-skillspector-scan-progress]');
  if (progress instanceof HTMLElement) {
    progress.hidden = !busy;
  }
};

forms.forEach((form) => {
  form.addEventListener('submit', (event) => {
    if (form.getAttribute('aria-busy') === 'true') {
      event.preventDefault();
      return;
    }
    setBusy(form, true);
  });
});

// Restored from the back/forward cache: the request has finished.
window.addEventListener('pageshow', (event) => {
  if (event.persisted) {
    forms.forEach((form) => setBusy(form, false));
  }
});
