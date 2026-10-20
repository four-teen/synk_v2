# Manual class additions in the Dean portal

To clear a class before correcting its faculty assignment, see
[Clearing a class list](clear-class-roster.md). That action preserves the student
directory and requires the separate phase29 history table.

Open **College Dean → Faculty Workload**, choose a faculty member and use
**Add Student** beside **View Students** and **Import CSV**. The same action is
available as **Add Student to Class** inside the student-list modal. It is
available only to the active Dean of the class's college, in the configured
current academic year and semester, after the audit table is prepared.

## Add an individual student

1. Enter the exact Student ID and choose **Look Up Student**. Leading zeros are
   preserved. Lookup searches the shared identity directory by exact ID; it
   reveals the matching name and identity revision without exposing another
   college's contact details or enrollment records.
2. If the ID exists, reuse its profile. Its saved name, email, program and year
   are retained. Profile corrections still belong in Student Management.
3. If the ID is new, enter the complete official names, the student's program
   within your college, and year level. The school email may remain blank while
   awaiting verification. Entered emails must be valid `@sksu.edu.ph` addresses
   and cannot belong to another directory student or conflict with an existing
   portal profile. This does not create a mailbox, password or Google account.
4. Choose **Late enrollee**, **Transferee** or **Class-list correction**. An
   optional note supports up to 500 characters.
5. Choose **Review Addition**, check the student and selected class, then
   **Confirm Addition**. Nothing is written until confirmation. **Back to
   Details** discards the previous review and allows corrections.

The destination subject, offering, class section, faculty, program and academic
term come from the assigned workload. The student's own program/year, when
creating a profile, are separate from the class's program/year. Lecture and
laboratory schedules share one membership. A duplicate membership is rejected.

## Section transfers

If the same student already has active memberships for the same subject in
another section within this college/campus and term, the review lists those
memberships and requires an explicit transfer acknowledgement. Confirming the
transfer marks the listed previous memberships inactive and adds the selected
destination membership in one transaction. The original rows remain available
for history, with their previous state copied into the audit record.

Memberships in other subjects, colleges or semesters are preserved. Existing
evaluation responses are preserved, and the college's evaluation controls still
govern whether the student can evaluate. Manual class-list changes do not
create or approve Registrar enrollment transactions.

A later CSV import skips an existing manual member and retains manual members
omitted from the CSV. A CSV attempting to add a student back into a different
section while a reviewed manual transfer is still active reports a conflict.
To intentionally transfer that student again, use the manual workflow and
review/confirm the new move. Removing the CSV conflict is required before
confirming that CSV import.

## Counts, tracing and access

Successful additions and transfers refresh the workload and relevant class-list
counts. Automatically derived offering counts use the existing `csv_roster`
source category, which covers the combined class list. Manually entered
offering counts are retained. Schedule rows, rooms, faculty assignments and
load formulas are not rewritten. Existing reports/metrics may reflect updated
roster counts when refreshed.

**View Students** marks entries as **Manual** or **Imported**. A manual entry
shows its reason and expandable addition details: the Dean, Philippine date/time
and note. These labels follow the saved membership rather than guessing from
the student's shared profile. The student portal reads the same active subject
memberships, so the class appears there after the addition.

Every request rechecks the active Dean's college/campus access and CSRF token.
Confirmation rechecks the current term, faculty assignment, profile revision,
class/schedule details and previous memberships. Reviews expire after 15
minutes, are scoped to the user/class/term, and are consumed on success. A
changed profile, assignment or membership requires another review. Concurrent
roster saves and profile creation use the existing import/email locks and
identity uniqueness safeguards. A failed save, including a failed audit write,
rolls back the profile, transfer, new membership and count updates together.
Program Chairs retain their existing CSV/view workflows; manual class-add
controls and the new endpoint are restricted to Deans.

## Live database preparation

Run `backend/sql/phase28_student_class_roster_audit.sql` in the application
database before deploying the changed files. Alternatively, with PHP CLI:

```text
php backend/tools/prepare_student_class_roster_audit.php
```

This creates **one new table**, `tbl_student_class_roster_audit`, with operation,
student/class/term identifiers, action, reason, note, previous membership
snapshots, actor ID/name and UTC time. No existing tables are altered. Preparing
the table is safe to repeat and does not add students or change class lists.
The local database is prepared; live preparation is separate. Request handlers
never create or alter database tables. Until prepared, existing CSV import and
class viewing continue, and the manual-add buttons stay hidden.

## Verification

Run database tests sequentially. HTTP fixtures roll back all student, membership,
audit and count data and verify protected evaluation, enrollment, scheduling
and login records remain unchanged.

```text
php tests/manual-class-roster.php
php -d extension=gd tests/college-dean-workload.php --http
php tests/program-chair-module.php
php tests/student-management.php
node tests/manual-class-roster-browser.cjs
node tests/college-dean-workload-ui.js
```

The browser suite uses the actual rendered PHP page and controllers with
isolated API responses. It covers both entry points, profile reuse/creation,
review, duplicate saves, transfers, escaping, failures and desktop/mobile
layout. Screenshots are saved under `.tmp`.
