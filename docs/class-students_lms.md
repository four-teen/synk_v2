# LMS class roster and student status

Open My Workload → Manage Class → Students. The named roster uses the existing
current enrollment matcher, including legacy offering matches and merged classes.
All students, Enrolled and Dropped cards filter the list. Name/ID search ignores
accents, and lists paginate at 25, 50 or 100 students. On phones each student has a
compact card with a visible status and a full action label.

Mark as dropped opens a confirmation identifying the student, subject and term,
with an optional 500-character professor note. A dropped row remains in the list
with a red badge and Restore to enrolled action. Restoration is also confirmed.
Statuses are independent of whether the student has signed in to the portal.

As requested, this is a reversible LMS class status. Dropped students keep LMS
access, assessment attempts, saved/submitted answers and grades. They can still
start or submit assessments. They are excluded from the professor's missing-
submission reminders and the student's dashboard assessment deadline notices
for this class. Assessment card missing counts use the same exclusion; the
roster total and submitted counts retain their existing meaning. Follow-up
reports state how many outstanding dropped students were excluded. Restoring
reenables reminders according to the existing deadlines and completion status.
Class announcements and gradebook access continue normally.

Status is scoped to faculty, displayed class/offering, academic year, semester
and student. Merged classes use the displayed class workspace's status, with each
member listed once. Unrelated subjects, terms and professors have independent
statuses. Shared student profiles, official enrollment, academic settings,
workloads, schedules and Management records are not modified.

The POST-only endpoint `professor/class_students_api_lms.php` accepts list and
set_status. Each request checks the active professor account, current workload
ownership, a session-bound signed Students link and CSRF token. A status change
also checks current roster membership. Versions reject stale tabs; changes and
audit rows commit together in the LMS database. A failed save keeps the dialog
and professor note, and a stale save requires refreshing the roster.

Run `php backend/install_class_student_status_lms.php` to install or verify the
two tables in the separate LMS database. This has been run for the local site.
For another installation, the equivalent SQL is
`backend/sql/class_student_status_lms.sql`. Web requests never create tables;
before installation the roster remains readable and status changes are disabled.
The installer preserves existing records and is safe to rerun.

`tbl_lms_class_student_statuses` holds the latest status, note, revision and actor.
`tbl_lms_class_student_status_logs` retains each previous/new status, note, actor,
revision and Philippine timestamp. There are no cross-database foreign keys.
Professor notes are not included in student assessment responses or reminders.

Verification: `php tests/class-student-status_lms.php` checks changes, restoration,
scope, audit records, stale revisions, merged rosters, retained access and reminder
exclusions with all fixtures rolled back. `node tests/class-students_lms_browser.cjs`
checks the real PHP page/assets with controlled API responses, including search,
pagination, status filters, cancel/retry, stale saves and 320–1440px layouts.
