# DI curriculum management

The Director for Instruction can manage Courses / Programs, Subject Masterlist,
Prospectus Builder, and Prospectus Browser from the DI sidebar. Prospectus and
subject pages reuse the Administrator forms and curriculum data. No new schema or role assignment is
required for an existing active DI account.

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
node tests/di-curriculum-browser.cjs
php tests/program-chair-curriculum.php
php tests/professor-workload.php --database
```

The PHP fixtures use transactions and roll back all temporary records. Browser
tests render those pages in a separate headless Chromium profile and intercept
every data request; they do not write through the browser.
