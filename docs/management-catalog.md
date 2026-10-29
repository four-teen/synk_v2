# Management contribution and event catalog

The catalog is now empty for a fresh start. The user cancelled legacy migration:
29 imported contributions, 11 events, and four event sessions were removed. The
old catalog import scripts and import-specific tests were removed. Do not restore
old definitions or Financial account links from the supplied SQL dump.

The existing organization setup and catalog schema remain in place:

| Table | Purpose |
| --- | --- |
| `tbl_management_organizations` | Organizations and their college ownership |
| `tbl_management_contributions` | New contribution definitions and amounts |
| `tbl_management_events` | New event definitions |
| `tbl_management_event_sessions` | Sessions belonging to new events |
| `tbl_management_violations` | New violation types and standard amounts |

Administrator manages organizations and Management account assignments. Native
contributions, events, and violations are managed from their Management pages.
New contributions and events use the assigned organization, shared current academic
year and semester, and the organization's saved current Management term. Each
stores `exam_term` in its own Management catalog table; the organization's operational
setting remains in `tbl_management_period_settings`. Saves lock and validate the
current period and revision. Records from other periods are available for viewing;
these pages cannot edit, delete, or move them into the current period. Event sessions
inherit the parent event's period and are saved in the same transaction.
The requested initial Management period was **2026-2027 / 1st Semester / Final**;
later explicit settings remain authoritative over calendar dates.

Lists provide organization scope, search, pagination, and catalog status filters.
Contributions, events, and event sessions use only **Active** and **Inactive**, with
new records defaulting to Active. Each catalog's three summary cards count total,
active, and inactive records. Both lists and their counts follow the selected academic year,
semester, and Midterm/Final term. Switching periods opens that period's records;
it never moves existing records to the new period. New records appear first by
descending contribution/event ID, regardless of their Event date.
Search applies after typing, and status/page-size selections apply immediately.
There is no separate academic scope, organization/term column, or Search/Reset action.
Contribution forms manage title, description, event date, amount, status, and an optional
event in the same organization and full Management period. Event choices and server
validation both enforce the year, semester, and Midterm/Final match. Organization
and period are assigned automatically. Both forms omit organization, academic
period, and audience controls. New records default to all students; edits preserve
the existing audience. The event form shows title, Event date, status, Absence fine,
and description. Event date, status, and Absence fine share one row on desktop.
Attendance/location settings and session controls are omitted from the form;
simplified edits preserve previously stored location fields and sessions. The
underlying session records retain their exact fine rates, grace periods, and
period association. The violation catalog stores types and standard amounts;
it does not assign charges to students.

Events now offer **Take attendance**. Attendance has its own Open/Closed state,
separate from the event's Active/Inactive status. The roster and fine are frozen
when attendance first opens; only students without scans after attendance closes
receive the fine. Student accounts display a private QR for scanning. Closing and
reopening are audited, and scans are unique per student/event. See
[management-student-financial-overview.md](management-student-financial-overview.md).

Writes validate Google authentication, role, current organization assignment,
CSRF token, current Management period/revision, and record version. Source
provenance columns remain nullable for native records; they do not create any
legacy student/account linkage. No schema changes occur during web requests.

Install Management with `backend/sql/management_live_install.sql`. It creates the
eleven Management tables and required Management account columns without importing
business rows. The cancelled Financial import tables are not included.
For an existing installation, run `php backend/tools/prepare_management_contribution_periods.php`
or import `backend/sql/management_contribution_periods.sql`. This additive upgrade
leaves existing contribution terms NULL for explicit review. Unknown terms do not
appear in either period. It does not infer periods from dates or alter source values.
The one existing native contribution was explicitly assigned to the saved current
term at the user's request; imported contributions retained their unknown term.
Existing installations also run `php backend/tools/prepare_management_contribution_status.php`
or import `backend/sql/management_contribution_status.sql` to update contribution
statuses. Open becomes Active; Draft, Closed, and Archived become Inactive. The
upgrade preserves source fields, period assignments, amounts, and timestamps.
For existing event storage, run `php backend/tools/prepare_management_event_periods.php`
or import `backend/sql/management_event_periods.sql`. The upgrade adds the event term
and period index, then normalizes event/session statuses using the same mapping.
Existing event terms stay NULL for explicit review; no dates or imported period
values are inferred or rewritten. Shared Academic Settings and student profiles
remain outside this upgrade.

Relevant verification:

The Events table displays the lowercase italic event date under its title, with
Present / Remaining counts in green and red, without the visible count caption. Unopened events use the current
eligible directory count; opened/closed events use their saved attendance roster.
The attendance status column opens the attendance dialog for Open/Close controls
without starting the camera. The separate Take attendance action starts scanning;
closed events display View attendance instead.
The Events action cell places View exemptions, Exempt students, Edit, and Delete
in a two-by-two grid with equal widths sized to its widest button. The attendance
button sits on the right and spans both rows, including on phone cards where the
labels may wrap to fit. Only permitted actions are rendered.
Closing attendance finalizes unscanned roster students as absent; financial views
show Absent in red and Present in green. The table updates its counts and status
after each attendance response without a page reload. The separate event status
continues to describe whether the catalog event is Active or Inactive. Session
storage is preserved; the Sessions column is removed from the list.

```powershell
php tests/management-live-sql.php
php tests/management-crud.php
php tests/management-contribution-periods.php
php tests/management-event-periods.php
php tests/management-portal.php
node tests/management-portal-browser.cjs
```

See [management-financial.md](management-financial.md) for the Financial reset and
[management-portal.md](management-portal.md) for account and period settings.
