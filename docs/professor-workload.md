# Professor workload and student rosters

The workload list groups each class into a compact subject summary with meeting
times, rooms, section and subject code. The columns are Subject, Enrolled,
Active / Inactive, and Action. Show Students opens a wide, searchable roster with
student names, student numbers and uploaded photos, split by login activity.

Active means at least one successful Student portal sign-in in the existing
`tbl_student_login_activity` history, across all terms. Failed attempts alone do
not mark a student active. These are sign-in adoption counts, not live presence.
The top Active and Inactive figures count unique roster students across the
selected academic term. Per-class figures count each roster student once in that
class, including combined class rosters. Enrolled keeps the existing recorded
enrollment total, which can differ from the available named roster. Missing
tracking is shown as unavailable rather than classifying all students inactive.

The read-only roster endpoint derives faculty identity from the signed-in
professor account. It rechecks the active account, Professor role, faculty link,
assignment and exact academic term. It uses the existing enrollment matcher for
offering, section, subject, program, college and campus, including older rows
without an offering ID. Inactive enrollments and other terms are excluded. Login
addresses, devices and other authentication diagnostics are never returned.

Below 1200px, Professor pages use a fixed footer with Home, Workload, Profile,
Research and More. The expandable More menu uses the same destinations as the
desktop sidebar, including evaluation results. Wide screens retain the sidebar.
The footer is hidden while a class roster modal is open.

No new or altered tables are required. Deploy the changed Professor files and
`backend/professor_workload_students_helper.php` together; retain the existing
Student login history schema. No Management settings or academic data change.

Checks:

- `php tests/professor-workload.php --database`
- `php tests/professor-workload-students.php`
- `node tests/professor-workload-students-http.cjs`
- `node tests/professor-workload-browser.cjs`

Database fixtures are transaction-scoped and rolled back. Browser checks use
rendered QA records and simulated responses; they never submit real student data.
