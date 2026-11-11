# Professor LMS grading system

Open My Workload → Manage Class → Manage Grading System. The overview has three
tool cards per row on desktop, two on tablet, and one on phones. The Grading system
tab opens the same signed workspace.

Midterm and Final are separate grading setups for the professor, class offering,
academic year, and semester. Add, rename, describe, reorder, or remove components.
Each period accepts 1–30 components and must total exactly 100% before saving.
Weights accept 0.01% through 100%, with two decimal places. Totals use integer
hundredths of a percent to avoid rounding drift. A missing setup remains empty;
professors create their own breakdown using Add component. Reaching 100% enables
saving; it does not save automatically. Click Save Midterm or Save Final to
record that period. Saving one period does not save or discard the other.

The locally bundled SweetAlert2 library provides save/reload success toasts,
validation and service error dialogs, and confirmations for removing components,
discarding changes, and leaving the page. Cancel preserves unsaved edits. Browser
reload and close-tab protection continues to use the browser's own warning.

Add component inserts a new component first and shifts the previous components
down, preserving their contents and IDs. Save Midterm and Save Final sit beside
Add component in their respective period. The displayed order is saved and
restored when the period is reopened.

Run `php backend/install_grading_lms.php` to create the three `tbl_lms_grading_*`
tables in the separate LMS database. The installer can be rerun. Web requests
never create schema. Shared academic settings, workloads, scheduling, student
records, and Management financial periods are not changed or synchronized.
The configured local LMS database is `synk_lms_db`. Both assessment records and
grading schemes, components, and revision logs use this LMS connection.

Every request checks the professor's active account, faculty link, current class
assignment, signed grading view, and CSRF token. Updates are transactional and
revision checked. Stale saves preserve edits and return HTTP 409; Reload period
loads only the selected period, preserving unsaved work in the other one.

Existing components retain their IDs across edits and reordering. Removed
components are archived, and each save records before/after snapshots. These
support the [LMS gradebook](gradebook_lms.md), which records manual scores and
linked assessments against a saved grading revision. Changes to grading weights
do not silently alter recorded grades: the professor reviews and applies the
latest revision in Grade Book. The grading setup itself never writes student
scores, schedules, workload assignments, or shared academic records.
