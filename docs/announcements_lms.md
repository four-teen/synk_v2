# LMS class announcements

Professors open **Manage Class → Announcements** from the overview card. Posting publishes a title and plain-text message immediately to the enrolled class. Professors can edit or remove their notices; edits use a version check so another tab cannot overwrite a newer post. Updating a notice makes its new version unread for students.

The student dashboard combines class notices with quiz, exam and assignment publication events. Activity notices come from the existing `published` / `reopened` assessment logs, so no extra professor action is required. Ordinary activity edits update the displayed details without creating another unread event. Reopening produces one new event for the same activity. Closed activities retain a notice identifying their current status. Drafts and archived records are excluded. Activity links open the correct subject, grading period and assessment without starting an attempt.

Only the current shared academic year and semester are displayed to students. Visibility uses live faculty workload ownership and the existing student enrollment matcher, including legacy enrollment rows and merged classes. The dashboard shows the latest 100 authorized updates. Staff subject previews remain read only and do not show or acknowledge student notices.

Unread updates show a dismissible dashboard alert. It waits while another modal is open. Closing the alert acknowledges the displayed unread snapshot; **Mark all as read** provides an explicit action and retry if saving fails. Read state is stored per student and event version, so acknowledged notices remain on the board without repeating the alert across logins or devices. New posts are fetched on dashboard load.

## Installation

Run `php backend/install_announcements_lms.php` once in each environment. It creates only `tbl_lms_announcements` and `tbl_lms_announcement_reads` in the configured separate LMS database. It is safe to rerun. There is no import, backfill write, shared academic setting change or Management storage change. Existing activity publication logs become readable as notices automatically.

Both portal APIs require POST, a session CSRF token and a live portal identity. Professor actions also require the signed class context and current workload assignment. Student read requests revalidate every event against current enrollment; client-supplied account, faculty or student identifiers are not accepted. Messages are rendered as escaped text with preserved line breaks.

## Verification

- `php tests/announcements_lms.php`: visibility, merged/legacy enrollment, validation, read isolation, versions, activity publication, reopening and anonymous endpoint protection. Core and LMS fixtures roll back.
- `node tests/announcements_lms_browser.cjs`: actual PHP pages/assets with controlled API responses for authoring, version conflicts, safe rendering, modal coordination, acknowledgment failures, activity links and 320/390/1440 px layouts. Temporary routes and browser profiles are removed.
- Existing regression checks: `tests/professor-class_lms.php`, `tests/assessment-delivery_lms.php`, `tests/class-overview_lms.php`, `tests/student-portal.php` and `tests/student-assessments_lms_browser.cjs`.
