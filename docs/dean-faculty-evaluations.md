# Dean faculty evaluation results

The **Faculty Evaluation** menu now includes **Combined Faculty Results** and
**Supervisor Evaluations** in both Dean and Program Chair modules. The existing
student overview described below is available under **Student Results**. See
`docs/supervisor-evaluations.md` for evaluator routing, private drafts, finalization,
the separate collection window and combined 60/40 individual reports.

The College Dean sidebar includes **Faculty Evaluation**, at
`college-dean/faculty-evaluations.php`. The page follows the Dean portal's selected
academic year and semester. Its year and semester controls also update that
shared viewing term.

The overview lists faculty assigned to the college or present in its imported
subjects for the selected term. Faculty remain visible when no evaluations have
been submitted. Search filters the names; **Show Evaluation** opens the selected
faculty's results, with the faculty list collapsed for easier reading.

The faculty list includes **Subjects**, **Submitted Count**, drafts, scores and
**Action**. Subjects count distinct subjects across imported rosters and college
assignments, with repeated sections counted once. Submitted Count shows green
submitted / red not submitted, counting each student once per faculty. Current
roster counts use active enrollments; historical terms retain original inactive
enrollments as evidence. Matching saved responses also remain in the cohort.
The instrument filter limits submitted responses and clearing scope.

The overview shows submitted evaluation counts, draft counts, and student evaluator
counts. Results include overall scores, category scores, statement means, response
counts, the original rating scale, and submitted comments. Draft ratings and
comments are excluded from this results page. Comments have no student-name labels
and their HTML is escaped when rendered.

The old 20-question and revised 15-question instruments have separate reports and
an instrument filter. Their scores are never combined. Calculations use raw
points and answer counts, with two-decimal rounding at the end, as described in
`docs/student-evaluation.md`.

Every request revalidates the Dean account and assigned college. Evaluation access
requires a matching student/faculty/academic-term enrollment in that college and
campus. Historical inactive imports remain evidence of original membership, so
past evaluations can be reviewed after a list is superseded. Guessed faculty IDs
outside the college and term are rejected.

The Dean can separately open/close student evaluations and release/hide faculty
results for the selected college and term. Student collection can open only for
the current academic year and semester. Historical result releases remain
independent. Program Chairs retain result viewing but cannot change these
controls. Changes use authenticated, CSRF-protected POST requests, reject stale
versions, and write an audit record.

Each faculty has **Show Evaluation** and **Clear Evaluation** actions. Only the
assigned Dean can clear. Clearing removes both submitted responses and saved
drafts for that faculty in the selected college, academic year, semester and
instrument. There is no clear-all action. Historical terms can be cleared without
opening them for new student submissions. Other faculty, colleges, terms,
instruments and evaluation access settings are preserved. Ambiguous legacy
ownership is excluded rather than guessed.

The confirmation displays the faculty, college, term, instrument and affected
counts before enabling Clear Evaluation. A session-bound preview expires after
15 minutes. Confirmation revalidates Dean access and locks the scope and response
records; changed responses require a fresh preview. The complete original
headers, answers, counts and acting Dean are retained in clearing history before
deletion in the same transaction. Any failure rolls back both history and
deletion. Repeated confirmation cannot clear another faculty. Mobile review uses
a scrollable body with visible Close, Cancel and Clear controls.

Before deploying clearing, apply
`backend/sql/phase35_college_faculty_evaluation_clear_history.sql` to the intended
database, or run `php backend/tools/prepare_faculty_evaluation_clearing.php`.
This adds only `tbl_college_faculty_evaluation_clear_history`; it never clears or
changes existing evaluations. Without this table, clearing stays disabled.
Scheduling and workload storage and behavior are unchanged.

## Subject exclusions from score computation

The assigned Dean can open **Manage Subject Exclusions** from Faculty Evaluation.
The list follows the selected college, academic year and semester. Select a subject,
choose **Exclude from faculty scores**, and record a reason. The rule
applies to that subject across its teachers and sections in this college and term;
it does not exclude the teacher's other subjects or another college's responses.
Use **Include / restore eligibility** to reverse it. Every change
preserves the previous and new rule, reason, acting Dean and timestamp. Stale form
versions and unauthorized colleges, subjects, roles and CSRF tokens are rejected.

The subject list searches codes and titles within the selected college and term,
with All, Included, Excluded and Needs review filters and a matching-subject count.
**Manage** opens a dialog with that subject's rule and reason. Saving refreshes
the list while preserving the search and position; validation errors stay inside
the dialog. Cancel does not save. Existing subject-specific URLs open the matching
dialog automatically. Phones use subject cards and a full-screen dialog with
visible Cancel and Save controls. Unknown save status requires reloading the latest
rule before retrying from the open dialog.

The Dean's shared sidebar provides a small-screen footer with Home, Students,
Evaluation, Reports and More. More lists the existing remaining destinations;
their routes and permissions are unchanged. The desktop sidebar is preserved,
and mobile navigation hides behind dialogs. This navigation is Dean-only and
does not change Program Chair, Student or Management navigation. No scheduling,
workload, scoring or access-control logic is changed by these interface features.

Students continue evaluating all linked teachers through the normal dashboard and
evaluation flow. Exclusions never hide a teacher, relax completion requirements,
alter reminders or expose the private reason. The existing one-response-per-student,
teacher, college and semester rule remains. Each new or re-saved draft records its
subject associations in separate evaluation-only storage, inside the response
transaction. Finalized response snapshots are never rewritten by roster changes.
Older responses use only exact original subject labels matched against original
college/term enrollment evidence. Missing or ambiguous associations are not guessed.

A response covering only excluded subjects is omitted from overall, category,
statement and student-component scores. A response covering both included and
excluded subjects remains counted and appears for Dean review. There is no
automatic whole-response exclusion for a mixed response. Unknown historical associations remain counted
for review. Scores cannot be divided between subjects after a combined response
has already been collected. The subject list's response counts can overlap when
one teacher response covers more than one subject.

Dean and Program Chair results, administrator aggregate reports and released faculty
scores use the same eligibility rule. Questionnaire versions stay separate. Submitted
and completion counts remain unchanged; the Dean sees included, excluded and review
counts separately. No eligible evaluations displays an empty score rather than 0%.
Comments remain available under existing access rules. The administrator's original
rating sheet retains every submitted response with its eligibility label. Exclusion
reasons and change history are available only on the Dean's management page.

Before serving this feature, run the additive, repeatable setup:

```text
php backend/tools/prepare_evaluation_subject_exclusions.php
```

Alternatively apply `backend/sql/phase36_evaluation_subject_exclusions.sql` after
the existing evaluation access schema. No subject is excluded by installation.
There are no changes to scheduling, workload, student profiles or academic settings.
Clearing keeps the separately captured subject associations as historical evidence;
they cannot affect scores without their original response header.

Additional regression checks (run database suites sequentially):

```text
php tests/evaluation-subject-exclusions.php
php tests/evaluation-subject-exclusions-http.php
node tests/evaluation-subject-exclusions-browser.cjs
```

Regression checks:

- `php tests/college-dean-evaluations.php`
- `php tests/college-dean-evaluation-clear.php`
- `node tests/college-dean-evaluation-browser.cjs`

Run the database fixture suites sequentially to avoid lock contention. They use
temporary colleges and roll back their data. The browser suite intercepts clear
requests and verifies desktop/mobile controls, cancellation, preview errors,
duplicate confirmation, stale records and request timeout handling.

See `docs/evaluation-access.md` for preparation, student and faculty behavior,
and historical response ownership.
