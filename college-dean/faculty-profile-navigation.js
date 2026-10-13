(function () {
  'use strict';
  const switcher = document.getElementById('dean-faculty-switcher');
  const select = document.getElementById('dean-faculty-select');
  if (!switcher || !select) return;
  const currentFacultyId = select.dataset.currentFacultyId;
  const $select = window.jQuery && window.jQuery.fn.select2 ? window.jQuery(select) : null;

  function switchFaculty() {
    const facultyId = select.value;
    // Restore the current name before navigating, including when the browser's
    // existing unsaved-changes prompt cancels navigation.
    select.value = currentFacultyId;
    if ($select) $select.trigger('change.select2');
    if (facultyId === currentFacultyId || !/^[1-9]\d*$/.test(facultyId)) return;
    const destination = new URL(switcher.action);
    destination.searchParams.set('faculty_id', facultyId);
    window.location.assign(destination.href);
  }

  if ($select) {
    const normalize = value => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase();
    $select.select2({
      width: '100%',
      minimumResultsForSearch: 0,
      dropdownParent: window.jQuery(switcher),
      dropdownCssClass: 'dean-faculty-dropdown',
      matcher: function (params, data) {
        const terms = normalize(params.term).trim().split(/\s+/).filter(Boolean);
        return terms.every(term => normalize(data.text).includes(term)) ? data : null;
      }
    });
    $select.next('.select2-container').find('.select2-selection')
      .attr('aria-labelledby', 'dean-faculty-switcher-label select2-dean-faculty-select-container');
    $select.on('change.deanFacultyNavigation', switchFaculty);
    $select.on('select2:open', function () {
      const search = switcher.querySelector('.select2-search__field');
      if (search) {
        search.setAttribute('aria-label', 'Search faculty by name');
        search.placeholder = 'Search faculty by name';
        search.focus();
      }
    });
  } else {
    select.addEventListener('change', switchFaculty);
  }
  switcher.addEventListener('submit', function (event) {
    event.preventDefault();
    switchFaculty();
  });
})();
