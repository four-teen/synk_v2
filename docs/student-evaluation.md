# Student Evaluation of Teachers

## College access and academic terms

Student drafts and final submissions require the current academic year and
semester and an open evaluation period in the enrollment's college. Both checks
run again in the persistence transaction. Each college is submitted separately,
so a closed college cannot block another college's evaluation. The same professor
can have separate responses for the same student and term in different colleges.

Previous terms are read only. Responses and original instruments are preserved;
new terms create separate responses and default to closed with faculty results
hidden. See `docs/evaluation-access.md` for the complete workflow.

Students receive required guidance when an enrolled current-semester college opens
evaluation. It returns on later page loads until all required faculty evaluations
are submitted; saving drafts does not dismiss it permanently. Start opens that
college's faculty list. Complete remaining drafts can be submitted after a new
faculty is added, while earlier submitted responses and verification codes stay
locked. Missing answers, missing comments or old drafts cannot unlock final review or finalization.
The prompt is read only and does not extend idle sessions or change Dean controls.

Each new or editable faculty evaluation requires a comment with visible text,
validated in the form and on the server for both draft saving and final submission.
Earlier unsubmitted drafts without comments must be completed before submission;
previously finalized responses remain unchanged. Final review displays only each
faculty's name, average and comment. On phones and tablets it fills the available
viewport, with a fixed header and footer and a scrolling review area. The ready
Review Final Evaluation button is orange with a gentle heartbeat effect and a
clear final-submission note; reduced-motion preferences disable the animation.

Final submission confirmation is rendered inside the review modal, above its
content, so Bootstrap's focus trap and overlay do not cover or disable it. Review
Again and Escape return focus to Submit Final Evaluation; background clicks do
not submit. Confirming sends one request and shows a disabled submitting button.

New student evaluations use SKSU-INS-EFP-01 Revision 01, effective January 14,
2026. The questionnaire contains 15 statements, in this order:

| Category | Statements |
| --- | ---: |
| Management of Teaching and Learning | 6 |
| Content Knowledge, Pedagogy and Technology | 5 |
| Commitment and Transparency | 4 |

The wording, category descriptions, and operational scale definitions are
transcribed from the supplied instrument. The online instruction uses dropdown
selection and a comments field in place of paper response handling.

| Rating | Description | Frequency anchor |
| --- | --- | --- |
| 5 | Always manifested | 91–100% of relevant instances |
| 4 | Often manifested | 61–90% |
| 3 | Sometimes manifested | 31–60% |
| 2 | Seldom manifested | 11–30% |
| 1 | Never/Rarely manifested | 0–10% |

## Calculation

The attachment defines frequency anchors for each response option. It does not
specify an aggregate percentage formula, category weights, or a 60% student
component. The application's existing points-based calculation is retained and
explicitly labelled **normalized score**:

- Mean = total rating points ÷ answered questions.
- Normalized score = total rating points ÷ (answered questions × 5) × 100.
- The separate student component = unrounded normalized score × 0.60.

Each complete new evaluation has 15 answers and a maximum of 75 points. Category
scores use the relevant category's question count; the overall score uses all
answers, rather than averaging the three category percentages equally. Across
students, reports add the raw point totals and answer counts before calculation.
Only the final displayed mean, percentage, and component are rounded to two
decimal places. Drafts are excluded from administrator reports. No responses
produce zero summary values and no faculty report rows.

College Dean subject exclusions affect aggregate scoring only. Students still
evaluate every linked teacher and finalize normally, with a separate record for
each teacher in each college and term. Submitted responses and comments remain.
Original rating-sheet rows show their eligibility; aggregate reports use eligible
responses. All-excluded results show no eligible evaluations instead of a 0% score.
See `docs/dean-faculty-evaluations.md` for mixed-subject and historical review rules.

The frequency bands are response-selection guidance. The normalized percentage
is not a measured percentage of observed behavior. Ratings are not converted to
band midpoints, and no unsupported qualitative cutoffs are applied to aggregate
means. The existing 60% student component remains separate from the student
score. The Dean and Program Chair **Combined Faculty Results** page now combines
it with finalized supervisor evaluations; see `docs/supervisor-evaluations.md`.
The administrator's existing student-only report remains separate.

## Saved responses

`instrument_version` records which questionnaire a response uses. Existing rows
are labelled `legacy-20`; new saves use `sksu-set-2026-rev01`. Submitted historical
evaluations remain locked with their original 20 questions and scale. Staff
preview selects the original instrument for saved responses.

An unsubmitted old draft must be answered and saved using all 15 revised
statements before final submission. Old ratings are not copied into new
questions. Saving the revised draft keeps its comment and replaces its answer
snapshot with the revised instrument. The server rejects finalization of an old
draft even if a request bypasses the interface.

Administrator reports default to the revised instrument. The instrument filter
provides a separate view of historical responses, including their original
category breakdown. Navigation and academic-term changes retain that selection;
the two instruments are never combined into one aggregate score.

The schema helper adds the version column when absent. For managed deployments,
`backend/sql/phase25_student_evaluation_instrument.sql` can be applied once before
serving the updated application. Do not run the standalone migration again if
the column already exists.
