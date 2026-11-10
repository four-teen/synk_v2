# LMS assessments

Open My Workload → Manage Class → Activities & assessments. The signed class link
and current professor assignment are checked on every page and API request.

The workspace supports private authoring, publishing, and enrolled student responses:

- Independent Midterm and Final grading periods for each class.
- Quiz, Exam, and Assignment types, with title, instructions, topic and points.
- Multiple choice (2–6 choices), True/False, short answer (accepted alternatives),
  and essay questions, with ordering and duplication.
- Optional opening date, due date and quiz/exam time limit in Philippine time.
- Preview with an optional professor-only answer key.
- Draft duplication into either grading period, plus archiving.
- Transactional saves, revision checks and an audit log.
- Publish the current draft with a confirmation; student delivery and questions
  become available according to the saved opening date and deadline.
- One resumable student attempt, server-enforced timers, saved responses, final
  submission locking, and professor response review.
- Close published assessments; saved work is retained and further responses stop.

Each professor assessment card shows answered students in green / total students
in red. Answered counts distinct submitted attempts from the actual enrolled
class roster; in-progress, expired, withdrawn and other-class attempts do not
inflate it. The total uses the same deduplicated roster as student delivery,
including legacy and merged-class membership, rather than the recorded class
headcount. Drafts show zero answered and retain the roster total. Closed
assessments retain their submitted count. Refresh updates the card counts. If
delivery storage is unavailable, the answered count is shown as unavailable.
Midterm uses indigo and Final teal, with a filled selected button, icons, counts,
keyboard focus outlines and pressed states. Counts wrap below the title on phones.
These are read-only aggregates from existing core enrollment and separate LMS
attempt storage; this display change requires no database migration.

LMS notifications use the locally bundled SweetAlert2 library: success toasts,
validation and request error dialogs, and confirmations for removing questions,
changing to Assignment, archiving, discarding edits, and leaving the page.
Cancel preserves the current work. Dialogs work above the existing preview and
duplicate modals. Browser reload/close-tab warnings remain browser controlled.

Question prompts support inline code between backticks and code blocks between
lines of triple backticks. An optional language can follow the opening fence
(for example, ```python). Select text and use Inline code or Code block to insert
the formatting. The live question preview and full assessment preview preserve
code indentation and show long code lines in a scrollable block. HTML remains
plain text; code is never executed. Formatting stays in the existing prompt text,
so reopening and duplicating drafts preserve it without a database migration.

Add question sits above the question list and inserts new questions first. The
order shown in the editor is the saved and previewed order. Preview, Save draft,
and Publish are available together at the top of the editor. Create draft remains
above the assessment list. Use All assessments at the upper left to return to
the list. A second action bar with the same three buttons floats at the bottom for a
new draft or unsaved edits, hides after a successful save, and reappears on edits.

Saving a draft does not publish it. Publish validates complete questions/answer
keys (or assignment instructions), requires a future deadline if supplied, saves
the current contents and changes status atomically. Published and closed assessments
offer Edit assessment for correcting questions, choices, answer keys, points,
instructions, type, grading period, opening dates, deadlines, and time limits.
Save changes preserves the current published/closed status. Closed assessments
also offer Reopen, which explicitly publishes the saved changes for new attempts.
Submitted and time-ended attempts remain locked. Duplication still creates a
separate draft and clears dates.

Each started attempt keeps an LMS-only snapshot of its original assessment,
including question IDs/order, choices, answer keys, points, instructions, grading
period and timing settings. Changes apply only to students who have not started.
Existing browser tabs can continue saving and submitting their original answers;
editing never resets an attempt timer or revision. Student detail, list-card
results, and professor response review use that attempt's original content.
Snapshots and answer keys never enter student responses. Pre-upgrade attempts
without a snapshot are preserved atomically before the first published/closed
edit. The assessment row lock serializes edits with starts, saves and closing.

Students use Activities & assessments from their dashboard or navigation (More on
mobile). My subjects opens first as a responsive card grid, including subjects
that have no published work yet. Each card shows its professor, schedule, room,
and section; search matches subject codes, titles, professors, and sections.
View assessments opens only that faculty subject's Midterm/Final list. All subjects
returns to the cards, and leaving an answer form preserves the selected subject.
Below View assessments, each subject card has three colored rows with count badges: Needs attention
(amber), Completed assessments (green), and Total assessments (blue). Attention
counts only currently open, published work that can still be answered. Completed
counts submitted attempts. Total includes all visible published/closed assessments,
including upcoming, overdue, and time-ended work. Counts cover both LMS grading
periods, update after submission, and retain zero values. Submitted work remains
available to review.
The dashboard's former subject-list area is reserved for announcements; posting
announcements is not implemented yet. Staff dashboard previews keep their existing
read-only subject list and historical term controls.

Their real identity, current academic term, active enrollment (including
legacy and merged-class matching), active faculty, and live workload ownership
are checked on every operation. Other student IDs and academic term values in a
request cannot override session identity or shared academic settings. Staff preview
cannot answer assessments. Questions are disclosed only after the attempt starts;
answer keys never appear in student responses.
The student page accepts no URL selectors and redirects query strings to its clean
URL; extra path segments are rejected. Its POST-only API rejects query selectors,
unsupported top-level fields, and payload fields outside the selected action's
allowlist, including supplied student/attempt/faculty/term identifiers. Every
attempt read or update is scoped to both the authenticated student and authorized
assessment. Knowing an assessment or another student's attempt ID grants no access
to that student's work. CSRF tokens are bound to the current session.

The deadline is the earlier of the due date and the time limit measured from the
server's start timestamp. Reloading or another tab resumes the same attempt and
never resets its deadline. Responses save on request and every 15 seconds while
editing. Closing a page does not pause the timer. At expiry, the last answers
saved before the deadline are retained; late payloads are not accepted. Students
should save before leaving. Revision checks protect against stale tabs; final
submission is idempotent and locks responses. Quizzes support all four question
types; assignments accept written responses. File attachments are not included.

Professors use Student responses in the assessment list to review saved or submitted
answers. After submission or timer expiry, students see server-calculated total
correct and total mistakes for multiple-choice, True/False, and accepted short
answers. Short answers ignore case and repeated/leading/trailing whitespace and
match any saved accepted alternative. Unanswered automatically checked questions
count as mistakes. Essays and assignments remain awaiting professor review and
are excluded from those totals. No results are returned while an attempt can
still be edited, and answer keys and per-question correctness remain private.
Existing submitted attempts receive the same summary when reopened; no schema
migration is needed. These are question counts, not weighted or released course
grades. Manual grade entry, course-grade calculation, and grade release remain
part of the gradebook work.
The subject's assessment list also shows these totals directly on submitted
cards, with a green background and View results action. Time-ended attempts use
an amber background and their last saved answers. Active cards keep their normal
background and never receive result totals or private answer data in the list API.
Subject cards use compact spacing and retain their three count rows below View
assessments. Assessment-list results use small inline badges that wrap on narrow
screens; repeated subject labels are omitted because the subject heading already
identifies the class. Action buttons retain a 44px minimum height. In the full
result view, How results are checked expands the grading explanation on request.

## Storage and installation

Run `php backend/install_assessments_lms.php` from the repository root. It uses
`backend/db_config.local_lms.php` and a separate database connection, creates
`tbl_lms_assessments`, `tbl_lms_assessment_questions`, and
`tbl_lms_assessment_logs`, and supports rerunning the installer. Web reads and
writes never create tables.
Run `php backend/install_assessment_delivery_lms.php` after that installer to create
`tbl_lms_assessment_attempts` in the same separate LMS database. It is safe to rerun.
Attempt answers, deadlines, revisions and submission timestamps are LMS-only.
For existing installations, rerun that delivery installer before deploying the
editing feature. It adds the nullable `assessment_snapshot_json MEDIUMTEXT` column
without changing existing answers. With phpMyAdmin, run
`backend/sql/assessment_editing_upgrade_lms.sql` in the **separate LMS database**.
Web requests never perform schema changes; published editing and student delivery
require the upgraded storage. Existing snapshots are never overwritten by edits.

LMS grading periods are independent of Management financial periods. Core
academic settings, academic years, semesters, workloads, schedules, student
profiles, and Management records are not modified by assessment recording.
Cross-database references are identifiers only; there are no foreign keys into
the core database. Each assessment is scoped to professor, offering, academic
year, and semester. Reassignment removes access immediately even with a valid
signed link.

Questions and points are validated on the server, and quiz totals are recalculated
from question points. Draft, published and closed records can be edited; only
drafts can be archived. Published/closed saves must retain complete questions
or assignment instructions. A stale
revision returns HTTP 409 and preserves the newer saved content. CSRF tokens and
signed class context are required for all API requests, including reads.

Regression checks: `php tests/assessment-editing_lms.php` covers multiple revisions,
pre-upgrade attempts, original results and timers, new start dates, type conversion,
closed edits/reopening, ownership, stale revisions and transactional rollback.
`php tests/assessment-delivery-http_lms.php` exercises the real PHP 7.1 endpoints;
`node tests/assessments_lms_browser.cjs` covers published/closed editing, original
response review, save confirmations and mobile action controls.

## Reference and next stage

The familiar title/instructions/points/due-date flow follows the concepts in
[Google Classroom assignments](https://support.google.com/edu/classroom/answer/6020265)
and [quiz assignments](https://support.google.com/edu/classroom/answer/9095575).
This is a native LMS builder; it does not connect to Google Classroom or Forms.

Next, connect assessment scores to grading components, add point-based scoring,
professor grading for written answers, and explicit grade release. File uploads,
individual-student assignment, and reusable question banks can follow.
