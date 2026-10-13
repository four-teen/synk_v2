(function () {
  'use strict';
  const modal = document.getElementById('deanTermSettingsModal');
  const form = document.getElementById('dean-view-term-form');
  if (!modal || !form) return;
  // Keep the dialog and backdrop outside the navbar's stacking context.
  document.body.appendChild(modal);
  const year = document.getElementById('dean-view-year');
  const semester = document.getElementById('dean-view-semester');
  const currentChoice = document.getElementById('dean-view-current-choice');
  modal.addEventListener('show.bs.modal', function () {
    form.reset();
    currentChoice.disabled = true;
  });
  modal.addEventListener('shown.bs.modal', function () { year.focus(); });
  [year, semester].forEach(function (select) {
    select.addEventListener('change', function () { currentChoice.disabled = true; });
  });
  form.querySelector('.dean-view-current').addEventListener('click', function () {
    year.value = form.dataset.currentYear;
    semester.value = form.dataset.currentSemester;
    currentChoice.disabled = false;
  });
})();
