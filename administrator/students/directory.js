(function () {
  const config = window.studentDirectoryConfig || {};
  const apiUrl = config.apiUrl || "directory_api.php";
  const previewBaseUrl = config.previewBaseUrl || "../../student/index.php";
  const $ = window.jQuery;

  const filterForm = document.getElementById("directoryFilterForm");
  const tableElement = document.getElementById("studentDirectoryTable");
  const resultCountLabel = document.getElementById("resultCountLabel");
  const feedbackContainer = document.getElementById("directoryFeedback");
  const addStudentBtn = document.getElementById("openAddStudentBtn");
  const resetFiltersBtn = document.getElementById("resetDirectoryFilters");
  const studentModalElement = document.getElementById("studentModal");
  const studentModal = studentModalElement ? new bootstrap.Modal(studentModalElement) : null;
  const studentForm = document.getElementById("studentForm");
  const studentModalTitle = document.getElementById("studentModalTitle");
  const generateEmailBtn = document.getElementById("generateEmailBtn");
  const studentIdField = document.getElementById("student_id");
  const programIdField = document.getElementById("program_id");
  const programFilterField = document.getElementById("program_id_filter");
  const programSelectField = document.getElementById("program_select_modal");
  const yearLevelField = document.getElementById("year_level_modal");
  const studentNumberField = document.getElementById("student_number_modal");
  const lastNameField = document.getElementById("last_name_modal");
  const firstNameField = document.getElementById("first_name_modal");
  const middleNameField = document.getElementById("middle_name_modal");
  const suffixNameField = document.getElementById("suffix_name_modal");
  const emailField = document.getElementById("email_address_modal");
  const hiddenAyId = document.getElementById("ay_id_hidden");
  const hiddenSemester = document.getElementById("semester_hidden");
  const academicYearFilterField = document.getElementById("ay_id");
  const semesterFilterField = document.getElementById("semester");

  if (!filterForm || !tableElement || !$ || !$.fn || !$.fn.DataTable) {
    return;
  }

  let autoEmailEnabled = true;
  let directoryTable = null;
  let loadingData = false;

  function escapeHtml(value) {
    return String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function showFeedback(status, message) {
    if (!feedbackContainer) {
      return;
    }

    feedbackContainer.innerHTML =
      '<div class="alert alert-' + status + ' alert-dismissible mb-4" role="alert">' +
        '<div class="fw-semibold">' + escapeHtml(message) + "</div>" +
        '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>' +
      "</div>";

    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  function clearFeedback() {
    if (feedbackContainer) {
      feedbackContainer.innerHTML = "";
    }
  }

  function updateResultCount(count) {
    if (!resultCountLabel) {
      return;
    }

    resultCountLabel.textContent = Number(count || 0).toLocaleString() + " matching records";
  }

  function setLoadingState(isLoading) {
    loadingData = Boolean(isLoading);
    if (isLoading) {
      updateResultCount(0);
    }
  }

  function normalizeLookupValue(value) {
    return String(value ?? "").trim().toLowerCase();
  }

  function serializeFilters() {
    const formData = new FormData(filterForm);
    const params = new URLSearchParams();

    formData.forEach(function (value, key) {
      const normalized = String(value).trim();
      if (normalized !== "" && normalized !== "0") {
        params.set(key, normalized);
      }
    });

    return params;
  }

  function syncFiltersToUrl() {
    const params = serializeFilters();
    const nextUrl = "directory.php" + (params.toString() ? "?" + params.toString() : "");
    window.history.replaceState({}, "", nextUrl);
  }

  async function fetchJson(url, fallbackMessage, options) {
    const response = await fetch(url, options || {
      headers: { "X-Requested-With": "XMLHttpRequest" },
      cache: "no-store"
    });
    const responseText = await response.text();

    let payload = null;
    if (responseText.trim() !== "") {
      try {
        payload = JSON.parse(responseText);
      } catch (error) {
        throw new Error(responseText.trim() || fallbackMessage);
      }
    }

    if (!response.ok) {
      throw new Error((payload && payload.message) || responseText.trim() || fallbackMessage);
    }

    return payload || {};
  }

  function buildDataTableQueryString(requestPayload) {
    const params = serializeFilters();
    const orderItems = Array.isArray(requestPayload.order) ? requestPayload.order : [];
    const columnItems = Array.isArray(requestPayload.columns) ? requestPayload.columns : [];

    params.set("action", "list");
    params.set("draw", String(Number(requestPayload.draw || 0)));
    params.set("start", String(Math.max(0, Number(requestPayload.start || 0))));
    params.set("length", String(Math.max(1, Number(requestPayload.length || 25))));

    orderItems.forEach(function (orderItem, index) {
      params.set("order[" + index + "][column]", String(Math.max(0, Number(orderItem.column || 0))));
      params.set("order[" + index + "][dir]", String(orderItem.dir || "asc"));
    });

    columnItems.forEach(function (columnItem, index) {
      params.set("columns[" + index + "][data]", String(columnItem.data || ""));
      params.set("columns[" + index + "][name]", String(columnItem.name || ""));
    });

    return params.toString();
  }

  function formatUpper(value) {
    return String(value ?? "").trim().toUpperCase();
  }

  function formatLower(value) {
    return String(value ?? "").trim().toLowerCase();
  }

  function buildFullNameHtml(item) {
    const lastName = formatUpper(item.last_name);
    const firstName = formatUpper(item.first_name);
    const middleName = formatLower(item.middle_name);
    const suffixName = formatUpper(item.suffix_name);

    let html = "";
    if (lastName) {
      html += '<span class="student-name-last">' + escapeHtml(lastName) + "</span>";
    }
    if (firstName) {
      html += (html ? ", " : "") + '<span class="student-name-first">' + escapeHtml(firstName) + "</span>";
    }
    if (middleName) {
      html += ' <span class="student-name-middle">' + escapeHtml(middleName) + "</span>";
    }
    if (suffixName) {
      html += ' <span class="student-name-suffix">' + escapeHtml(suffixName) + "</span>";
    }

    return html || '<span class="text-muted">NO NAME</span>';
  }

  function initSelect2(element, options) {
    if (!$ || !$.fn || !$.fn.select2 || !element) {
      return;
    }

    const $element = $(element);
    if ($element.hasClass("select2-hidden-accessible")) {
      $element.select2("destroy");
    }

    $element.select2(options);
  }

  function refreshSelect2(element) {
    if (!$ || !element) {
      return;
    }

    const $element = $(element);
    if ($element.hasClass("select2-hidden-accessible")) {
      $element.trigger("change.select2");
    }
  }

  function initializeProgramSelects() {
    initSelect2(programFilterField, {
      width: "100%",
      allowClear: true,
      placeholder: "All programs"
    });

    initSelect2(programSelectField, {
      width: "100%",
      dropdownParent: studentModalElement ? $(studentModalElement) : $(document.body),
      placeholder: "Select program from program table"
    });
  }

  function resolveAcademicYearContext() {
    const filterValue = academicYearFilterField ? String(academicYearFilterField.value || "").trim() : "";
    return filterValue || String(config.defaultAyId || "").trim();
  }

  function resolveSemesterContext() {
    const filterValue = semesterFilterField ? String(semesterFilterField.value || "").trim() : "";
    return filterValue || String(config.defaultSemester || "").trim();
  }

  function setAcademicContext(record) {
    if (record) {
      hiddenAyId.value = String(record.ay_id || 0);
      hiddenSemester.value = String(record.semester || 0);
      return;
    }

    hiddenAyId.value = resolveAcademicYearContext();
    hiddenSemester.value = resolveSemesterContext();
  }

  function findProgramOptionById(programId) {
    if (!programSelectField || Number(programId || 0) <= 0) {
      return null;
    }

    return Array.from(programSelectField.options).find(function (option) {
      return Number(option.dataset.programId || option.value || 0) === Number(programId || 0);
    }) || null;
  }

  function findProgramOptionBySourceName(sourceName, collegeName, campusName) {
    if (!programSelectField) {
      return null;
    }

    const normalizedSourceName = normalizeLookupValue(sourceName);
    if (!normalizedSourceName) {
      return null;
    }

    const normalizedCollegeName = normalizeLookupValue(collegeName);
    const normalizedCampusName = normalizeLookupValue(campusName);
    const matches = Array.from(programSelectField.options).filter(function (option) {
      return normalizeLookupValue(option.dataset.sourceName) === normalizedSourceName;
    });

    if (!matches.length) {
      return null;
    }

    const locationMatch = matches.find(function (option) {
      const sameCollege = !normalizedCollegeName || normalizeLookupValue(option.dataset.college) === normalizedCollegeName;
      const sameCampus = !normalizedCampusName || normalizeLookupValue(option.dataset.campus) === normalizedCampusName;
      return sameCollege && sameCampus;
    });

    return locationMatch || matches[0] || null;
  }

  function removeLegacyProgramOption() {
    if (!programSelectField) {
      return;
    }

    const legacyOption = document.getElementById("studentProgramLegacyOption");
    if (legacyOption) {
      legacyOption.remove();
    }
  }

  function createLegacyProgramOption(record) {
    if (!programSelectField || !record || !record.source_program_name) {
      return;
    }

    removeLegacyProgramOption();

    const option = document.createElement("option");
    option.id = "studentProgramLegacyOption";
    option.value = "legacy-" + String(record.student_id || "record");
    option.dataset.programId = String(record.program_id || 0);
    option.dataset.sourceName = record.source_program_name || "";
    option.dataset.college = record.college_name || "";
    option.dataset.campus = record.campus_name || "";
    option.textContent = "Current record | " + String(record.source_program_name || "");
    programSelectField.appendChild(option);
    programSelectField.value = option.value;
  }

  function syncProgramSelection() {
    if (!programSelectField) {
      return;
    }

    const option = programSelectField.options[programSelectField.selectedIndex];
    programIdField.value = option ? String(option.dataset.programId || option.value || 0) : "0";
  }

  function setProgramSelection(record, allowLegacy) {
    if (!programSelectField) {
      return;
    }

    removeLegacyProgramOption();

    const recordProgramId = Number(record && record.program_id ? record.program_id : 0);
    const sourceProgramName = record ? String(record.source_program_name || "") : "";
    const collegeName = record ? String(record.college_name || "") : "";
    const campusName = record ? String(record.campus_name || "") : "";

    const option =
      findProgramOptionById(recordProgramId) ||
      findProgramOptionBySourceName(sourceProgramName, collegeName, campusName);

    if (option) {
      programSelectField.value = option.value;
      refreshSelect2(programSelectField);
      return;
    }

    if (allowLegacy && sourceProgramName) {
      createLegacyProgramOption(record);
      refreshSelect2(programSelectField);
      return;
    }

    programSelectField.value = "";
    refreshSelect2(programSelectField);
  }

  function buildInstitutionalEmail() {
    const sanitize = function (value) {
      return String(value || "")
        .normalize("NFD")
        .replace(/[\u0300-\u036f]/g, "")
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, "");
    };

    const firstToken = sanitize(firstNameField.value);
    const lastToken = sanitize(lastNameField.value);
    if (!firstToken || !lastToken) {
      return "";
    }

    const token = firstToken + lastToken;
    return token ? token + "@sksu.edu.ph" : "";
  }

  function generateInstitutionalEmail(force) {
    const nextEmail = buildInstitutionalEmail();
    if (force || autoEmailEnabled || !emailField.value.trim()) {
      emailField.value = nextEmail;
    }

    if (force) {
      autoEmailEnabled = true;
    }
  }

  function syncAutoEmailState() {
    const generatedEmail = buildInstitutionalEmail();
    const currentEmail = String(emailField.value || "").trim().toLowerCase();
    autoEmailEnabled = currentEmail === "" || (generatedEmail !== "" && currentEmail === generatedEmail.toLowerCase());
  }

  function openStudentModal(record) {
    if (!studentModal) {
      return;
    }

    studentForm.reset();
    removeLegacyProgramOption();
    programIdField.value = "0";
    autoEmailEnabled = true;

    if (record) {
      studentModalTitle.textContent = "Edit Student";
      studentIdField.value = record.student_id || 0;
      yearLevelField.value = record.year_level || "";
      studentNumberField.value = record.student_number || "";
      lastNameField.value = record.last_name || "";
      firstNameField.value = record.first_name || "";
      middleNameField.value = record.middle_name || "";
      suffixNameField.value = record.suffix_name || "";
      emailField.value = record.email_address || "";
      setAcademicContext(record);
      setProgramSelection(record, true);
    } else {
      studentModalTitle.textContent = "Add Student";
      studentIdField.value = "0";

      const currentYearFilter = document.getElementById("year_level");
      setAcademicContext(null);
      if (programFilterField && programFilterField.value) {
        setProgramSelection({ program_id: programFilterField.value }, false);
      } else if (programSelectField) {
        programSelectField.value = "";
        refreshSelect2(programSelectField);
      }
      if (currentYearFilter && currentYearFilter.value !== "0") {
        yearLevelField.value = currentYearFilter.value;
      }
    }

    syncProgramSelection();
    if (!emailField.value.trim()) {
      generateInstitutionalEmail(true);
    }
    syncAutoEmailState();
    studentModal.show();
  }

  function loadStudentForEdit(studentId) {
    return (async function () {
      try {
        const result = await fetchJson(
          apiUrl + "?action=get&student_id=" + encodeURIComponent(studentId),
          "Unable to load the student record."
        );

        if (result.status !== "success") {
          throw new Error(result.message || "Unable to load the student record.");
        }

        openStudentModal(result.record || null);
      } catch (error) {
        showFeedback("danger", error.message || "Unable to load the student record.");
      }
    })();
  }

  async function deleteStudent(studentId) {
    if (!window.confirm("Delete this student record? This action cannot be undone.")) {
      return;
    }

    try {
      const formData = new FormData();
      formData.append("student_id", studentId);

      const result = await fetchJson(apiUrl + "?action=delete", "Unable to delete the student record.", {
        method: "POST",
        body: formData,
        headers: { "X-Requested-With": "XMLHttpRequest" }
      });

      if (result.status !== "success") {
        throw new Error(result.message || "Unable to delete the student record.");
      }

      showFeedback("success", result.message || "Student record deleted.");
      reloadDirectoryTable(false);
    } catch (error) {
      showFeedback("danger", error.message || "Unable to delete the student record.");
    }
  }

  function renderActionButtons(item) {
    const returnTo = window.location.pathname + window.location.search;
    const previewParams = new URLSearchParams();
    previewParams.set("preview_student_id", String(Number(item.student_id || 0)));
    previewParams.set("return_to", returnTo);

    const previewAyId = academicYearFilterField && String(academicYearFilterField.value || "").trim() !== ""
      ? String(academicYearFilterField.value || "").trim()
      : String(config.defaultAyId || "").trim();
    const previewSemester = semesterFilterField && String(semesterFilterField.value || "").trim() !== ""
      ? String(semesterFilterField.value || "").trim()
      : "";

    if (previewAyId !== "" && previewAyId !== "0") {
      previewParams.set("subject_ay_id", previewAyId);
    }

    if (previewSemester !== "" && previewSemester !== "0") {
      previewParams.set("subject_semester", previewSemester);
    }

    const previewUrl = previewBaseUrl + "?" + previewParams.toString();

    return (
      '<div class="d-inline-flex gap-1">' +
        '<a href="' + escapeHtml(previewUrl) + '" class="btn btn-sm btn-outline-secondary" title="Preview Student Dashboard">' +
          '<i class="bx bx-show-alt"></i>' +
        "</a>" +
        '<button type="button" class="btn btn-sm btn-outline-primary btn-edit-student" data-student-id="' + Number(item.student_id || 0) + '">' +
          '<i class="bx bx-edit-alt"></i>' +
        "</button>" +
        '<button type="button" class="btn btn-sm btn-outline-danger btn-delete-student" data-student-id="' + Number(item.student_id || 0) + '">' +
          '<i class="bx bx-trash"></i>' +
        "</button>" +
      "</div>"
    );
  }

  function initializeDataTable() {
    directoryTable = $(tableElement).DataTable({
      processing: true,
      serverSide: true,
      searching: false,
      lengthChange: true,
      autoWidth: false,
      responsive: true,
      pageLength: 25,
      order: [[0, "asc"]],
      dom:
        "<'row align-items-center gy-2 mb-3'<'col-sm-6'l><'col-sm-6 text-sm-end'>>" +
        "rt" +
        "<'row align-items-center gy-2 mt-3'<'col-sm-6'i><'col-sm-6 d-flex justify-content-sm-end'p>>",
      ajax: function (requestPayload, callback) {
        setLoadingState(true);

        (async function () {
          try {
            const result = await fetchJson(
              apiUrl + "?" + buildDataTableQueryString(requestPayload),
              "Unable to load student records."
            );

            clearFeedback();
            updateResultCount(Number(result.recordsFiltered || 0));
            callback({
              draw: Number(result.draw || requestPayload.draw || 0),
              recordsTotal: Number(result.recordsTotal || 0),
              recordsFiltered: Number(result.recordsFiltered || 0),
              data: Array.isArray(result.data) ? result.data : []
            });
          } catch (error) {
            updateResultCount(0);
            showFeedback("danger", error.message || "Unable to load student records.");
            callback({
              draw: Number(requestPayload.draw || 0),
              recordsTotal: 0,
              recordsFiltered: 0,
              data: []
            });
          } finally {
            setLoadingState(false);
          }
        })();
      },
      columns: [
        {
          data: "student_number",
          name: "student_number",
          className: "student-id-cell",
          render: function (data, type) {
            const value = String(data ?? "");
            return type === "display" ? escapeHtml(value) : value;
          }
        },
        {
          data: null,
          name: "full_name",
          className: "student-name-cell",
          render: function (data, type, row) {
            if (type !== "display") {
              return [
                String(row.last_name || ""),
                String(row.first_name || ""),
                String(row.middle_name || ""),
                String(row.suffix_name || "")
              ].join(" ").trim();
            }

            return buildFullNameHtml(row);
          }
        },
        {
          data: "email_address",
          name: "email_address",
          className: "email-cell",
          render: function (data, type) {
            const value = String(data ?? "");
            return type === "display" ? escapeHtml(value) : value;
          }
        },
        {
          data: "subject_count",
          name: "subject_count",
          className: "subject-count-cell",
          render: function (data, type) {
            const value = Number(data || 0);
            if (type !== "display") {
              return value;
            }

            return '<span class="badge bg-label-primary">' + escapeHtml(String(value)) + "</span>";
          }
        },
        {
          data: null,
          name: "actions",
          orderable: false,
          searchable: false,
          className: "text-end",
          render: function (data, type, row) {
            if (type !== "display") {
              return "";
            }

            return renderActionButtons(row);
          }
        }
      ],
      language: {
        processing: "Loading student records...",
        emptyTable: "No student records matched the current filters.",
        zeroRecords: "No student records matched the current filters.",
        info: "_START_ to _END_ of _TOTAL_ students",
        infoEmpty: "0 students",
        infoFiltered: "",
        lengthMenu: "_MENU_ per page",
        paginate: {
          previous: "Prev",
          next: "Next"
        }
      }
    });
  }

  function reloadDirectoryTable(resetPaging) {
    if (!directoryTable) {
      return;
    }

    directoryTable.ajax.reload(null, resetPaging !== false);
  }

  initializeProgramSelects();
  setAcademicContext(null);
  initializeDataTable();

  if (addStudentBtn) {
    addStudentBtn.addEventListener("click", function () {
      openStudentModal(null);
    });
  }

  if (programSelectField) {
    programSelectField.addEventListener("change", syncProgramSelection);
  }

  if (generateEmailBtn) {
    generateEmailBtn.addEventListener("click", generateInstitutionalEmail);
  }

  [firstNameField, lastNameField].forEach(function (field) {
    if (!field) {
      return;
    }

    field.addEventListener("input", function () {
      generateInstitutionalEmail(false);
    });
  });

  if (emailField) {
    emailField.addEventListener("input", syncAutoEmailState);
  }

  filterForm.addEventListener("submit", function (event) {
    event.preventDefault();
    syncFiltersToUrl();
    reloadDirectoryTable(true);
  });

  if (resetFiltersBtn) {
    resetFiltersBtn.addEventListener("click", function () {
      const searchField = document.getElementById("search");
      const yearFilter = document.getElementById("year_level");

      if (searchField) {
        searchField.value = "";
      }
      if (yearFilter) {
        yearFilter.value = "0";
      }
      if (academicYearFilterField) {
        academicYearFilterField.value = "";
      }
      if (semesterFilterField) {
        semesterFilterField.value = "";
      }
      if (programFilterField) {
        programFilterField.value = "";
        refreshSelect2(programFilterField);
      }

      syncFiltersToUrl();
      reloadDirectoryTable(true);
    });
  }

  if (studentForm) {
    studentForm.addEventListener("submit", async function (event) {
      event.preventDefault();
      if (!hiddenAyId.value.trim() || !hiddenSemester.value.trim() || hiddenAyId.value === "0" || hiddenSemester.value === "0") {
        setAcademicContext(null);
      }
      syncProgramSelection();

      try {
        const formData = new FormData(studentForm);
        const result = await fetchJson(apiUrl + "?action=save", "Unable to save the student record.", {
          method: "POST",
          body: formData,
          headers: { "X-Requested-With": "XMLHttpRequest" }
        });

        if (result.status !== "success") {
          throw new Error(result.message || "Unable to save the student record.");
        }

        studentModal.hide();
        showFeedback("success", result.message || "Student record saved.");
        reloadDirectoryTable(false);
      } catch (error) {
        showFeedback("danger", error.message || "Unable to save the student record.");
      }
    });
  }

  tableElement.addEventListener("click", function (event) {
    const editButton = event.target.closest(".btn-edit-student");
    if (editButton) {
      loadStudentForEdit(editButton.dataset.studentId);
      return;
    }

    const deleteButton = event.target.closest(".btn-delete-student");
    if (deleteButton) {
      deleteStudent(deleteButton.dataset.studentId);
    }
  });
})();
