# Student Evaluation of Teachers

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

The frequency bands are response-selection guidance. The normalized percentage
is not a measured percentage of observed behavior. Ratings are not converted to
band midpoints, and no unsupported qualitative cutoffs are applied to aggregate
means. The existing 60% student component remains separate from the student
score; supervisor contributions and combined totals remain pending manual input.

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
