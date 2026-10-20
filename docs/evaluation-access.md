# College evaluation access

The College Dean's **Faculty Evaluation** page has two independent controls for
the selected college, academic year and semester:

| Control | Enabled | Disabled |
| --- | --- | --- |
| Student evaluations | Students can answer, save drafts and submit | No new drafts, edits or final submissions |
| Faculty results | Professors can read their own submitted results | Scores, comments and question statistics are unavailable |

Only the assigned active Dean can change access. Program Chairs can review their
existing reports but have no access-control permission. Dean reports continue to
work while faculty results are hidden. Every control change records the actor,
college, academic term, old/new value and timestamp. Concurrent changes use a
version check so a stale browser tab cannot overwrite a more recent decision.

## Normal workflow

1. Select the current academic year and semester in the Dean portal.
2. Open student evaluations when collection should begin.
3. Close student evaluations for review. Saved drafts and submissions remain.
4. Release faculty results when ready. Results can subsequently be hidden again.

Each college makes these decisions independently. Faculty teaching in multiple
colleges receive separate released reports; a hidden college's responses cannot
enter another college's scores. The faculty page is **My Evaluation Results** in
the Professor sidebar. It uses the account's current active faculty link, never a
requested faculty ID, and omits student names, student numbers, response tokens
and individual response links. Submitted comments are HTML escaped. Drafts never
enter faculty reports. Questionnaire versions are reported separately.

The Student **Faculty Evaluation** menu is hidden while collection is closed.
It appears only when the student has active faculty-linked enrollments in a
college that has opened the current academic year and semester. **Show Account**
uses the same menu rule, including Dean and Administrator previews; a Dean's
preview is limited to that Dean's college. The menu opens an available college's
current-term evaluation directly. Faculty score release is a separate setting
and does not open the student menu. Closing collection never removes historical
responses or the Dean's report access.

## Term rollover and saved work

The central academic settings identify the current term. A college's open switch
does not override that term. Previous terms reject student writes even if their
stored switch was left open. Every new college/term combination defaults to
closed with results hidden; no cron job or destructive reset is required.

Previous submissions and drafts retain their original term and questionnaire.
Submitted responses remain locked. Closing and reopening the current term keeps
unfinished drafts available; past-term drafts remain read only. Saved responses
also remain discoverable after historical enrollment imports become inactive or
are removed. The selected college is included in the final completion QR.

Students with enrollments in multiple colleges select a college on the evaluation
page and finalize that college's complete faculty list independently. Server-side
membership, current-term and open-period checks run in the same transaction as
each save/submission. Shared database locks serialize those writes with period
closure and academic-term updates.

## Schema preparation

Run once before serving the updated application (safe to repeat):

```text
php backend/tools/prepare_evaluation_access.php
```

For manual SQL deployment, select the live Synk database and run the complete
`backend/sql/phase26_college_evaluation_access.sql` file instead. It checks for
existing columns and keys, preserves saved settings, and can be run again after a
partial preparation. The existing evaluation, enrollment and college tables must
already be present. Its final query reports historical responses still needing
college ownership review.

The existing student schema preparation also prepares these additions when
needed. It adds `college_id` to evaluation headers, replaces the old unique key
with `(student_id, faculty_id, college_id, ay_id, semester)`, and creates
`tbl_college_evaluation_settings` and `tbl_college_evaluation_audit`. Completed
installations perform no DDL during evaluation transactions. No evaluations,
answers, scores or submission statuses are cleared by this feature.

Existing responses are assigned to a college only when their original enrollment
data proves one college. Ambiguous or missing membership retains `college_id=0`;
the preparation command reports that count. These rows remain in administrator
reports and require a reviewed ownership assignment before college/faculty
release. Legacy rows with exactly one proven college can still be read safely.
Ownership is never inferred from the professor's home college or a student's
current program after a transfer.

## Verification

```text
php tests/evaluation-access.php
php tests/evaluation-access-migration.php
php tests/student-evaluation.php
php tests/college-dean-evaluations.php
php tests/student-portal.php
node tests/evaluation-access-browser.cjs
```

Database fixtures use an enclosing rollback and protect the loopback HTTP
fixture with a random token. They cover college isolation, shared faculty,
current-term enforcement, academic rollover, independent release history, CSRF,
stale changes, Dean-only permission, revoked faculty access and draft exclusion.
