const chairFacultyConfig = JSON.parse(document.getElementById("chairFacultyConfig").textContent);
const CSRF_TOKEN = chairFacultyConfig.csrf_token;
let facultyCanManage = chairFacultyConfig.can_manage;
let facultySaving = false;
const facultyListState = {
  page: 1,
  pageSize: 20,
  total: 0,
  loadedCount: 0,
  hasMore: true,
  loading: false,
  search: "",
  debounceTimer: null,
  currentRequest: null,
  observer: null,
  requestToken: 0,
  termText: $("#facultyTermText").text().trim(),
  termAyId: chairFacultyConfig.ay_id,
  termSemester: chairFacultyConfig.semester
};

const addFacultyModalElement = document.getElementById("addFacultyModal");
const designationModalElement = document.getElementById("designationModal");
const classificationModalElement = document.getElementById("classificationModal");
const addFacultyModal = new bootstrap.Modal(addFacultyModalElement, {
  backdrop: "static",
  keyboard: false
});
const designationModal = new bootstrap.Modal(designationModalElement, {
  backdrop: "static",
  keyboard: false
});
const classificationModal = new bootstrap.Modal(classificationModalElement, {
  backdrop: "static",
  keyboard: false
});

$(document).ready(function () {
  $("#faculty_id").select2({
    dropdownParent: $("#addFacultyModal .modal-body"),
    width: "100%",
    placeholder: "Select Faculty...",
    allowClear: true
  });

  $("#designation_id").select2({
    dropdownParent: $("#designationModal .modal-body"),
    width: "100%",
    placeholder: "No designation",
    allowClear: true
  });

  $("#btnAddFaculty, #btnCopyPreviousFaculty").prop("disabled", !facultyCanManage);
  loadFacultyDropdown();
  initializeFacultyScroll();
  resetFacultyList();

  $("#facultySearch").on("input", function () {
    const nextSearch = $.trim($(this).val());

    clearTimeout(facultyListState.debounceTimer);
    facultyListState.debounceTimer = window.setTimeout(function () {
      if (nextSearch === facultyListState.search) {
        return;
      }

      facultyListState.search = nextSearch;
      resetFacultyList();
    }, 250);
  });

  $("#addFacultyModal").on("keydown", function (e) {
    if (e.key !== "Enter") {
      return;
    }

    if ($(".select2-container--open").length) {
      return;
    }

    e.preventDefault();
    $("#addFacultyForm").trigger("submit");
  });

  $("#designationModal").on("keydown", function (e) {
    if (e.key !== "Enter") {
      return;
    }

    if ($(".select2-container--open").length) {
      return;
    }

    e.preventDefault();
    $("#designationForm").trigger("submit");
  });

  $("#designationModal").on("hidden.bs.modal", function () {
    resetDesignationForm();
  });

  $("#classificationModal").on("keydown", function (e) {
    if (e.key !== "Enter") {
      return;
    }

    e.preventDefault();
    $("#classificationForm").trigger("submit");
  });

  $("#classificationModal").on("hidden.bs.modal", function () {
    resetClassificationForm();
  });

  $("#btnAddFaculty").on("click", function () {
    const collegeId = Number($("#college_id").val() || 0);

    if (!collegeId) {
      Swal.fire("No College Selected", "Please select a college first.", "warning");
      return;
    }

    $("#faculty_id").val(null).trigger("change");
    $("#add_employment_classification").val("");
    addFacultyModal.show();
  });

  $("#addFacultyForm").on("submit", function (e) {
    e.preventDefault();
    if (!facultyCanManage || facultySaving) return;

    const collegeId = Number($("#college_id").val() || 0);
    const facultyId = Number($("#faculty_id").val() || 0);
    const employmentClassification = String($("#add_employment_classification").val() || "").trim();

    if (!collegeId || !facultyId || employmentClassification === "") {
      Swal.fire({
        icon: "warning",
        title: "Missing Data",
        text: "Please select faculty and employment classification to assign."
      });
      return;
    }

    facultySaving = true;
    $("button[form='addFacultyForm']").prop("disabled", true);
    $.ajax({
      url: "../backend/query_program_chair_faculty.php",
      type: "POST",
      dataType: "json",
      data: buildRequestData({
        save_assignment: 1,
        college_id: collegeId,
        faculty_id: facultyId,
        employment_classification: employmentClassification
      })
    }).done(function (res) {
      if (res.status === "inserted" || res.status === "reactivated") {
        const viewportState = captureFacultyViewportState();

        Swal.fire({
          icon: "success",
          title: "Faculty Assigned",
          text: "Faculty added to the current college list.",
          timer: 1300,
          timerProgressBar: true,
          showConfirmButton: false
        });

        $("#addFacultyForm")[0].reset();
        $("#faculty_id").val(null).trigger("change");
        $("#add_employment_classification").val("");
        hideModalAndRefresh(addFacultyModalElement, addFacultyModal, viewportState);
        return;
      }

      if (res.status === "duplicate") {
        Swal.fire("Already Assigned", "This faculty is already assigned to this college.", "info");
        return;
      }

      if (res.status === "invalid_classification") {
        Swal.fire("Invalid Classification", "Please select a valid employment classification.", "warning");
        return;
      }

      if (res.status === "schema_update_required") {
        Swal.fire("Schema Update Required", "Employment classification support is not ready in this database yet.", "warning");
        return;
      }

      Swal.fire("Error", "Something went wrong while saving the assignment.", "error");
    }).fail(function (xhr) {
      Swal.fire("Server Error", extractErrorMessage(xhr, "Unable to connect."), "error");
    }).always(function () {
      facultySaving = false;
      $("button[form='addFacultyForm']").prop("disabled", false);
    });
  });

  $(document).on("click", ".btn-edit-designation", function () {
    openDesignationModal($(this));
  });

  $(document).on("click", ".btn-edit-classification", function () {
    openClassificationModal($(this));
  });

  $("#designationForm").on("submit", function (e) {
    e.preventDefault();
    if ($("#designation_id").prop("disabled")) return;

    const collegeId = Number($("#designation_college_id").val() || $("#college_id").val() || 0);
    const facultyId = Number($("#designation_faculty_id").val() || 0);
    const designationId = Number($("#designation_id").val() || 0);

    if (!collegeId || !facultyId) {
      Swal.fire({
        icon: "warning",
        title: "Missing Data",
        text: "Select a faculty member before saving."
      });
      return;
    }

    $("#designation_id, button[form='designationForm']").prop("disabled", true);
    $.ajax({
      url: "../backend/query_program_chair_faculty.php",
      type: "POST",
      dataType: "json",
      data: buildRequestData({
        update_faculty_designation: 1,
        college_id: collegeId,
        faculty_id: facultyId,
        designation_id: designationId,
        ay_id: facultyListState.termAyId,
        semester: facultyListState.termSemester
      })
    }).done(function (res) {
      if (res && res.status === "updated") {
        Swal.fire({
          icon: "success",
          title: designationId ? "Designation Updated" : "Designation Removed",
          text: "Saved for the current semester. Previous semesters are unchanged.",
          timer: 1300,
          timerProgressBar: true,
          showConfirmButton: false
        });
        applyFacultyDesignationUpdate(facultyId, res);
        designationModal.hide();
        return;
      }

      if (res && res.status === "invalid_designation") {
        Swal.fire("Invalid Designation", "Please select an active designation.", "warning");
        return;
      }

      if (res && res.status === "schema_update_required") {
        Swal.fire("Schema Update Required", "Designation support is not ready in this database yet.", "warning");
        return;
      }

      Swal.fire("Error", "Unable to update the faculty designation.", "error");
    }).fail(function (xhr) {
      Swal.fire("Error", extractErrorMessage(xhr, "Unable to update the faculty designation."), "error");
    }).always(function () {
      $("#designation_id, button[form='designationForm']").prop("disabled", false);
    });
  });

  $("#classificationForm").on("submit", function (e) {
    e.preventDefault();

    const collegeId = Number($("#classification_college_id").val() || $("#college_id").val() || 0);
    const facultyId = Number($("#classification_faculty_id").val() || 0);
    const employmentClassification = String($("#employment_classification").val() || "").trim();

    if (!collegeId || !facultyId || employmentClassification === "") {
      Swal.fire({
        icon: "warning",
        title: "Missing Data",
        text: "Please choose an employment classification before saving."
      });
      return;
    }

    $.ajax({
      url: "../backend/query_program_chair_faculty.php",
      type: "POST",
      dataType: "json",
      data: buildRequestData({
        update_employment_classification: 1,
        college_id: collegeId,
        faculty_id: facultyId,
        employment_classification: employmentClassification
      })
    }).done(function (res) {
      if (res && res.status === "updated") {
        Swal.fire({
          icon: "success",
          title: "Classification Updated",
          text: "Faculty employment classification has been updated successfully.",
          timer: 1300,
          timerProgressBar: true,
          showConfirmButton: false
        });
        applyFacultyClassificationUpdate(facultyId, res);
        classificationModal.hide();
        return;
      }

      if (res && res.status === "invalid_classification") {
        Swal.fire("Invalid Classification", "Please select a valid employment classification.", "warning");
        return;
      }

      if (res && res.status === "schema_update_required") {
        Swal.fire("Schema Update Required", "Employment classification support is not ready in this database yet.", "warning");
        return;
      }

      Swal.fire("Error", "Unable to update the employment classification.", "error");
    }).fail(function (xhr) {
      Swal.fire("Error", extractErrorMessage(xhr, "Unable to update the employment classification."), "error");
    });
  });

  $(document).on("click", ".btn-remove-assignment", function () {
    const collegeFacultyId = Number($(this).data("id") || 0);

    if (!collegeFacultyId) {
      return;
    }

    Swal.fire({
      title: "Remove from this list?",
      text: "This removes the current college-list entry. The faculty record and scheduled workloads will be kept.",
      icon: "warning",
      showCancelButton: true,
      confirmButtonText: "Yes, remove",
      cancelButtonText: "Cancel"
    }).then((result) => {
      if (!result.isConfirmed) {
        return;
      }

      $.ajax({
        url: "../backend/query_program_chair_faculty.php",
        type: "POST",
        dataType: "json",
        data: buildRequestData({
          remove_assignment: 1,
          college_faculty_id: collegeFacultyId,
          ay_id: facultyListState.termAyId,
          semester: facultyListState.termSemester
        })
      }).done(function (res) {
        if (res.status === "removed") {
          Swal.fire({
            icon: "success",
            title: "Removed",
            text: "Faculty removed from this list.",
            timer: 1300,
            showConfirmButton: false
          });
          refreshFacultyData({ preserveScroll: true });
          return;
        }

        Swal.fire("Error", "Unable to remove assignment.", "error");
      }).fail(function (xhr) {
        Swal.fire("Error", extractErrorMessage(xhr, "Unable to connect to the server."), "error");
      });
    });
  });

});

function buildRequestData(payload) {
  return Object.assign({
    csrf_token: CSRF_TOKEN,
    ay_id: facultyListState.termAyId,
    semester: facultyListState.termSemester
  }, payload);
}

function extractErrorMessage(xhr, fallbackMessage) {
  const response = xhr && xhr.responseJSON ? xhr.responseJSON : null;

  if (response && typeof response.message === "string" && response.message.trim() !== "") {
    return response.message;
  }

  if (response && response.status === "unauthorized") {
    return "Your Program Chair session has expired. Please sign in again.";
  }

  if (response && response.status === "forbidden") {
    return "You no longer have access to manage this college.";
  }

  return fallbackMessage;
}

function resetDesignationForm() {
  $("#designationForm")[0].reset();
  $("#designation_faculty_id").val("");
  $("#designation_faculty_name").val("");
  $("#designationModalTitle").text("Update Designation");
  $("#designation_id")
    .prop("disabled", false)
    .empty()
    .append('<option value=""></option>')
    .val(null)
    .trigger("change");
}

function resetClassificationForm() {
  $("#classificationForm")[0].reset();
  $("#classification_faculty_id").val("");
  $("#classification_faculty_name").val("");
  $("#classificationModalTitle").text("Update Employment Classification");
  $("#employment_classification").val("");
}

function openDesignationModal($button) {
  const facultyId = Number($button.data("facultyId") || 0);
  const facultyName = String($button.data("facultyName") || "").trim();
  const designationId = Number($button.data("designationId") || 0);
  const collegeId = Number($("#college_id").val() || 0);

  if (!facultyId || !collegeId) {
    Swal.fire("Missing Data", "Unable to load the faculty designation form.", "warning");
    return;
  }

  $("#designation_faculty_id").val(facultyId);
  $("#designation_college_id").val(collegeId);
  $("#designation_faculty_name").val(facultyName);
  $("#designationModalTitle").text(designationId > 0 ? "Update Designation" : "Add Designation");
  $("#designationTermNote").text("Choose No designation to remove the appointment for " + facultyListState.termText + ". Previous semesters keep their saved designations.");
  $("button[form='designationForm']").prop("disabled", true);
  $("#designation_id")
    .prop("disabled", true)
    .empty()
    .append('<option value=""></option>')
    .val(null)
    .trigger("change");

  designationModal.show();

  loadDesignationOptions(designationId).fail(function (xhr) {
    designationModal.hide();
    Swal.fire("Error", extractErrorMessage(xhr, "Unable to load designation options."), "error");
  });
}

function openClassificationModal($button) {
  const facultyId = Number($button.data("facultyId") || 0);
  const facultyName = String($button.data("facultyName") || "").trim();
  const employmentClassification = String($button.data("employmentClassification") || "").trim();
  const collegeId = Number($("#college_id").val() || 0);

  if (!facultyId || !collegeId) {
    Swal.fire("Missing Data", "Unable to load the faculty classification form.", "warning");
    return;
  }

  $("#classification_faculty_id").val(facultyId);
  $("#classification_college_id").val(collegeId);
  $("#classification_faculty_name").val(facultyName);
  $("#classificationModalTitle").text(employmentClassification !== "" ? "Update Employment Classification" : "Add Employment Classification");
  $("#employment_classification").val(employmentClassification);

  classificationModal.show();
}

function loadDesignationOptions(selectedId) {
  return $.ajax({
    url: "../backend/query_program_chair_faculty.php",
    type: "POST",
    dataType: "json",
    data: buildRequestData({
      load_designation_options: 1
    })
  }).done(function (res) {
    const $designationSelect = $("#designation_id");
    $designationSelect.empty().append('<option value=""></option><option value="0">No designation</option>');

    if (res && res.status === "success" && Array.isArray(res.data)) {
      $.each(res.data, function (index, item) {
        $designationSelect.append(
          $("<option>", { value: item.id, text: item.text })
        );
      });
    }

    if (selectedId > 0) {
      $designationSelect.val(String(selectedId));
    } else {
      $designationSelect.val("0");
    }

    if (selectedId > 0 && !$designationSelect.find("option[value='" + selectedId + "']").length) {
      $designationSelect.append($("<option>", {value: selectedId, text: "Previously saved designation (unavailable)"})).val(String(selectedId));
    }
    $designationSelect.prop("disabled", false).trigger("change");
  }).always(function () {
    $("#designation_id").prop("disabled", false);
    $("button[form='designationForm']").prop("disabled", false);
  });
}

function initializeFacultyScroll() {
  const sentinel = document.getElementById("facultyScrollSentinel");

  if (!sentinel || typeof IntersectionObserver !== "function") {
    return;
  }

  facultyListState.observer = new IntersectionObserver(function (entries) {
    const entry = entries[0];
    if (!entry || !entry.isIntersecting) {
      return;
    }

    if (facultyListState.loading || !facultyListState.hasMore) {
      return;
    }

    loadCollegeFaculty();
  }, {
    root: null,
    rootMargin: "220px 0px",
    threshold: 0
  });

  facultyListState.observer.observe(sentinel);
}

function refreshFacultyData(options) {
  const settings = Object.assign({
    preserveScroll: false,
    viewportState: null
  }, options || {});

  loadFacultyDropdown();

  if (!settings.preserveScroll) {
    resetFacultyList();
    return;
  }

  reloadFacultyListPreservingViewport(settings.viewportState || captureFacultyViewportState());
}

function hideModalAndRefresh(modalElement, modalInstance, viewportState) {
  $(modalElement).one("hidden.bs.modal", function () {
    if (document.activeElement && typeof document.activeElement.blur === "function") {
      document.activeElement.blur();
    }

    refreshFacultyData({
      preserveScroll: true,
      viewportState: viewportState
    });
  });

  modalInstance.hide();
}

function resetFacultyList(options) {
  const settings = Object.assign({
    deferLoad: false
  }, options || {});

  if (facultyListState.currentRequest) {
    facultyListState.currentRequest.abort();
    facultyListState.currentRequest = null;
  }

  facultyListState.page = 1;
  facultyListState.total = 0;
  facultyListState.loadedCount = 0;
  facultyListState.hasMore = true;
  facultyListState.loading = false;
  facultyListState.requestToken += 1;

  $("#collegeFacultyTableBody").empty();
  $("#collegeFacultyMobileList").empty();
  $("#facultyEmptyState").addClass("d-none");

  toggleFacultyLoading(false);
  updateFacultyResultsSummary();

  if (settings.deferLoad) {
    return null;
  }

  return loadCollegeFaculty();
}

function loadFacultyDropdown() {
  const collegeId = Number($("#college_id").val() || 0);

  if (!collegeId) {
    return;
  }

  $.ajax({
    url: "../backend/query_program_chair_faculty.php",
    type: "POST",
    dataType: "json",
    data: buildRequestData({
      load_faculty_dropdown: 1,
      college_id: collegeId
    })
  }).done(function (data) {
      const $facultySelect = $("#faculty_id");
      $facultySelect.empty().append('<option value=""></option>');

      $.each(data, function (index, item) {
        $facultySelect.append(
          $("<option>", { value: item.id, text: item.text })
        );
      });
    }).fail(function () {
      $("#faculty_id").empty().append('<option value=""></option>');
    });
}

function loadCollegeFaculty() {
  const collegeId = Number($("#college_id").val() || 0);

  if (!collegeId || facultyListState.loading || !facultyListState.hasMore) {
    return null;
  }

  facultyListState.loading = true;
  toggleFacultyLoading(true);
  updateFacultyResultsSummary();

  const requestToken = facultyListState.requestToken;

  facultyListState.currentRequest = $.ajax({
    url: "../backend/query_program_chair_faculty.php",
    type: "POST",
    dataType: "json",
    data: buildRequestData({
      load_college_faculty: 1,
      college_id: collegeId,
      page: facultyListState.page,
      page_size: facultyListState.pageSize,
      search: facultyListState.search
    })
  }).done(function (res) {
    if (requestToken !== facultyListState.requestToken) {
      return;
    }

    if (!res || res.status !== "success") {
      facultyListState.hasMore = false;
      updateFacultyResultsSummary("Unable to load faculty assignments.");
      return;
    }

    const rows = Array.isArray(res.data) ? res.data : [];
    const pagination = res.pagination || {};
    const startIndex = facultyListState.loadedCount + 1;

    facultyListState.total = Number(pagination.total || 0);
    facultyListState.hasMore = Boolean(pagination.has_more);

    if (res.term && typeof res.term.is_current === "boolean") {
      facultyCanManage = res.term.is_current;
      $("#btnAddFaculty, #btnCopyPreviousFaculty").prop("disabled", !facultyCanManage);
    }
    if (res.term && res.term.term_text) {
      facultyListState.termText = String(res.term.term_text);
      facultyListState.termAyId = Number(res.term.ay_id);
      facultyListState.termSemester = Number(res.term.semester);
      $("#facultyTermText").text(facultyListState.termText);
      $("#facultyTermNote").text("Assigned count is scoped to " + facultyListState.termText + ".");
    }

    if (rows.length > 0) {
      appendFacultyRows(rows, startIndex);
      facultyListState.loadedCount += rows.length;
      facultyListState.page += 1;
    } else if (facultyListState.page === 1) {
      facultyListState.hasMore = false;
    }

    const showEmptyState = facultyListState.total === 0;
    $("#facultyEmptyState")
      .toggleClass("d-none", !showEmptyState)
      .text(facultyListState.search
        ? "No faculty assignments matched your search."
        : "No faculty assignments found for this college.");

    updateFacultyResultsSummary();
  }).fail(function (xhr, statusText) {
    if (statusText === "abort") {
      return;
    }

    facultyListState.hasMore = false;
    updateFacultyResultsSummary(extractErrorMessage(xhr, "Unable to load faculty assignments."));
  }).always(function () {
    if (requestToken === facultyListState.requestToken) {
      facultyListState.loading = false;
      facultyListState.currentRequest = null;
      toggleFacultyLoading(false);
    }
  });

  return facultyListState.currentRequest;
}

function reloadFacultyListPreservingViewport(viewportState) {
  const safeViewportState = viewportState || captureFacultyViewportState();
  const targetLoadedCount = Math.max(
    facultyListState.pageSize,
    Number(safeViewportState.targetLoadedCount || facultyListState.pageSize)
  );
  const targetPageCount = Math.max(1, Math.ceil(targetLoadedCount / facultyListState.pageSize));

  resetFacultyList({ deferLoad: true });

  const requestToken = facultyListState.requestToken;

  function finalizeRefresh() {
    if (requestToken !== facultyListState.requestToken) {
      return;
    }

    restoreFacultyViewport(safeViewportState);
  }

  function loadNextPage(pageNumber) {
    if (requestToken !== facultyListState.requestToken) {
      return;
    }

    const request = loadCollegeFaculty();
    if (!request || typeof request.done !== "function") {
      finalizeRefresh();
      return;
    }

    request.done(function (res) {
      if (requestToken !== facultyListState.requestToken) {
        return;
      }

      const rows = res && Array.isArray(res.data) ? res.data : [];
      const shouldContinue = rows.length > 0 && facultyListState.hasMore && pageNumber < targetPageCount;

      if (shouldContinue) {
        loadNextPage(pageNumber + 1);
        return;
      }

      finalizeRefresh();
    }).fail(function (xhr, statusText) {
      if (statusText === "abort") {
        return;
      }

      finalizeRefresh();
    });
  }

  loadNextPage(1);
}

function captureFacultyViewportState() {
  const items = getFacultyViewportItems();
  const viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
  const scrollTarget = getPrimaryScrollTarget();
  let anchorElement = null;
  let anchorIndex = 0;

  items.some(function (item, index) {
    const rect = item.getBoundingClientRect();
    const isVisible = rect.bottom > 0 && rect.top < viewportHeight;

    if (!isVisible) {
      return false;
    }

    anchorElement = item;
    anchorIndex = index + 1;
    return true;
  });

  const targetLoadedCount = anchorIndex > 0
    ? Math.max(
      facultyListState.pageSize,
      Math.min(facultyListState.loadedCount, anchorIndex + facultyListState.pageSize)
    )
    : Math.max(
      facultyListState.pageSize,
      Math.min(facultyListState.loadedCount || 0, facultyListState.pageSize)
    );

  return {
    scrollTarget: scrollTarget,
    scrollTop: getScrollTargetTop(scrollTarget),
    targetLoadedCount: targetLoadedCount,
    anchorAssignmentId: anchorElement ? String(anchorElement.getAttribute("data-assignment-id") || "") : "",
    anchorOffset: anchorElement ? anchorElement.getBoundingClientRect().top : 0
  };
}

function restoreFacultyViewport(viewportState) {
  const scrollTarget = (viewportState && viewportState.scrollTarget) || getPrimaryScrollTarget();
  const scrollTop = Math.max(0, Number(viewportState && viewportState.scrollTop ? viewportState.scrollTop : 0));

  window.requestAnimationFrame(function () {
    window.requestAnimationFrame(function () {
      if (viewportState && viewportState.anchorAssignmentId) {
        const anchorItems = getFacultyViewportItems();
        let anchorElement = null;

        anchorItems.some(function (item) {
          if (String(item.getAttribute("data-assignment-id") || "") !== String(viewportState.anchorAssignmentId)) {
            return false;
          }

          anchorElement = item;
          return true;
        });

        if (anchorElement) {
          const nextScrollTop = getScrollTargetTop(scrollTarget)
            + anchorElement.getBoundingClientRect().top
            - Number(viewportState.anchorOffset || 0);

          restoreScrollTarget(scrollTarget, nextScrollTop);
          return;
        }
      }

      restoreScrollTarget(scrollTarget, scrollTop);
    });
  });
}

function getFacultyViewportItems() {
  const isMobileViewport = window.matchMedia("(max-width: 767.98px)").matches;
  const selector = isMobileViewport
    ? "#collegeFacultyMobileList .faculty-mobile-card"
    : "#collegeFacultyTableBody tr";

  return Array.prototype.slice.call(document.querySelectorAll(selector));
}

function getPrimaryScrollTarget() {
  const candidates = [];
  const seen = [];

  function pushCandidate(candidate) {
    if (!candidate || seen.indexOf(candidate) !== -1) {
      return;
    }

    seen.push(candidate);
    candidates.push(candidate);
  }

  pushCandidate(window);
  pushCandidate(document.scrollingElement);
  pushCandidate(document.documentElement);
  pushCandidate(document.body);
  pushCandidate(document.querySelector(".layout-page"));
  pushCandidate(document.querySelector(".content-wrapper"));
  pushCandidate(document.querySelector(".layout-wrapper"));
  pushCandidate(document.querySelector(".layout-content-navbar"));

  let activeTarget = window;
  let bestScore = -1;

  candidates.forEach(function (candidate) {
    const scrollTop = getScrollTargetTop(candidate);
    const maxScrollTop = getScrollTargetMaxTop(candidate);

    if (maxScrollTop <= 0) {
      return;
    }

    const score = (scrollTop > 0 ? 1000000 : 0) + scrollTop + maxScrollTop;
    if (score > bestScore) {
      bestScore = score;
      activeTarget = candidate;
    }
  });

  return activeTarget;
}

function getScrollTargetTop(target) {
  if (!target || target === window) {
    return window.pageYOffset
      || document.documentElement.scrollTop
      || document.body.scrollTop
      || 0;
  }

  return Number(target.scrollTop || 0);
}

function getScrollTargetMaxTop(target) {
  if (!target || target === window) {
    const scrollingElement = document.scrollingElement || document.documentElement || document.body;
    if (!scrollingElement) {
      return 0;
    }

    return Math.max(0, Number(scrollingElement.scrollHeight || 0) - Number(window.innerHeight || 0));
  }

  return Math.max(0, Number(target.scrollHeight || 0) - Number(target.clientHeight || 0));
}

function setScrollTargetTop(target, top) {
  const safeTop = Math.max(0, Number(top || 0));

  if (!target || target === window) {
    window.scrollTo(0, safeTop);
    return;
  }

  target.scrollTop = safeTop;
}

function restoreScrollTarget(target, top) {
  const safeTop = Math.max(0, Number(top || 0));

  setScrollTargetTop(target, safeTop);

  window.setTimeout(function () {
    setScrollTargetTop(target, safeTop);
  }, 60);
}

function appendFacultyRows(rows, startIndex) {
  const tableRows = [];
  const mobileCards = [];

  rows.forEach(function (row, index) {
    const sequence = startIndex + index;
    const designationHtml = buildDesignationHtml(row);
    const classificationHtml = buildEmploymentClassificationHtml(row);
    const assignedHtml = buildAssignedHtml(row);
    const actionHtml = buildActionHtml(row);
    const safeName = escapeHtml(row.full_name || "Unnamed Faculty");
    const email = String(row.faculty_email || "").trim().toLowerCase();
    const emailHtml = email ? "<em class='faculty-email'>" + escapeHtml(email) + "</em>" : "<small class='faculty-email-empty'>No login account</small>";
    const loginHtml = buildFacultyLoginHtml(row);
    const assignmentId = escapeHtml(String(row.college_faculty_id || 0));
    const facultyId = escapeHtml(String(row.faculty_id || 0));

    tableRows.push(
      "<tr data-assignment-id='" + assignmentId + "' data-faculty-id='" + facultyId + "'>" +
        "<td class='faculty-index'>" + sequence + "</td>" +
        "<td><div class='faculty-name'>" + safeName + "</div>" + emailHtml + loginHtml + "</td>" +
        "<td class='faculty-designation-slot'>" + designationHtml + "</td>" +
        "<td class='faculty-classification-slot'>" + classificationHtml + "</td>" +
        "<td>" + assignedHtml + "</td>" +
        "<td class='text-end faculty-action-cell faculty-actions-slot'>" + actionHtml + "</td>" +
      "</tr>"
    );

    mobileCards.push(
      "<div class='card faculty-mobile-card' data-assignment-id='" + assignmentId + "' data-faculty-id='" + facultyId + "'>" +
        "<div class='card-body'>" +
          "<div class='faculty-mobile-top'>" +
            "<div>" +
              "<span class='faculty-mobile-index'>#" + sequence + "</span>" +
              "<h6 class='faculty-mobile-name'>" + safeName + "</h6>" +
              emailHtml + loginHtml +
              "<div class='faculty-designation-slot'>" + designationHtml + "</div>" +
            "</div>" +
          "</div>" +
          "<div class='faculty-mobile-grid'>" +
            "<div class='faculty-mobile-meta'>" +
              "<span class='faculty-mobile-meta-label'>Classification</span>" +
              "<span class='faculty-mobile-meta-value faculty-classification-slot'>" + classificationHtml + "</span>" +
            "</div>" +
            "<div class='faculty-mobile-meta'>" +
              "<span class='faculty-mobile-meta-label'>Assigned</span>" +
              "<span class='faculty-mobile-meta-value'>" + assignedHtml + "</span>" +
            "</div>" +
            "<div class='faculty-mobile-meta'>" +
              "<span class='faculty-mobile-meta-label'>Academic Term</span>" +
              "<span class='faculty-mobile-meta-value'>" + escapeHtml(facultyListState.termText || "Current academic term") + "</span>" +
            "</div>" +
          "</div>" +
          "<div class='faculty-mobile-actions faculty-actions-slot'>" + actionHtml + "</div>" +
        "</div>" +
      "</div>"
    );
  });

  $("#collegeFacultyTableBody").append(tableRows.join(""));
  $("#collegeFacultyMobileList").append(mobileCards.join(""));
}

function buildDesignationHtml(row) {
  const designationName = String(row.designation_name || "").trim();

  if (designationName === "") {
    return "<span class='text-muted'>No designation</span>";
  }

  const styleAttr = row.designation_style
    ? " style=\"" + escapeHtml(String(row.designation_style)) + "\""
    : "";

  return "<span class='faculty-designation-pill'" + styleAttr + ">" + escapeHtml(designationName) + "</span>";
}

function buildEmploymentClassificationHtml(row) {
  const classification = String(row.employment_classification || "").trim();
  const label = String(row.employment_classification_label || formatEmploymentClassification(classification)).trim();

  if (label === "") {
    return "<span class='text-muted'>Not Set</span>";
  }

  return "<span class='faculty-classification-pill'>" + escapeHtml(label) + "</span>";
}

function formatEmploymentClassification(value) {
  const labels = {
    permanent: "Permanent",
    temporary: "Temporary",
    contract_of_service: "Contract of Service",
    part_time: "Part Time"
  };

  return labels[String(value || "").trim()] || "";
}

function buildAssignedHtml(row) {
  const count = Number(row.assigned_count || 0);
  const label = count + " " + (count === 1 ? "class" : "classes");
  const stateClass = count > 0 ? "is-active" : "is-empty";

  return "<span class='faculty-assigned-pill " + stateClass + "'>" + escapeHtml(label) + "</span>";
}


function applyFacultyDesignationUpdate(facultyId, response) {
  const normalizedFacultyId = String(facultyId || "").trim();
  if (normalizedFacultyId === "") {
    return;
  }

  const row = {
    designation_id: Number(response && response.designation_id ? response.designation_id : 0),
    designation_name: String(response && response.designation_name ? response.designation_name : "").trim(),
    designation_style: String(response && response.designation_style ? response.designation_style : "").trim()
  };
  const designationHtml = buildDesignationHtml(row);
  const hasDesignation = row.designation_name !== "";
  const designationTitle = hasDesignation ? "Edit Designation" : "Add Designation";

  $("[data-faculty-id='" + escapeSelectorValue(normalizedFacultyId) + "']").each(function () {
    const $container = $(this);
    $container.find(".faculty-designation-slot").first().html(designationHtml);

    const $button = $container.find(".btn-edit-designation").first();
    if ($button.length) {
      $button
        .attr("title", designationTitle)
        .attr("aria-label", designationTitle)
        .attr("data-designation-id", String(row.designation_id || 0))
        .data("designationId", Number(row.designation_id || 0));
    }
  });
}

function applyFacultyClassificationUpdate(facultyId, response) {
  const normalizedFacultyId = String(facultyId || "").trim();
  if (normalizedFacultyId === "") {
    return;
  }

  const row = {
    employment_classification: String(response && response.employment_classification ? response.employment_classification : "").trim(),
    employment_classification_label: String(response && response.employment_classification_label ? response.employment_classification_label : "").trim()
  };
  const classificationHtml = buildEmploymentClassificationHtml(row);
  const hasClassification = row.employment_classification !== "";
  const classificationTitle = hasClassification ? "Edit Employment Classification" : "Add Employment Classification";

  $("[data-faculty-id='" + escapeSelectorValue(normalizedFacultyId) + "']").each(function () {
    const $container = $(this);
    $container.find(".faculty-classification-slot").first().html(classificationHtml);

    const $button = $container.find(".btn-edit-classification").first();
    if ($button.length) {
      $button
        .attr("title", classificationTitle)
        .attr("aria-label", classificationTitle)
        .attr("data-employment-classification", row.employment_classification)
        .data("employmentClassification", row.employment_classification);
    }
  });
}

function buildActionHtml(row) {
  if (!facultyCanManage) return "<span class='text-muted'>View only</span>";
  const hasDesignation = String(row.designation_name || "").trim() !== "";
  const designationTitle = hasDesignation ? "Edit Designation" : "Add Designation";
  const employmentClassification = String(row.employment_classification || "").trim();
  const classificationTitle = employmentClassification !== "" ? "Edit Employment Classification" : "Add Employment Classification";
  const designationButton = (
    "<button type='button' class='btn btn-sm btn-outline-primary btn-edit-designation' title='" +
    escapeHtml(designationTitle) +
    "' aria-label='" +
    escapeHtml(designationTitle) +
    "' data-faculty-id='" +
    escapeHtml(String(row.faculty_id || 0)) +
    "' data-faculty-name='" +
    escapeHtml(String(row.full_name || "Unnamed Faculty")) +
    "' data-designation-id='" +
    escapeHtml(String(row.designation_id || 0)) +
    "'>" +
    "<i class='bx bx-id-card faculty-action-icon'></i>" +
    "<span class='faculty-action-label'>Designation</span>" +
    "</button>"
  );
  const classificationButton = (
    "<button type='button' class='btn btn-sm btn-outline-secondary btn-edit-classification' title='" +
    escapeHtml(classificationTitle) +
    "' aria-label='" +
    escapeHtml(classificationTitle) +
    "' data-faculty-id='" +
    escapeHtml(String(row.faculty_id || 0)) +
    "' data-faculty-name='" +
    escapeHtml(String(row.full_name || "Unnamed Faculty")) +
    "' data-employment-classification='" +
    escapeHtml(employmentClassification) +
    "'>" +
    "<i class='bx bx-briefcase faculty-action-icon'></i>" +
    "<span class='faculty-action-label'>Employment Classification</span>" +
    "</button>"
  );

  return (
      "<div class='faculty-action-group' role='group' aria-label='Faculty row actions'>" +
        designationButton +
        classificationButton +
        "<button type='button' class='btn btn-sm btn-outline-danger btn-remove-assignment' title='Remove Assignment' aria-label='Remove Assignment' data-id='" +
        escapeHtml(String(row.college_faculty_id || 0)) +
        "'>" +
        "<i class='bx bx-trash faculty-action-icon'></i>" +
        "<span class='faculty-action-label'>Remove</span>" +
        "</button>" +
      "</div>"
    );
}

function buildFacultyLoginHtml(row) {
  const status = String(row.login_status || "");
  const statusText = status === "active" ? "Login active" : status === "inactive" ? "Login inactive" : "Set up login";
  const badge = status ? "<span class='faculty-login-state " + (status === "active" ? "is-active" : "is-inactive") + "'>" + escapeHtml(statusText) + "</span>" : "";
  if (!facultyCanManage) return "<div class='faculty-login-summary'>" + badge + "</div>";
  if (row.login_can_manage === false) return "<div class='faculty-login-summary'>" + badge + "<small class='text-muted' title='" + escapeHtml(row.login_reason || "") + "'>Managed by administrator</small></div>";
  return "<div class='faculty-login-summary'>" + badge + "<button type='button' class='btn btn-sm btn-outline-info btn-manage-login' data-faculty-id='" + escapeHtml(String(row.faculty_id || 0)) + "'><i class='bx bx-lock-open-alt' aria-hidden='true'></i> " + (status ? "Login Access" : "Set Up Login") + "</button></div>";
}

function toggleFacultyLoading(isLoading) {
  $("#facultyLoadingState").toggleClass("d-none", !isLoading);
}

function updateFacultyResultsSummary(overrideText) {
  if (overrideText) {
    $("#facultyResultsSummary").text(overrideText);
    return;
  }

  if (facultyListState.loading && facultyListState.loadedCount === 0) {
    $("#facultyResultsSummary").text("Loading faculty assignments...");
    return;
  }

  if (facultyListState.total === 0) {
    $("#facultyResultsSummary").text(
      facultyListState.search
        ? "No faculty assignments matched your search."
        : "No faculty assignments found."
    );
    return;
  }

  const noun = facultyListState.total === 1 ? "faculty assignment" : "faculty assignments";
  $("#facultyResultsSummary").text(
    "Showing " + facultyListState.loadedCount + " of " + facultyListState.total + " " + noun
  );
}

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/\"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

function escapeSelectorValue(value) {
  return String(value).replace(/'/g, "\\'");
}
