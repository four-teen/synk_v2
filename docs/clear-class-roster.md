# Clearing a class list in the Dean portal

Open **College Dean → Faculty Workload → View Students → Clear Student List**.
Review the complete student list, tick the acknowledgement, and confirm.
Both CSV imports and manual additions are included. Cancel returns to the class
list without changes. The action is available only to the active Dean of the
class's college, for the current academic year and semester, after the history
table is prepared. Empty class lists cannot be cleared again.

Clearing marks only the selected class's active memberships inactive. Matching
uses offering (including legacy offering ID zero), program, section, subject,
college, campus, academic year and semester. Lecture/laboratory schedule parts
share that class list. Other sections and merged-class partner offerings are
not cleared by this action.

Students remain in the Dean's Students list for the same college/program and
term, even when this was their only class or their shared profile originated
in another college. Retained directory connections do not count as subjects or
class sections. A student with no remaining subjects has a subject count of
zero. Class-section filters still match active class memberships only.
Directory details, profile/email management, login tracing and Dean account
preview remain available. Previously removed directory records are not restored.
The directory's explicit Delete action can remove these retained connections
for its selected program/term; clearing history and shared identities remain.

Student profiles, accounts, other subjects, previous semesters, submitted
evaluations, official enrollment records and login history are preserved.
The student portal stops showing the cleared class as an active subject.
Existing evaluations keep their original faculty/term; they are not moved to
a replacement faculty member.

After clearing, remove/reassign the subject in the Scheduler, then import a
corrected CSV or use Add Student to Class under the new faculty. Re-adding uses
the same student profiles and creates new active memberships. Old inactive
memberships and clearing history remain. Clearing alone does not reassign a
workload or automatically repopulate it. Re-importing the old CSV can add its
students back, so use the corrected file after reassignment.

Class-list counts refresh to zero. Offering counts derived from rosters are
recalculated; deliberately entered manual offering estimates are retained,
consistent with existing CSV/manual addition behavior.

Every confirmation rechecks role, college/campus access, CSRF token, current
term, class assignment, schedule and exact membership snapshot. A roster or
assignment changed after preview requires a fresh preview. Tokens are bound
to the user and class, expire after 15 minutes, and are consumed on success.
Clearing uses the existing roster/email locks, locks the workload and membership
rows, and writes history, inactive status and derived counts in one transaction.
Failures roll back all changes. The history stores the Dean, UTC timestamp and
the original membership snapshot for each cleared membership.

## Database preparation

Run **backend/sql/phase29_student_class_clear_history.sql** in the live
application database before deploying this feature, or use:

```text
php backend/tools/prepare_student_class_clear_history.php
```

This feature adds **one new table**, `tbl_student_class_clear_history`.
No existing tables are altered. The local database is prepared. Live database
preparation is separate. Existing view/import/add workflows continue if this
table has not been installed, and clearing stays hidden. Web requests never
create or alter schema. The preceding manual-add feature separately requires
`phase28_student_class_roster_audit.sql`.

## Verification

Run database suites sequentially. Fixtures roll back their changes and compare
protected records before/after. The clearing suite covers preview/cancel, both
entry sources, legacy memberships, isolation, directory retention, empty lists,
CSV re-addition, expired/stale/replayed previews, access checks, and a failure
after the first membership update to verify complete rollback.

```text
php tests/clear-class-roster.php
php tests/manual-class-roster.php
php tests/student-management.php
php tests/dean-login-report.php
php tests/college-dean-statistics.php
php tests/student-portal.php
php -d extension=gd tests/college-dean-workload.php --http
php tests/program-chair-module.php
node tests/manual-class-roster-browser.cjs
node tests/college-dean-workload-ui.js
```

The browser suite exercises the actual rendered page/controllers with isolated
API responses: acknowledgement, cancel/return, empty-list refresh, preview
failure/retry, escaping, historical controls, duplicate-submit/close protection
and desktop/mobile layout. Screenshots are saved under `.tmp`.
