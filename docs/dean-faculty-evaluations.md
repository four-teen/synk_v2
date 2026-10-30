# Dean faculty evaluation results

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
