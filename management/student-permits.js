'use strict';
(() => {
  const form = document.getElementById('management-permit-form');
  if (!form) return;
  const { program_id: course, year_level: year, section_id: section, exam_term: term } = form.elements;
  const preview = document.getElementById('management-permit-preview');
  const print = document.getElementById('management-permit-print');
  const generate = document.getElementById('management-permit-generate');
  const staleMessage = document.getElementById('management-permit-stale');
  const years = [...year.options].slice(1);
  const sections = [...section.options].slice(1);
  const key = () => [course.value, year.value, section.value, term.value].join(':');
  const previewKey = key();

  function fill(select, options) {
    const selected = select.value;
    select.replaceChildren(select.options[0], ...options);
    select.value = options.some(option => option.value === selected) ? selected : '';
    select.disabled = !options.length;
  }

  function updateChoices() {
    const courseSections = sections.filter(option => option.dataset.program === course.value);
    const availableYears = new Set(courseSections.map(option => option.dataset.year));
    fill(year, years.filter(option => availableYears.has(option.value)));
    fill(section, courseSections.filter(option => option.dataset.year === year.value));
    generate.disabled = !section.value;
    const stale = Boolean(preview) && key() !== previewKey;
    if (preview) preview.hidden = stale;
    print.disabled = !preview || stale;
    staleMessage.hidden = !stale;
  }

  form.addEventListener('change', updateChoices);
  print.addEventListener('click', () => { if (!print.disabled) window.print(); });
  updateChoices();
})();
