# Scheduler working academic term

The scheduler navbar has **Working term settings**, following the dean portal's
year/semester dialog. A selection is stored in the scheduler's session and
defaults to the official active term. It never updates `tbl_academic_settings`.

Schedulers can work in **any existing academic year and semester**, including
previous years and years marked inactive. No administrator opening/closing step
is required. The official current term remains the default for new sessions and
is managed separately by the Administrator. A workspace selection never changes it.

The former planning-permission controls have been replaced with an explanatory
note in Academic Settings. Existing planning-table rows are preserved for upgrade
compatibility and no longer restrict Scheduler access. Stale pages submitting to
the retired settings endpoint receive HTTP 410 without changing permissions.

### Copy Previous List deployment checks

Faculty copying validates college membership through `tbl_college_faculty`; it
does not require `tbl_faculty.college_id`. The faculty master stays shared.

A working preview alone does not confirm that designation storage is installed:
older deployments can read the global designation as a fallback. Copying must
retain appointments for the selected term, so it requires the complete
`tbl_faculty_term_designations` schema from
`backend/sql/phase23_faculty_term_designations.sql`. Missing or incomplete storage
returns a setup message before changing the faculty list.

If that table is absent, run the preparation command below **on the affected
server**, using its configured database. Running it on a workstation only prepares
the workstation database. If the table already exists but is incomplete, compare
its columns with the phase 23 schema and repair the missing fields without
dropping existing appointment records. The copy endpoint never runs schema DDL.

Install the additive database prerequisites on every deployment:

```powershell
php backend/tools/prepare_scheduler_working_terms.php
```

The preparation tool installs the term-designation table and baseline migration
when absent, plus compatibility storage for older planning settings. It preserves
the official term and existing academic records. No permission setup is required.

## Scope and operational behavior

- Scheduler pages, dashboard counts/charts, reports, room access, sections,
  offerings, faculty lists, appointments, schedules, workload, and simulation
  defaults use the working term.
- Existing page term selectors reload into that working term. AJAX requests carry
  the originating page's year/semester and college. Another tab's session changes
  cannot redirect a save. A stale college context is rejected.
- Server checks enforce active account/role and assigned-college access, validate
  term identifiers, require CSRF protection on writes, and reject mismatched record
  terms. Scheduler writes and official-term updates retain their shared advisory
  lock so legacy current-term membership rules stay consistent during a write.
- Copy Previous List previews and copies into the selected term, retaining source
  records, owned-room sharing rules, and saved appointment names/units. Undated
  legacy faculty memberships belong to the existing current list and can be copied
  forward without deleting their source membership.
- Faculty, program, curriculum, and room master records retain their existing
  shared identity. Selecting a term does not create historical snapshots of master
  names, capacities, employment classifications, or signatories. Scheduler
  appointment edits use the selected term's designation record.
- Future schedules and workloads retain the application's existing visibility
  rules. This feature does not introduce an unpublished draft/release workflow.
- Existing room/section/faculty conflict checks, merged schedule rules, pending
  schedule handling, and student-protected workload removal remain in place.
  Selected-row removal always retains its explicit row IDs and term; whole-term
  clearing remains a separate operation.

## Validation

```powershell
php tests/scheduler-term-lists.php
php tests/scheduler-tba.php
php tests/scheduler-working-term.php
php tests/scheduler-advance-scheduling.php
```

HTTP fixtures use savepoints and roll back test data. Coverage includes current
and future preparation, previous/inactive-year editing, retired planning flags,
scheduler/admin role checks,
forged terms, stale college contexts, historical appointment preservation, legacy
memberships, schedule/workload creation, CSRF protection for scheduled classes,
selected-row removal, active student protection, and scheduler page rendering.
php tests/subject-assignments.php also checks the existing read/report flow.
Run these suites sequentially; their transaction fixtures deliberately hold shared
account/role index locks until rollback. Browser checks cover current, future,
and previous-term selection plus the settings dialog at 320px and 390px widths.
