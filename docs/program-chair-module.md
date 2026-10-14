# Program Chair portal

The administrator assigns the `program_chair` role and its college in Access Accounts. A faculty member keeps the same shared login and can choose or switch to Program Chair. The portal checks the active account, role, college, and campus on each request. Administrator reassignment takes effect on the next request.

The portal follows the current College Dean menus: Dashboard, Faculty Workload, Faculty Profile, Programs, Students, Faculty Evaluation, and Reports with student/faculty statistics. `program-chair/*.php` delegates to the existing Dean views. The shared sidebar and navbar display the actual active role. Shared JavaScript uses dedicated Chair endpoints, which enter the existing scoped handlers without changing the user's role. Viewing terms are stored separately for Dean and Chair.

College Faculty follows Scheduler's Faculty Assignment per College page: the same desktop table/mobile cards, search and pagination, searchable Add Faculty dialog, designation dialog with No designation, employment classification dialog, removal confirmation, and Copy Previous List preview. The interface was adapted into Chair files; the original Scheduler page is unchanged. It creates or reactivates a current-term `tbl_college_faculty` assignment from the shared active faculty directory. Classification is stored on `tbl_faculty`; designations use `tbl_faculty_term_designations` for the selected current term, as they do in Scheduler. No separate faculty copies are created. Active current-term and legacy assignments are treated as duplicates; historical terms are for viewing only. Server checks enforce the current term, administrator-assigned college, active faculty, and CSRF token.

Removing deletes only the college-list entry and retains the faculty record, appointments, schedules, and prepared workloads. Copy Previous List uses the same source-term catalog and preview queries as Scheduler, stages a Chair-specific preview token, and copies only selected eligible faculty into the current term. It retains the source term's designation unless a current appointment was already selected. Copy confirmation rechecks source membership, college access, faculty status, and the current term, skips newly added duplicates, and consumes the token to prevent replay.

The faculty dialogs use the same Select2 version as Scheduler, bundled under `program-chair/vendor/select2` with its MIT license, and the existing local SweetAlert2 asset. These dialogs do not require a CDN connection.

The faculty name column shows the explicitly linked login email below the name in lowercase italic text, on desktop and mobile. Search also matches this email. The designation, employment classification, and remove actions have full labels and occupy three separate rows. Login status and the Login Access control appear with the faculty identity so the three existing actions remain grouped together.

Login Access creates a shared login for an assigned faculty member who has no account, or edits an existing linked account's Active/Inactive status, Professor/Scheduler roles, and default workspace. A new login requires an unused institutional email and follows the administrator's Google account setup; an existing login keeps its email and credentials. A Scheduler receives access only to the Chair's assigned college. The explicit faculty link is retained when Scheduler is the sole role. Accounts with any other roles, a different home college, or other college access remain managed by the administrator. Checks run on the server for every save, require the current term and CSRF, and reject stale edits. Account, role, faculty-link, and college-access changes commit together or all roll back.

Existing Scheduler files and scheduling/workload calculations are unchanged. The two new Chair chart/matrix endpoints retain the Dean read-only implementation with Chair authorization; the integration suite verifies these copies differ only in authorization. Faculty additions do not assign classes, change workloads, or edit schedules. No SQL migration is required on the current schema.

Verification:

```text
php tests/program-chair-module.php
php tests/program-chair-module.php --http
node tests/program-chair-faculty-browser.cjs
```

Database fixtures run inside transactions/savepoints and roll back. The browser test renders the real faculty page with intercepted API responses to check adding, designation removal, classification edits, search, copying selected faculty, removal confirmation, email formatting, three-row actions, login status and role edits, errors, duplicates, repeated submissions, refreshed desktop/mobile rows, and historical-term restrictions. The HTTP suite checks the actual installed PHP runtime and request handlers, including the real shared-login chooser accepting a Chair-assigned Scheduler role and rejecting an inactive account. Account tests cover forbidden roles, protected accounts, other colleges, stale edits, invalid email/status/roles, and rollback after a failed role write. Management tests verify that class schedules and workload records are unchanged.
