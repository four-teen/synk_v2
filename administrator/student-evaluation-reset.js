(function () {
  'use strict';
  const campus = document.getElementById('resetCampus'), college = document.getElementById('resetCollege');
  if (!campus || !college) return;
  const options = [...college.options].slice(1).map(option => option.cloneNode(true));
  function colleges() {
    const selected = college.value;
    college.replaceChildren(new Option('Select college', ''));
    options.filter(option => option.dataset.campus === campus.value).forEach(option => college.append(option.cloneNode(true)));
    college.value = [...college.options].some(option => option.value === selected) ? selected : '';
    college.disabled = !campus.value;
  }
  colleges();
  campus.addEventListener('change', colleges);
  // A changed filter invalidates the visible review immediately. Server-side
  // confirmation also binds the exact scope and fingerprints all response rows.
  document.getElementById('evaluationResetFilters').addEventListener('change', () => {
    document.getElementById('evaluationResetReview')?.setAttribute('hidden', '');
    document.querySelector('[data-reset-review]')?.setAttribute('disabled', '');
    const modal = document.getElementById('evaluationResetModal');
    if (modal) { bootstrap.Modal.getInstance(modal)?.hide(); document.getElementById('resetDelete').disabled = true; }
  });
  const form = document.getElementById('evaluationResetConfirm');
  if (form) form.addEventListener('submit', () => { document.getElementById('resetDelete').disabled = true; document.getElementById('resetDelete').textContent = 'Deleting...'; });
})();
