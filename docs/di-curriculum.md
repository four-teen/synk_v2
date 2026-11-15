# DI curriculum management

The Director for Instruction can manage Courses / Programs, Subject Masterlist,
Prospectus Builder, and Prospectus Browser from the DI sidebar. Prospectus and
subject pages reuse the Administrator forms and curriculum data. No new schema or role assignment is
required for an existing active DI account.

The DI Prospectus Builder follows the Program Chair's program-first workflow:
choose a program, browse its versions with subject/unit totals, then load a version
or create one in a modal. The loaded version's CMO and effectivity stay visible
above its year/semester structure. Copy Prospectus uses that loaded version as
the source and opens the new copy after saving. Versions sort by newest effectivity
first, and `?pid=` links load the requested version. DI retains its university-wide
program list and DI API routes, including campus labels for copy destinations.

DI's Year & Semester Structure groups saved semesters by year. First and Second
Semester appear side by side on desktop; an existing Summer spans the next row.
All groups start expanded and can collapse independently or through Expand all /
Collapse all. Smaller screens use one column. Each semester header combines its
name and subject count on one line, with Add Subject beside it. There is no extra
semester footer; the subject table retains its existing totals row. Subject cells
are vertically centered and have no row numbers.
Subject code and title share a cell,
with prerequisites beneath; lecture/lab hours, unit values, totals, and subject
order retain the existing reader's values. Add and Edit retain the existing forms.
Each subject has vertically stacked Edit and Remove icons; removal uses the same
confirmation and safeguards for referenced curriculum. DI cannot remove year/semester
groups: the control is absent and direct API requests are rejected. This is
DI-specific behavior; it creates no missing semesters or database records.

DI pages retain DI navigation and use dedicated `query_di_*` routes. Every request
checks the active account and DI role. Writes also require the session CSRF token,
validate their input, and commit only successful responses. The session role,
assigned college, academic settings, and scheduler working term are not replaced.

DI cannot change the structure or header, move, or delete a prospectus referenced
by offerings, section curriculum, section subjects, or saved schedule rows. Copy
an in-use prospectus to a new version to revise it. DI deletion removes unused
curriculum rows only and never invokes the Administrator's schedule/workload
deletion cascade. Program management requires a campus selection and offers only
that campus's colleges. Its summary counts programs (including inactive records),
colleges, prospectus versions, and distinct linked subjects for the current scope.
The table combines each program's code and name in one column. Program deletion
sets its status to `inactive`, including when academic records already reference
it; the program and all related records are retained. Edit can reactivate it.
Referenced programs retain their college, and referenced subjects retain their status.
Names and descriptions remain editable. Existing Admin, Program Chair, and
Scheduler permissions and curriculum calculations remain in their own handlers.

Validation commands:

```text
php tests/di-curriculum.php
node tests/prospectus-builder-ui.cjs --di
node tests/di-curriculum-browser.cjs
php tests/program-chair-curriculum.php
php tests/professor-workload.php --database
```

The PHP fixtures use transactions and roll back all temporary records. Browser
tests render those pages in a separate headless Chromium profile and intercept
every data request; they do not write through the browser.
