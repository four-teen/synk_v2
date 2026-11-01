# Professor evaluation results

`professor/faculty-evaluations.php` follows the administrator's current academic
year and semester. College comes from the signed-in account's college assignment,
falling back to its linked faculty record when the account has no college set.
There is no college or academic-term selector; obsolete URL parameters cannot
override this context. Student results require the Dean's **Faculty results**
release for that exact college and term, independently of collection being open. The
server revalidates the active Professor account and faculty link on every request;
request-supplied faculty IDs never select the report's owner. No-cache headers and
a final release check also apply to the result page.

The Student evaluation and Supervisor evaluation tabs remain visible. The
Supervisor tab is a placeholder for later work; it loads no subjects, completion
counts, or reports. No schema changes are required.

Assigned classes appear as one full-width card per class, with the exact workload
subject summary: subject code and orange title, meeting times, rooms and section.
The page reuses `workload-class-summary.php`, `workload.css`, and the existing
workload read/merge logic, then scopes classes by college ID. Subject search works
across title, code, section, schedule and room. Mobile cards remain a single column.

Each Student card displays **Total students**, **Evaluated**, and **Not evaluated**.
Totals use distinct students from the actual current class roster, including the
existing merged-class and offering-zero enrollment matcher. Recorded enrollment
estimates are not used. Evaluated counts distinct roster students with a submitted
faculty evaluation in the same college and term. Drafts, other faculty, other
colleges, and other terms do not count; score exclusions do not change completion.
A student taking multiple classes counts once in each class they attend. The
remaining roster count is Not evaluated. The initial page sends aggregate results
and counts; student names and numbers load only when that class's pending list is requested.
Hiding the release suppresses counts, student lists, exports, scores and comments.

**Not evaluated** is a button that expands the current card to show only roster
students without a submitted faculty evaluation. **Export CSV** downloads a fresh
list of those students with subject, section and term context. Formula-like cell
values are escaped for spreadsheet use. Submitted students, email addresses and
other profile or login details are never part of this export.

CSV output uses RFC 4180 quoting and CRLF line endings, with a UTF-8 marker for
Excel. It supports the deployed PHP 7.1 runtime, including quoted names, commas,
newlines and accented characters. Each row includes subject code, title, course,
section, academic year and semester. The download uses the server's filename:
`Subject code - Subject title - Course - Section - Not evaluated.csv`.
Windows-invalid characters are removed and long components are shortened without
dropping the course and section. An empty/invalid download response is reported
as an error instead of saving an empty file.

**View subject results** expands the same card to show results linked to that
subject, restricted to students on that class's current roster. Saved subject associations take precedence; older responses
use exact original subject labels matched to original enrollment evidence. Missing
or ambiguous associations are omitted rather than inferred from today's classes.
If a linked response covers multiple subjects, the panel explicitly says that its
original combined ratings are included. Separate ratings were not collected and
are never invented. Student questionnaire versions stay separate; the existing
score-exclusion and anonymous-comment rules still apply. Reports contain category
and statement scores, the original scale, and escaped submitted comments, without
student identities or individual-response links.

**Consolidated evaluation results** appears below the full subject list, with
unique roster completion counts and one score summary per questionnaire version.
It covers all assigned classes regardless of the subject search filter. Category,
statement and anonymous-comment details expand beneath the summary.

Consolidation unions the exact submitted response IDs used by the subject panels.
A response linked to several subjects or sections contributes once, rather than
being counted once for every card. Responses not linked to an assigned subject and
its current roster, drafts, other faculty, other colleges and other terms are
excluded. Score eligibility rules are then applied to that distinct response set;
excluded responses still count toward completion, and their submitted comments
remain available under the existing release rules. The current faculty, class
assignments, college, administrator term and Dean release are rechecked before
returning the consolidated report. No response or score is written by this view.

For each questionnaire version, calculations use raw totals:

- **Overall mean /5** = eligible rating points / eligible answer count.
- **Normalized score %** = eligible rating points / (eligible answer count * 5) * 100.
- Category and statement means use only their own eligible points and answer counts.
- Questions have equal weight; subject cards and category means are not equally
  averaged. Rounding occurs only after each final calculation, to two decimals.
- Zero eligible answers displays no score rather than 0%.

For example, two distinct 15-answer responses scoring 60 and 75 points produce
135 / 30 = 4.50 out of 5, or 90.00%. If the 60-point response appears in two subject
cards, it still contributes just 60 points and 15 answers to the consolidated
result. Supervisor scores are not included; that tab remains a placeholder.

The read-only `faculty-evaluation-details.php` controller resolves account identity,
current college/term, release and class assignment for each panel or CSV request.
Stale page context is rejected. It rechecks access after loading the data, and
returns no-store responses. Panels clear old content while loading, support retry
and close, abort superseded requests, and ignore late responses. Only one view is
shown within each card; different subject cards can expand independently.

Validation:

```text
php tests/evaluation-access.php
php tests/professor-evaluations.php
node tests/professor-evaluation-details-http.cjs
php tests/professor-evaluation-export.php
C:/VertrigoServ/Php/php.exe tests/professor-evaluation-export.php
```

Run database suites sequentially. All fixture changes are rolled back. To render
the actual page with temporary QA records for browser inspection:

```text
php tests/professor-evaluations.php --render student C:/VertrigoServ/www/synk/tmp/professor-evaluations-student-preview.html
php tests/professor-evaluations.php --render supervisor C:/VertrigoServ/www/synk/tmp/professor-evaluations-supervisor-preview.html
```

The Student preview also creates the loopback/token-protected
`tmp/professor-evaluation-preview-details.php` route containing only synthetic
panel and export responses. Delete that route and preview HTML after visual QA.
These previews use the installed application assets. They do not change real release settings,
evaluation records, workload assignments, academic settings, or Management data.
