# Professor LMS gradebook

Open **My Workload → Manage Class → Grade Book**, or use the Grade Book class tab.
Each professor/class offering/academic year/semester has separate Midterm and
Final records in the configured LMS database. Existing assignment and scheduling
helpers are read only; the gradebook cannot assign classes or edit schedules.

The component names, order, descriptions and weights come from **Manage Grading
System** for that subject and period. An unconfigured period requires a saved
100% grading system before entries can be added. There are no default report,
presentation or project columns. The reference XLSX is a calculation reference;
its students and historical scores are not imported.

## Entries and scores

**Add manual entry** opens a short form for face-to-face work under any saved
grading component. The selected Midterm or Final period is preselected; the
professor can choose another configured period before creating the entry.
**Create and enter scores** saves the title, component, maximum and optional
date, then opens the full class roster for that exact new entry. The server
returns its saved item ID so concurrent entries cannot open the wrong roster.

Score encoding supports Enter to move to the next visible student and Shift +
Enter to move back. Name/number search filters the roster without changing its
order. Progress counts the entire class and distinguishes blank/unrecorded
scores from recorded zeros. Invalid or excessive scores are flagged beside the
input and block saving. Notes stay collapsed until needed; LMS overrides still
require a note. Reverting an edit to its saved value clears its unsaved state.

**Paste scores from Excel** accepts a single score column in full-roster order,
starting with an explicitly selected student. The preview shows each student,
current score, proposed score and replacement or validation status. Blank lines
preserve current scores while retaining their positions. Extra columns, invalid
scores and rows extending past the roster block application. Applying the
preview updates the local score table only; **Save scores** records the changes.
Pasting a multiline column into a manual score input opens the same preview,
starting with that student. Search filters do not alter paste mapping.
Editing scores after a preview invalidates it so the current-score comparison
must be reviewed again. Saving with an unapplied paste asks the professor to
apply or close it first.

`tests/manual-entry_lms_browser.js` runs through the connected browser with
intercepted mutations in its own test tab. Prepare its HTML and JSON using
`php tests/gradebook_lms.php --render` with absolute output paths ending in
`.tmp/manual-entry_lms.html` and `.tmp/manual-entry_lms.json`. The PHP fixtures
roll back; the browser checks creation, period/component selection, keyboard
entry, zero handling, validation, paste previews, save failure recovery,
corrections, mobile widths, attendance and LMS override notes without production
writes.

**Open graded work** opens a wide table modal. Its button counts all active
manual entries and published/closed LMS assessments in the selected period,
including work not yet assigned to a component. The semester summary opens work
from both periods. A mapped assessment appears only once, and archived entries
are excluded. Each row has a component button that opens an in-modal selector;
saving the assignment immediately recalculates the component and period totals.
Scores and entry editing remain available from each row. On first load, Final
is selected if only Final has recorded/submitted work; subsequent refreshes keep
the professor's selected period.

- **Manual score** covers handwritten exams, quizzes, recitation, lab exercises,
  attendance totals, or any other work. Enter the title, maximum points and
  optional date. Scores accept two decimal places and must be 0–maximum.
- **Attendance session** records present = 1 and absent = 0, with a maximum of 1.
  Leave unrecorded students blank. Bulk marking fills only unrecorded students
  after confirmation, and still requires Save scores. Sessions live entirely in
  LMS and have no effect on class schedules or Management attendance.
- **Linked LMS assessment** explicitly maps a published/closed assessment from
  the same class and period to one component. The maximum comes from the
  assessment and stays fixed. An assessment cannot count twice in the class,
  including after archiving an entry. Objective submitted/expired attempts are
  checked using each question's points and original assessment snapshot. A
  changed assessment maximum is normalized to the entry's fixed maximum.
  Written or unkeyed answers contribute zero until manually scored, while
  objective question points continue to contribute to the total.

Every entry supports manual score recording. A manual reviewed LMS total
requires a note; clearing it restores the automatic result. **Missing scores
count as zero in calculations**, including students without an LMS submission.
The original score fields remain blank until recorded; calculation does not
write synthetic zero records or overwrite submissions.

Entry title, component, date and manual maximum can be edited. The maximum
cannot drop below an existing score. Archive excludes an entry from calculation
and retains its record, score rows and audit history.

## Print grade sheet

**Print grade sheet** opens a separate print preview using the supplied
GRADESHEET.docx layout and its original university seal, Bagong Pilipinas logo,
and stationery. The seven columns contain family-first student names,
program/year/section, Midterm percentage, Final percentage, semester average,
numerical rating, and remark. The name column uses 34% of the table width so the
current class's full names fit on one line at the existing font size.
All grades come from the same LMS semester summary;
the sample student and sample 1.75 rating in the Word file are never imported.
The legend uses the existing LMS 96–98 band for 1.25, correcting the document's
overlapping 96–93 typo.

The preview includes the complete named roster, regardless of gradebook search
or selected period, on 8.5 × 13 inch portrait sheets. The date, GRADESHEET title,
campus, semester/year, course and professor appear only on the first sheet.
Each sheet repeats the university letterhead, column headers and institutional
footer. Pagination measures the actual printed row heights and fills each sheet
before continuing, including the space freed by omitting the first-sheet heading.
Exactly one blank row separates the final student from the summary. No other
blank student rows are inserted. If the closing section needs another sheet,
the last student moves with it to keep the record, separator and summary together.
Only the final sheet includes the whole-class summary totals,
signatures, legend, Dean's approval and acknowledgement receipt. The receipt uses
the Word template's thin dash-dot frame, 10-point Arial, blank Date/Time areas,
By line and centered representative label. The institutional footer retains its
centered black 6-point Arial, original paragraph breaks and pipe separators.
An unconfigured period prints blank. Configured periods retain the gradebook's zero-score rule.
The date uses Asia/Manila. Printing is blocked during score saves or unsaved edits.

Program/year/section is read from matching active class enrollment, including
merged offerings and enrollment rows without an offering ID. Sex is read only
from approved enrollment for the same academic year and semester. If any student
has no recorded sex, Male/Female totals print as unavailable; the explanation
appears only in the preview toolbar, outside the printed form. The class total
always includes every named student. No sex is inferred from names.

The print endpoint revalidates the professor's active account, role, faculty link,
assignment, and signed Grade Book context. It does not write grade records or
introduce report tables. All implementation stays in LMS-specific files; shared
academic settings, workloads, scheduling, and Management are unchanged.

Validation: `php tests/gradesheet_lms.php` checks printed grade parity, null/zero
handling, names, pagination, enrollment metadata, membership and signed access,
with all database fixtures rolled back.

## Calculation

For each student, within each component:

`contribution = sum(earned points) / sum(maximum points) × component weight`

Items are weighted by possible points within their component. A 30-point
activity therefore has three times the influence of a 10-point activity in the
same component. The period grade is the sum of all component contributions.
All assigned entries contribute their maximum points to the denominator, even
when a student has no score. Components with no entries contribute zero. For
example, scores 1/4 and an unrecorded /15 quiz in a 20% component contribute
`(1 + 0) / (4 + 15) × 20 = 1.052631...` to the period grade. The main table shows
only the numerical component totals, period grade and rating.

Nonzero score cells, component totals, period grades and semester results use
a light green background. Zero and unrecorded scores keep the normal background.
Rating and status cells follow the underlying grade so a 5.00 rating for a zero
grade does not imply recorded work. Score inputs update their highlight as the
professor types; clearing an LMS override shows the automatic score's highlight.

Saving an entry, component assignment or score returns freshly calculated
Midterm, Final and semester results together. The open overview refreshes from
LMS every 30 seconds, when returning to the tab, and when changing periods.
Refresh pauses during score entry, component assignment and open dialogs, so
unsaved changes are preserved. Newly submitted linked assessments enter the
calculation on the next refresh; newly published work appears in the work list
and requires a component assignment before it contributes to grades.

Midterm and Final are independent and can have entirely different components.
The reference Gradesheet uses `(Midterm + Final) / 2`, so the semester summary
uses 50% of each configured period grade. No semester average appears until
both periods have a saved grading system totaling 100%.

The user-provided legend supersedes the earlier spreadsheet rating formulas:

| Grade range | Rating |
| --- | --- |
| 99–100 | 1.00 |
| 96–98 | 1.25 |
| 93–95 | 1.50 |
| 90–92 | 1.75 |
| 87–89 | 2.00 |
| 84–86 | 2.25 |
| 81–83 | 2.50 |
| 78–80 | 2.75 |
| 75–77 | 3.00 |
| 73–74 | 4.00 Conditional |
| 72 and below | 5.00 Failed |

Ratings use the minimum threshold of each band, without rounding a grade up
into the next band: 98.99 remains 1.25 and 99.00 becomes 1.00; 72.99 remains
5.00 and 73.00 becomes 4.00. Percentage display uses two decimals. Ratings up to
3.00 are Passed, 4.00 Conditional and 5.00 Failed. These are professor working grades, not automatically
published to students, shared academic reports or official transcripts.

## Revision history and data boundaries

The first gradebook entry freezes the saved grading scheme's component IDs,
weights, names and revision. Later changes in Grading system produce a notice;
they do not silently change existing grades. Review the latest setup and choose
**Review & apply latest revision** to explicitly recalculate the period. Active
entries referring to removed components must first move to a retained component
or be archived. Scores are preserved.

Requests check active professor account/role/faculty link, live workload
ownership, session-signed gradebook view and CSRF. Score entry is restricted to
the exact current named class roster, including existing merged memberships.
Every mutation checks the book and scheme revisions, runs transactionally, and
records an audit event. Failed/stale saves retain local edits. All changes in a
score batch are validated before any write; any failure rolls back the request.

Run `php backend/install_gradebook_lms.php` once (safe to rerun). It creates only
these four tables in the **separate LMS database**:

- `tbl_lms_gradebook_periods`
- `tbl_lms_gradebook_items`
- `tbl_lms_gradebook_scores`
- `tbl_lms_gradebook_logs`

Web requests do not install or alter tables. Core academic settings, scheduling,
workloads, shared student profiles, and Management financial settings/history
are neither written nor synchronized.

Checks: `php tests/gradebook_lms.php`, `php tests/gradebook-http_lms.php`, and
`node tests/gradebook_lms_browser.cjs`. CLI/browser data fixtures roll back; HTTP
checks use tagged temporary LMS records and remove exactly those records.
