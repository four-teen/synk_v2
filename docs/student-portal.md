# Student Portal

Students sign in at `/synk/student/login.php`. The staff portal remains `/synk/`.
The Student form requires the school email in the Student Directory and sends
the student through the existing SKSU Google OAuth configuration. It reuses the
registered `backend/auth_google_callback.php`; no new OAuth client or callback
registration is required when the existing configuration works on that host.

This local installation currently uses
`http://localhost/synk/backend/auth_google_callback.php`. Before opening the
Student Portal to users on a public host, configure the callback on the same
public HTTPS origin as the Student page and register that exact URI in the
Google OAuth client. Google requires HTTPS for public callback URLs; only
localhost callbacks are exempt. The entered HTTP address
`http://isulan.sksu.edu.ph:8082/synk` therefore needs a public HTTPS setup for
Google authentication. Changing only the application URL does not register a
Google callback. The existing local credentials and redirect URI were retained.
See [Google's redirect URI rules](https://developers.google.com/identity/protocols/oauth2/web-server#uri-validation).

The application checks the entered address before opening Google. Invalid,
unmatched, or duplicated addresses are recorded for Administrator review.
After Google verifies the account, the callback requires the same entered email
and exactly one matching student. It binds the session to that student ID.
Directory changes or duplicate emails invalidate a previously authenticated
session. Names, query parameters, and profile form values do not authorize
access to another student's subjects.

Student and staff OAuth states are matched separately, so starting a sign-in in
one portal does not consume a pending sign-in in the other portal. Both portals
use the application's existing session cookie; completing a sign-in changes
the active account for that browser session.

## Dashboard

Student sessions now sign out after 15 minutes without activity. Background
status checks do not reset that timer. The College Dean dashboard shows a live
student grid and successful-login counts for the selected academic year and semester; see
[student-live-activity.md](student-live-activity.md) for behavior and deployment.

The dashboard reads active imported enrollment records for the signed-in student.
Each subject appears in a full-width row with the professor's circular profile
photo, orange professor name, subject title and compact schedule lines. Lecture
and laboratory entries separated by semicolons or newlines appear on separate
lines. Room, section and subject code sit below the schedule. Photos use the
linked professor account or faculty-record profile storage; missing photos use
a neutral person icon. Missing details are labeled "To be posted". It does not change schedules,
faculty workloads, enrollment lists or Administrator academic settings.

The current academic term is selected when it has imported subjects. Otherwise,
the latest imported term is shown with a clear notice of the current term.
The cog opens a term-selection modal for reviewing the student's own imported
terms. Apply saves that student's viewing preference; Cancel preserves the
displayed term. Search filters the displayed cards on the client.

Profile setup does not block the dashboard. The existing one-time profile
setup is retained at `student/profile.php`, with CSRF validation. Existing
Prospectus and Faculty Evaluation pages are retained.

My Profile starts with a compact Student Workspace card, followed by the academic
year subject listing. The separate Student details card is removed. Its subject
rows use the same shared `student/subject-card.php` view as My Subjects, including
professor photos, orange names and compact schedules. The academic-year filter,
semester groups and first-time profile setup remain available.

On screens below 1,200px, the student sidebar becomes a fixed bottom navigation
bar on all four portal pages. It contains the same available Subjects, Profile,
Prospectus and Faculty Evaluation destinations, including preview parameters and
evaluation availability rules. Content has space below it for the bar and device
safe area. Desktop keeps the sidebar. Mobile pages allow pinch zoom.

## Faculty Evaluation

Faculty Evaluation opens the assigned college and academic term directly. Its
overview has no term, college or faculty dropdowns and no Load Faculty List or
Select Faculty step. All assigned professors appear together. Their subject rows
reuse the My Subjects photo, orange name, title and compact schedule layout.
Each professor has one prominent evaluation action for all their subjects, on
the right on desktop and full width below the subjects on smaller screens.
Saved drafts, submitted evaluations, archived terms and staff previews retain
their existing view/edit restrictions. Draft forms carry term, college and faculty
identifiers in hidden fields. The server checks enrollment and collection access
for draft saves and final submissions, and finalizes the complete faculty list.

On screens below 992px, the SET form opens as a full-screen modal. Its header and
Close/Save Draft actions stay visible while the questions and comments scroll
inside the form. The layout follows the available viewport height and includes
device safe-area spacing. Larger screens retain the regular dialog.

## Dean account preview

**Show Account**, beside a student name in the Dean Student Management page,
opens the actual Student dashboard in the same tab. The Dean remains signed in as
Dean. Profile, Prospectus and available Faculty Evaluation links retain the selected student's preview ID and term,
and the return link goes to the Dean directory. All preview requests are
read-only; server-side guards reject POST requests.

The Faculty Evaluation menu follows the student's current college collection
access, including in Show Account. It is hidden when collection is closed and
appears when the college opens the current term. Faculty result release does not
control this student menu. The Dean can still review saved evaluations through
the Dean's own Faculty Evaluation reports.

The application rechecks the Dean's active role, college and campus on each
request. The student must belong to that college's imported lists or its manual
directory. Preview subjects and term choices are limited to that college.
Faculty Evaluation includes the question forms, saved draft and submitted ratings,
comments and completion status for faculty linked to active enrollments in the
Dean's college and campus. Rating controls and comments are read-only, and both
draft saves and final submissions are blocked on the server. Preview reads do
not create or alter evaluation tables. Student and Administrator views retain
their existing subject scopes.

The individual **Delete** button uses the existing term-record removal process.
Its preview includes only the clicked student, covering that student's class
lists throughout the current college and semester. Confirming removes those
list entries while retaining the shared student profile, email and other terms.
It does not add that student to, or clear, an existing bulk selection. Historical
and year-wide views remain read-only. This update adds no tables or columns.

## Administrator review

Open **Access and Term Control → Student Sign-in Issues** at
`administrator/student-login-issues.php`. The page shows the submitted email,
Google email and name when Google has verified the identity, the reason,
attempt count, and last attempt. Entered addresses are not represented as
verified identities. Search the Student Directory and correct the email there.
"Mark reviewed" stores a note; it does not automatically change student data.
A repeated failure reopens the matching issue while retaining its review note.

Only an active Administrator can access or review issues. Both Student sign-in
submission and issue review validate session-bound CSRF tokens. Student sign-in
starts are limited to ten attempts per session within fifteen minutes.
OAuth flows expire after fifteen minutes. OAuth tokens, passwords, Google
authorization codes, and ID tokens are not stored in diagnostic records.

## Database change

One new table: `tbl_student_login_issues`.
Run `backend/sql/phase24_student_login_issues.sql` when deploying to another
database. It is additive and can be run again. No existing tables are altered.

Columns: `issue_id`, `issue_key`, `submitted_email`, `google_email`, `google_name`,
`failure_code`, `identity_verified`, `attempt_count`, `first_seen`, `last_seen`,
`review_status`, `review_note`, `reviewed_by`, `reviewed_at`.

## Verification

My Subjects also shows total units and weekly contact hours for the selected
term. The calculation and the Dean's six-or-more-subject workload review are
documented in [Student units and workload review](student-workload.md).

`php tests/student-portal.php` exercises guarded HTTP fixtures against Apache's
PHP runtime and rolls back all database writes. It covers Student identity,
subject ownership, term selection, failed-email records, OAuth state isolation,
Administrator authorization, CSRF, and review behavior.

`node tests/student-portal-browser.cjs` checks the real rendered login,
dashboard, search, term modal and Administrator review layouts on desktop and
phone widths. It intercepts the browser's Google-start submission and does not
sign into Google. Completing a live Google sign-in must be checked with a real
SKSU account using the existing configured OAuth client.
