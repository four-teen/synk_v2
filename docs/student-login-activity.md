# Student login activity

Open **College Dean → Students**. The **Login Activity** column shows each
student's successful sign-in count, with failed attempts below it. Click the
count, including zero, to open the wide history modal.

The modal shows successful logins, failed attempts, total attempts, success rate
and the last successful login. It lists the latest attempts first, with 25 rows
per page and a stable activity-ID tie break for equal timestamps. Filter by
successful or failed attempts. **Refresh history** fetches new arrivals; paging
keeps the loaded history snapshot stable.

Each event records the outcome, reason, submitted email, Google verification
status, UTC timestamp, direct connection IP, and browser/device information.
Times display in Philippine Time (`Asia/Manila`). The browser and device are
inferred from the supplied user agent, which is displayed as escaped text.
Forwarded IP headers are not trusted. No passwords, Google tokens, session IDs,
Google subject identifiers or OAuth codes are stored in activity records.

History counts student sign-in outcomes only. Opening a Google redirect is still
pending and is not a successful login. Cancellation, expired flows, rejected
requests and identity validation failures are failed outcomes. Only completing
the authenticated Google flow creates a successful event. Dashboard visits,
refreshes, Show Account previews, staff sign-ins and reading history do not
create student logins. Activity-storage errors do not block a verified login.

An unverified failure means someone attempted to use the submitted student
email; it does not prove the student performed that attempt. The email must
match exactly one directory record to attach the failure to a student ID.
Unknown or duplicated addresses, and callbacks without a matching OAuth state,
remain unassigned. The verified Google email of another person is not exposed
in the student's history. Changing a student's email does not relink the saved
student ID or erase the email used for an older event.

## College access

Only an active College Dean assigned to the student's college can read this
history. Every request revalidates college/campus access, student membership,
and CSRF. Program Chairs cannot read the endpoint or see the column/modal in
their shared student view. Guessed IDs outside the Dean's directory are denied.

The student list's academic-term filters determine which students are listed.
Their login counts and modal history cover all recorded terms because signing
in belongs to the shared student account. No academic evaluation, subject,
scheduling or workload records are modified.

## College activity page

Open **College Dean → Student Login Activity** (`college-dean/login-activity.php`),
or use **View all student login activity** from the Students page. This provides
one college-wide history without opening individual student accounts.

The initial date range includes today and the previous four Philippine calendar
days. Both endpoints are inclusive; the backend converts midnight boundaries to
UTC and uses an exclusive upper boundary for the following day. Choose a custom
range of up to 366 days, or use Today, 5 days, 7 days and 30 days. Dates cannot be
in the future. Academic-term selection in the navigation does not change this
account-activity report.

The page provides:

- Total attempts, successful logins, failed attempts, distinct students who
  successfully logged in, distinct students with failures, and success rate.
- A daily comparison of successful and failed sign-ins, including zero-activity
  dates, and up to five common failure reasons.
- Up to five students with the most failed attempts, with the last failure and
  an indication when no successful sign-in exists within the selected range.
- A **Trace** action, also available by clicking a student's name in history,
  that filters to the exact student ID. **Show all students** clears tracing.
- Program filters and literal text search across student ID, name, current
  email, email used for the attempt, and direct connection IP address.
- Newest-first history with 25, 50 or 100 attempts per page. Paging preserves
  the applied filters and event snapshot; unsaved filter edits do not alter the
  next page. Refresh loads new arrivals.

Statistics, daily comparisons and failure lists reflect the date, program,
search and traced student. The history outcome selector affects the history
rows only, so the full success/failure comparison remains visible above it.
Multiple subject or program memberships do not duplicate an event. A student
can appear in both distinct-success and distinct-failure totals.

Only students within the Dean's existing college/campus directory scope are
included. Unknown/unassigned attempts and other colleges' records are excluded;
an address resembling a college student's email is not used to guess ownership.
All requests revalidate the active Dean, assignment and CSRF token. Program Chair
navigation omits this page and its API rejects other roles. Reading the report
does not create sign-ins, change records, or prepare database tables.

This report reuses `tbl_student_login_activity` from phase 27. It requires no
additional tables, columns, indexes or migration queries.

## Preparation

Run the SQL in the target database before deploying the updated authentication
and student-directory files:

```text
backend/sql/phase27_student_login_activity.sql
```

Alternatively, on a server with PHP CLI configured:

```text
php backend/tools/prepare_student_login_activity.php
```

This creates **one new table**, `tbl_student_login_activity`. It does not alter
existing tables. Both preparation methods are safe to repeat and do not erase
history. Read endpoints do not create tables or change records. The local
database has been prepared; run the migration separately on the live server.

Recording begins when these changes are enabled. Earlier successful sign-ins
cannot be reconstructed. The existing `tbl_student_login_issues` groups repeated
failures for Administrator review and stays intact; its aggregate counts are not
fabricated into individual activity events.

## Verification

Run database suites sequentially; fixtures roll back all event and student data:

```text
php tests/student-login-activity.php
php tests/student-portal.php
php tests/college-dean-students.php
php tests/dean-login-report.php
node tests/student-login-activity-browser.cjs
node tests/dean-login-report-browser.cjs
node tests/college-dean-students-ui.js
node tests/student-management-browser.cjs
```
