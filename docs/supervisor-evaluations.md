# Supervisor evaluation of faculty

Open **Faculty Evaluation → Supervisor Evaluations** in the College Dean or Program
Chair portal. **Combined Faculty Results** opens the same college/academic-term
results list from either portal. The existing Student Results page remains available.

## Evaluators and collection

Faculty route automatically to the Program Chair of the program with their highest
actual assigned teaching workload within the selected college, academic year and
semester. The evaluation module reads the existing faculty-load metric, including
lecture/laboratory and split schedule ownership; designation load is excluded.
It does not modify workload calculations, scheduling, assignments or their storage.
The stored offering program owns the workload; merged member offerings do not
create additional workload assignments or duplicate load in this projection.

Program Chair appointments identify the reviewer by faculty identity and active
linked portal account. Current program signatory mappings are used only when a
term-specific appointment is absent. Missing workload or an eligible linked Chair
is shown explicitly; no unrelated Chair is selected. Program Chairs themselves
are evaluated by the Dean, and teaching Deans follow the workload routing rule.
Self-evaluation is prohibited, including through account role switching.

When programs tie for highest teaching load, their Chairs share one evaluation.
Both can see and edit the saved ratings and comment. Version checks prevent stale
tabs from overwriting another Chair's edits. Either eligible Chair can submit the
shared draft after final review. Submission completes it for every tied Chair;
the result identifies the submitting Chair and contributes only once to scores.
Drafts are visible only to their eligible reviewer group, not unrelated evaluators.

The reviewer group and faculty identities are snapshotted on the first saved draft.
Later workload changes do not silently move started or completed evaluations.
Existing manual assignment rows are retained as evaluation record anchors, but
manual assignment actions and controls are retired. Routing is computed without
writes on page views; the evaluation anchor is created on the first draft save.
No new or altered database table is required beyond phase37.

Supervisor collection defaults to closed and is opened separately by the Dean.
Only the configured current academic term accepts writes. Evaluation pages follow
the existing Dean or Program Chair module viewing term, with no additional academic
year/semester selectors or Load button. Older terms are read only; stale forms are
rejected if the module viewing term changes before submission.

## Form and submission

The supervisor instrument is **SKSU-INS-EFP-03, Revision 01, January 14, 2026**.
It is stored independently from SET, with 15 original benchmark statements:

| Category | Items |
| --- | ---: |
| Management of Teaching and Learning | 6 |
| Content Knowledge, Pedagogy and Technology | 5 |
| Commitment and Transparency | 4 |

The form shows faculty, college, academic term, academic rank where recorded,
course codes/titles and program years from existing class lists. Its 1–5 frequency
scale and suggested means of verification follow the supplied supervisor form.
Evidence is guidance, not an automatic rating calculation or mandatory attachment.

Each evaluator saves a complete 15-answer draft and a visible comment. The final
review lists their pending faculty, mean and comment. All assigned pending faculty
must have current, complete drafts before confirmation. Explicit final confirmation
and the exact reviewed evaluation IDs, assignment revisions and draft versions are
checked on the server. A changed reviewer faculty-account link blocks access to the started evaluation.
Already submitted
faculty are preserved when additional faculty are assigned later.

Drafts are shared only with the eligible reviewer group recorded for the evaluation. Finalized ratings and original
comments are visible to authorized Dean and Program Chair accounts in the same
college. Submitted question wording, evidence guidance, answers, faculty/evaluator
names, evaluator identity/role and teaching/profile context are saved as snapshots.
Submitted results remain readable after account/roster/leadership changes. Names of
individual student evaluators do not appear in the shared combined reports.

All writes require a current active account/college, CSRF, fresh revisions and the
correct assignment. Collection, assignments and draft/final writes serialize on the
college row and revalidate access inside the transaction. Audit failures roll back
the action. The browser prevents repeated submit clicks; stale posts are also
rejected on the server. No DDL runs during requests or evaluation transactions.

## Combined scores and report

Only finalized supervisor records enter scores. The revised 15-question student
instrument is paired with the current supervisor instrument; legacy student
questionnaires remain separate on Student Results. Student subject exclusions apply
only to the student component. Mixed student responses keep their existing counted
review behavior; they never automatically exclude a supervisor evaluation.

- Student mean = eligible raw student points ÷ eligible answer count.
- Student component /60 = eligible raw points ÷ (answer count × 5) × 60.
- Supervisor mean = supervisor points ÷ 15.
- Supervisor component /40 = supervisor points ÷ 75 × 40.
- Final /100 = both unrounded components added, then rounded for display.

Category means use their own raw answer totals. Overall means use all answers, not
an equal average of category means. A missing/all-excluded student component or
missing supervisor component displays Pending, never an artificial complete score.
For the supplied sample, student mean 3.65 contributes 43.80 and supervisor mean
4.80 contributes 38.40, giving 82.20. Tests construct four independent complete SET
responses totaling 219/60 answers to reproduce that exact student mean.

**View Report → Print / Save PDF** prints the individual report with both category
breakdowns, weighted contributions, original submitted comments, supervisor identity,
submission reference and approval signature lines. Displaying/printing never records
an approval or electronic signature. Submitted timestamps are stored in UTC and
displayed in Philippine Time. Signature lines are separate from the supervisor's
saved evaluator identity. Report comments can span additional pages as needed.

## Setup and verification

Apply `backend/sql/phase37_supervisor_evaluations.sql` once to the intended database,
or run:

```text
php backend/tools/prepare_supervisor_evaluations.php
```

This repeatable setup creates only four new evaluation tables:

- `tbl_supervisor_evaluation_settings`
- `tbl_supervisor_evaluation_assignments`
- `tbl_supervisor_evaluations`
- `tbl_supervisor_evaluation_audit`

It does not open collection, choose reviewers, create real ratings or alter existing
tables. The existing student evaluation, college ownership and subject-exclusion
migrations must already be applied. There are no legacy imports or Management changes.

Run database fixtures sequentially:

```text
php tests/supervisor-evaluations.php
php tests/supervisor-evaluations-http.php
node tests/supervisor-evaluations-browser.cjs
php tests/evaluation-access.php
php tests/college-dean-evaluations.php
php tests/evaluation-subject-exclusions.php
```

Tests use isolated colleges and enclosing rollbacks, including before/after hashes
for original schedules, workloads, student evaluations and academic settings. Browser
POSTs are intercepted; real save/finalization/permission rules are exercised by the
database and HTTP suites. Browser QA covers desktop, tablet, phone and 320px widths,
long-form scrolling, fixed modal controls, restored drafts, required comments,
confirmation, shared results and print visibility. Screenshots are saved under `.tmp`.
