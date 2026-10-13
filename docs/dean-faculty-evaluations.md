# Dean faculty evaluation results

The College Dean sidebar includes **Faculty Evaluation**, at
`college-dean/faculty-evaluations.php`. The page follows the Dean portal's selected
academic year and semester. Its year and semester controls also update that
shared viewing term.

The overview lists faculty assigned to the college or present in its imported
subjects for the selected term. Faculty remain visible when no evaluations have
been submitted. Search filters the names; **Show Evaluation** opens the selected
faculty's results, with the faculty list collapsed for easier reading.

The page shows submitted evaluation counts, draft counts, and student evaluator
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

This page reads the existing evaluation tables without schema changes. It accepts
GET requests only. The clarified request was a clean start before the first
official evaluation; the local inventory found zero headers and zero answers, so
no cleanup was required and no continuing reset feature was added.
