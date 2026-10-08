(function () {
  'use strict';
  document.querySelectorAll('form').forEach(function (form) {
    const campus = form.querySelector('[data-campus-select]');
    const college = form.querySelector('[data-college-select]');
    if (!campus || !college) return;
    function updateColleges() {
      const restrict = campus.value !== '0' || campus.dataset.requireCampus === 'true';
      Array.from(college.options).forEach(function (option) {
        const unavailable = option.value !== '0' && restrict && option.dataset.campus !== campus.value;
        option.hidden = unavailable;
        option.disabled = unavailable;
      });
      if (college.selectedOptions[0]?.disabled) college.value = '0';
    }
    campus.addEventListener('change', updateColleges);
    updateColleges();
  });
}());
