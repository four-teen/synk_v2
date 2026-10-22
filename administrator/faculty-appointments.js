(function () {
  'use strict';
  const filters = document.getElementById('appointment-filters');
  if (filters) {
    filters.querySelectorAll('select').forEach(select => {
      select.addEventListener('change', () => filters.requestSubmit());
    });
    const view = document.getElementById('appointment-view');
    if (view) view.hidden = true;
  }
  const role = document.getElementById('appointment-role');
  const program = document.getElementById('appointment-program');
  const programField = document.getElementById('appointment-program-field');
  if (!role || !program || !programField) return;
  const programLocked = program.disabled;
  function updateProgram() {
    const chair = role.value === 'chair';
    programField.hidden = !chair;
    program.disabled = programLocked || !chair;
    program.required = chair && !programLocked;
  }
  role.addEventListener('change', updateProgram);
  updateProgram();
  if (window.jQuery && jQuery.fn.select2) {
    jQuery(program).select2({
      width: '100%', placeholder: 'Choose one or more programs', closeOnSelect: false
    });
    const normalize = value => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase();
    jQuery('#appointment-faculty').select2({
      width: '100%', placeholder: 'Search faculty name or ID',
      matcher: function (params, data) {
        const terms = normalize(params.term).trim().split(/\s+/).filter(Boolean);
        return terms.every(term => normalize(data.text).includes(term)) ? data : null;
      }
    });
  }
})();
