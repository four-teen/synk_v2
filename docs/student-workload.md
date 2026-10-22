# Student units and workload review

My Subjects has a fourth overview card for total credit units and weekly
lecture/laboratory contact hours in the student's selected academic year and
semester. Staff previews use the same calculation and existing college access
boundaries. For student 1956 in AY 2026–2027, Semester 1, the current records
produce 9 subjects, 27 units and 33 weekly contact hours.

Units come from each enrolled offering's linked prospectus subject. Contact
hours use the application's existing lecture/laboratory conversion, which
supports both stored laboratory credit units and stored contact hours.
Hours describe the curriculum requirement, not elapsed attendance or the sum
of overlapping timetable blocks. Repeated memberships for the same subject do
not multiply its units. Missing curriculum details appear as pending; partial
totals are labeled confirmed rather than presented as complete totals.

The College Dean dashboard has a full-width **Suspicious Workload Review** card
below Student Access and above Program Offerings Overview. It includes only
students with at least six distinct active subjects in the assigned college,
campus and selected academic term. Results have search, review/overlap filters,
sorting and ten-student pages. Show account opens the existing scoped preview
in the same tab, preserves the term, and returns to this dashboard card.

Review signals are overlapping class schedules, repeated subjects, membership
in multiple programs or year levels, and four or more sections. A high subject
count alone is not flagged as an error. Review priority puts schedule overlaps
and repeated subjects ahead of the softer program/year/section signals.
Expandable overlap details show subject codes, sections, days and times.
Merged lecture/laboratory schedules resolve to their effective owner; TBA
schedules, adjacent periods and an offering's own lecture/laboratory parts do
not create false self-conflicts.

The endpoint requires current College Dean access, an active college assignment,
POST and a matching CSRF token. Posted college/campus scopes are checked against
the assigned scope, and all roster queries repeat the college/campus/term
constraints. Dashboard reads never prepare schema, modify memberships or create
student profiles. No migration is needed.

Validation:

```text
php tests/student-workload.php --http
php tests/student-portal.php
php tests/college-dean-dashboard.php --http
node tests/student-portal-browser.cjs
node tests/student-live-activity-browser.cjs
```

The workload suite verifies unit/hour conversion, deduplication, pending and
empty states, overlaps, the six-subject threshold, selected-term population,
paging, search, parity between student/dean totals and endpoint access controls.
Data checks run in a read-only transaction; access fixtures roll back. Browser
checks cover desktop/tablet/phone widths, placement, same-tab links, escaping,
overlap details, paging, search, filtering and retry behavior. Local browser
screenshots are saved under `.tmp/student-workload-1600.png` and
`.tmp/student-workload-390.png`.
