(function () {
  'use strict';
  const form = document.getElementById('faculty-profile-form');
  if (!form) return;
  const birth = document.getElementById('date_of_birth');
  const age = document.getElementById('profile-age');
  const start = document.getElementById('service_start_date');
  const service = document.getElementById('length_of_service');
  const status = document.getElementById('profile-save-status');
  const button = document.getElementById('profile-save-button');
  const rank = document.getElementById('faculty_rank');
  const salary = document.getElementById('salary_grade');
  const eligibility = document.getElementById('eligibility_ids');
  const campus = document.getElementById('campus_name');
  const college = document.getElementById('college_name');
  const imageInput = document.getElementById('profile_image');
  const imagePreview = document.getElementById('profile-image-preview');
  const imageInitials = document.getElementById('profile-image-initials');
  const summaryImage = document.querySelector('#profile-summary-image img');
  const summaryInitials = document.querySelector('#profile-summary-image span');
  const imageSelection = document.getElementById('profile-image-selection');
  const savedImageUrl = imagePreview.getAttribute('src');
  let previewUrl = null;
  const collegeOptions = Array.from(college.options).filter(option => option.value !== '');
  const completionCard = document.getElementById('profile-completion');
  const completionRules = JSON.parse(completionCard.dataset.completionRules);
  const today = form.dataset.today.split('-').map(Number);
  const commitEducationEntries = [];
  let dirty = form.dataset.hasErrors === '1';
  let submitting = false;

  function completedMonths(value) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(value) || value > form.dataset.today) return null;
    const date = value.split('-').map(Number);
    return (today[0] - date[0]) * 12 + today[1] - date[1] - (today[2] < date[2] ? 1 : 0);
  }

  function updateAge() {
    const months = completedMonths(birth.value);
    age.value = months === null || months < 0 ? '' : Math.floor(months / 12) + ' years old';
  }

  function updateService() {
    const months = completedMonths(start.value);
    service.readOnly = start.value !== '';
    if (start.value === '') return;
    if (months === null || months < 0) {
      service.value = '';
      return;
    }
    const years = Math.floor(months / 12);
    const remaining = months % 12;
    const parts = [];
    if (years) parts.push(years + (years === 1 ? ' year' : ' years'));
    if (remaining) parts.push(remaining + (remaining === 1 ? ' month' : ' months'));
    service.value = parts.length ? parts.join(' and ') : 'Less than a month';
  }

  function markDirty(event) {
    dirty = true;
    status.textContent = 'Unsaved changes';
    if (event.target.classList.contains('is-invalid')) {
      event.target.classList.remove('is-invalid');
      event.target.removeAttribute('aria-invalid');
    }
    updateCompletion();
  }

  function completionValue(key) {
    return (document.getElementById(key)?.value || '').trim();
  }

  function validCompletionField(key, rule) {
    const value = completionValue(key);
    const field = document.getElementById(key);
    if (!value || !field || !field.validity.valid) return false;
    if (rule.options && !rule.options.includes(value)) return false;
    if (rule.max && Array.from(value).length > rule.max) return false;
    if (rule.date && (value < '1900-01-01' || value > form.dataset.today)) return false;
    if (rule.campus_options && !(rule.campus_options[value] || []).includes(campus.value)) return false;
    if (key === 'length_of_service' && start.value) {
      if (!start.validity.valid || start.value > form.dataset.today || start.value < '1900-01-01' || (birth.value && start.value < birth.value)) return false;
    }
    return true;
  }

  function updateCompletion() {
    let completed = 0;
    let total = 0;
    const sections = {};
    const missing = [];
    Object.entries(completionRules).forEach(function ([key, rule]) {
      if (!sections[rule.section]) sections[rule.section] = {completed: 0, total: 0};
      const skipped = rule.skip_when && completionValue(rule.skip_when) === 'Not applicable';
      const complete = !skipped && validCompletionField(key, rule);
      document.querySelector('[data-completion-item="' + key + '"]').hidden = skipped || complete;
      if (skipped) return;
      total++;
      sections[rule.section].total++;
      if (complete) {
        completed++;
        sections[rule.section].completed++;
      } else {
        missing.push(key);
      }
    });
    const percent = total ? Math.floor(completed * 100 / total) : 0;
    completionCard.classList.toggle('is-complete', percent === 100);
    document.getElementById('profile-completion-percent').textContent = percent + '%';
    document.getElementById('profile-completion-label').textContent = percent === 100 ? 'Details complete' : 'Needs attention';
    document.getElementById('profile-completion-message').textContent = percent === 100
      ? (dirty ? 'All applicable details are filled in. Save your changes.' : 'All applicable details are filled in.')
      : 'Your profile needs attention. Complete the missing details below.';
    document.getElementById('profile-completion-progress').setAttribute('aria-valuenow', percent);
    document.getElementById('profile-completion-fill').style.width = percent + '%';
    document.getElementById('profile-completion-count').textContent = completed + ' of ' + total + ' applicable details complete.';
    document.getElementById('profile-completion-remaining').textContent = '(' + missing.length + ')';
    document.getElementById('profile-completion-missing').hidden = missing.length === 0;
    Object.entries(sections).forEach(function ([section, progress]) {
      document.getElementById('profile-completion-' + section).textContent = progress.completed + '/' + progress.total;
    });
  }

  completionCard.querySelectorAll('[data-completion-target]').forEach(function (link) {
    link.addEventListener('click', function (event) {
      const field = document.getElementById(link.dataset.completionTarget);
      if (!field) return;
      event.preventDefault();
      const control = field.classList.contains('select2-hidden-accessible')
        ? field.nextElementSibling.querySelector('.select2-selection') : field;
      control.scrollIntoView({block: 'center'});
      control.focus({preventScroll: true});
    });
  });

  function updateSalary() {
    salary.value = rank.selectedOptions[0]?.dataset.salaryGrade || '';
  }

  function updateColleges(preserveSavedOption = false) {
    const selected = college.value;
    const available = collegeOptions.filter(function (option) {
      const campuses = JSON.parse(option.dataset.campuses || '[]');
      return campuses.includes(campus.value);
    });
    const placeholder = campus.value === '' ? 'Select a campus first'
      : (available.length ? 'Select college' : 'No colleges available for this campus');
    college.replaceChildren(new Option(placeholder, ''));
    available.forEach(option => college.add(option.cloneNode(true)));
    if (available.some(option => option.value === selected)) {
      college.value = selected;
    } else if (preserveSavedOption && selected !== '') {
      const previous = new Option(selected + ' (select a current option)', selected, true, true);
      college.add(previous);
    } else {
      college.value = '';
    }
    college.disabled = campus.value === '' && college.value === '';
  }

  campus.addEventListener('change', function () { updateColleges(); });
  updateColleges(true);

  if (window.jQuery && window.jQuery.fn.select2) {
    const $rank = window.jQuery(rank);
    $rank.select2({
      width: '100%',
      placeholder: 'Search faculty rank',
      allowClear: true,
      minimumResultsForSearch: 0,
      dropdownParent: window.jQuery('#employment'),
      dropdownCssClass: 'profile-rank-dropdown'
    });
    $rank.on('change', function () {
      updateSalary();
      markDirty({target: rank});
    });
    $rank.on('select2:open', function () {
      const search = document.querySelector('.profile-rank-dropdown .select2-search__field');
      if (search) {
        search.setAttribute('aria-label', 'Search faculty rank');
        search.focus();
      }
    });
    const $eligibility = window.jQuery(eligibility);
    $eligibility.select2({
      width: '100%',
      placeholder: 'Search and select eligibility or licenses',
      allowClear: true,
      closeOnSelect: false,
      dropdownParent: window.jQuery('#employment'),
      dropdownCssClass: 'profile-eligibility-dropdown',
      matcher: function (params, data) {
        const term = (params.term || '').trim().toLowerCase();
        if (!term) return data;
        if (data.children) {
          const children = data.children.filter(function (child) {
            const keywords = [child.text, data.text, child.element?.dataset.searchTerms || ''].join(' ').toLowerCase();
            return term.split(/\s+/).every(word => keywords.includes(word));
          });
          return children.length ? Object.assign({}, data, {children: children}) : null;
        }
        const keywords = [data.text, data.element?.dataset.searchTerms || ''].join(' ').toLowerCase();
        return term.split(/\s+/).every(word => keywords.includes(word)) ? data : null;
      }
    });
    $eligibility.next('.select2-container').find('.select2-search__field')
      .attr('aria-label', 'Search eligibility or professional licenses')
      .attr('aria-describedby', 'eligibility-help');
    $eligibility.on('change', function () {
      markDirty({target: eligibility});
    });

    document.querySelectorAll('input[data-education-type]').forEach(function (input) {
      // Keep ordinary text inputs usable if Select2 does not load.
      const select = document.createElement('select');
      ['id', 'name', 'aria-describedby', 'aria-invalid', 'data-education-type', 'data-field-label'].forEach(function (name) {
        if (input.hasAttribute(name)) select.setAttribute(name, input.getAttribute(name));
      });
      select.className = 'form-select profile-education-select' + (input.classList.contains('is-invalid') ? ' is-invalid' : '');
      select.required = input.required;
      select.add(new Option('', '', !input.value, !input.value));
      if (input.value) select.add(new Option(input.value, input.value, true, true));
      const maxLength = input.maxLength;
      input.replaceWith(select);
      const $select = window.jQuery(select);
      let pendingEntry = '';
      let choosingResult = false;

      function normalizeEntry(value) { return value.replace(/\s+/g, ' ').trim(); }
      function discardOnEscape(event) {
        // Capture before Select2 handles Escape and closes the dropdown.
        if (event.key === 'Escape') pendingEntry = '';
      }
      function commitEntry() {
        if (!pendingEntry || choosingResult) return;
        const value = pendingEntry;
        pendingEntry = '';
        const existing = Array.from(select.options).find(option => normalizeEntry(option.value).toLowerCase() === value.toLowerCase());
        if (existing) {
          select.value = existing.value;
        } else {
          select.add(new Option(value, value, true, true));
        }
        $select.trigger('change');
      }
      commitEducationEntries.push(commitEntry);
      $select.select2({
        width: '100%',
        placeholder: 'Search or type ' + select.dataset.fieldLabel.toLowerCase(),
        allowClear: true,
        tags: true,
        dropdownParent: $select.closest('.profile-education-level'),
        dropdownCssClass: 'profile-education-dropdown',
        ajax: {
          url: form.dataset.educationUrl || 'education-options.php',
          dataType: 'json',
          delay: 200,
          cache: false,
          data: function (params) {
            return {type: select.dataset.educationType, q: params.term || '', page: params.page || 1};
          }
        },
        createTag: function (params) {
          const value = normalizeEntry(params.term || '');
          if (!value || Array.from(value).length > maxLength) return null;
          return {id: value, text: value, newTag: true};
        },
        templateResult: function (item) {
          return item.newTag ? 'Add new: ' + item.text : item.text;
        }
      });
      $select.next('.select2-container').find('.select2-selection')
        .removeAttr('aria-labelledby')
        .attr('aria-label', select.dataset.fieldLabel)
        .attr('aria-describedby', select.getAttribute('aria-describedby') || '');
      $select.on('change', function () { markDirty({target: select}); });
      $select.on('select2:open', function () {
        pendingEntry = '';
        choosingResult = false;
        const $search = window.jQuery('.profile-education-dropdown .select2-search__field');
        $search.attr('aria-label', 'Search ' + select.dataset.fieldLabel.toLowerCase())
          .attr('maxlength', maxLength).off('.educationEntry')
          .on('input.educationEntry', function () {
            pendingEntry = normalizeEntry(this.value);
            markDirty({target: select});
          });
        if ($search[0]) {
          $search[0].removeEventListener('keydown', discardOnEscape, true);
          $search[0].addEventListener('keydown', discardOnEscape, true);
        }
        $search.trigger('focus');
      });
      $select.on('select2:selecting select2:clearing', function () { choosingResult = true; });
      $select.on('select2:select select2:clear', function () { pendingEntry = ''; choosingResult = false; });
      // Keep newly typed text when moving to another field or clicking Save.
      $select.on('select2:closing', commitEntry);
      $select.on('select2:close', function () { pendingEntry = ''; choosingResult = false; });
    });
  } else {
    rank.addEventListener('change', updateSalary);
  }

  birth.addEventListener('input', updateAge);
  function showImage(url) {
    [imagePreview, summaryImage].forEach(function (image) {
      image.hidden = !url;
      if (url) image.src = url;
      else image.removeAttribute('src');
    });
    imageInitials.hidden = summaryInitials.hidden = Boolean(url);
  }
  imageInput.addEventListener('change', function () {
    if (previewUrl) URL.revokeObjectURL(previewUrl);
    previewUrl = null;
    imageInput.setCustomValidity('');
    const file = imageInput.files[0];
    let error = '';
    if (file && !['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
      error = 'Choose a JPG, PNG, or WebP image.';
    } else if (file && file.size > 5 * 1024 * 1024) {
      error = 'Choose a profile image no larger than 5 MB.';
    }
    imageInput.setCustomValidity(error);
    imageSelection.textContent = error || (file ? 'New image selected. Save your profile to apply it.' : '');
    if (file && !error) previewUrl = URL.createObjectURL(file);
    showImage(previewUrl || savedImageUrl);
  });
  start.addEventListener('input', updateService);
  form.addEventListener('input', markDirty);
  form.addEventListener('change', markDirty);
  form.addEventListener('submit', function () {
    commitEducationEntries.forEach(commit => commit());
    submitting = true;
    button.disabled = true;
    button.textContent = 'Saving...';
    status.textContent = 'Saving your profile';
  });
  window.addEventListener('beforeunload', function (event) {
    if (!dirty || submitting) return;
    event.preventDefault();
    event.returnValue = '';
  });
  window.addEventListener('pageshow', function () {
    submitting = false;
    button.disabled = false;
    button.textContent = 'Save profile';
  });
  updateAge();
  updateService();
  updateSalary();
  updateCompletion();
  if (dirty) {
    status.textContent = 'Changes have not been saved';
    document.getElementById('profile-errors')?.focus();
  }
})();
