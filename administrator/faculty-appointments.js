(function () {
  'use strict';
  const role = document.getElementById('appointment-role');
  const program = document.getElementById('appointment-program');
  const programField = document.getElementById('appointment-program-field');
  if (!role || !program || !programField) return;
  function updateProgram() {
    const chair = role.value === 'chair';
    programField.hidden = !chair;
    program.required = chair && !program.disabled;
    if (!chair && !program.disabled) program.value = '0';
  }
  role.addEventListener('change', updateProgram);
  updateProgram();
  if (window.jQuery && jQuery.fn.select2) {
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
