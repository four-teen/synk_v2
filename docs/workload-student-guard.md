# Protecting workload removal and finding student lists needing review

Scheduler workload removal now checks active student memberships before deleting
an assignment. The same guard covers the Faculty Workload page, the faculty
workload modal in Class Schedule, paired lecture/laboratory removal, and
**Remove All College Workload**. If any selected class has active students, the
whole request is blocked and no selected assignments are removed.

The warning uses the actual distinct student count, for example:

> This class has 39 students. Clear its student list in the Dean module before removing the workload.

The message also identifies the subject and section. Multiple blocked classes
are returned in the response. CSV imports and manual additions both count.
Inactive memberships do not block removal. The check uses the class offering
and selected college/campus/academic year/semester. Legacy offering ID zero
uses program, section and subject to find its class. Saved faculty ID is not a
filter, so an outdated student faculty link cannot bypass the protection.
Linked lecture/laboratory rows count the class once. Other colleges and terms
are unaffected. Past-term active rosters remain protected; the Dean clearing
workflow continues to allow changes only in the configured current term.

Removal requires Scheduler access, the session college and a valid CSRF token.
All requested rows must still belong to that college. The server holds the
schedule-write and roster-import locks, rechecks/locks assignments and matching
memberships, and deletes inside a transaction. Concurrent class-list saves
cannot slip between the check and removal. An audit-write failure rolls removal
back. Existing audit storage is reused; schema DDL is skipped when it already
exists and is never run inside this removal transaction.

## Find classes already affected

Open **College Dean → Faculty Workload → Student Lists Needing Review** and use
**Check Student Lists**. The check also runs when the page opens, before a
faculty is selected. It examines all active class lists in the Dean's own
college and selected academic term, independently of the faculty filter.

Rows show the subject, program/section, exact class offering ID, total/affected
student counts, faculty saved in student records, current faculty assignments,
and the next step. It identifies:

- Student lists with no faculty assignment.
- Student memberships whose faculty differs from the current assignment.
- Unavailable or ambiguous class offerings that need verification.

These are review flags, not proof that deletion caused the problem. The check
is read only and never changes memberships, profiles, evaluations, assignments,
or counts. Inactive membership history is excluded. A unique legacy class can
be resolved for display; ambiguous legacy records require verification. Rows
with an invalid class link never open another class's student list.

For a class with no faculty assignment, ask Scheduler to assign the **same class
offering** to the intended faculty. Run the check again. A reassigned class
with outdated student faculty links offers **Review Student List**, which opens
the exact class under its current faculty. Clear that list and import the
corrected CSV. If the original removal was accidental and the same offering is
restored to its original faculty, the links may already agree; verify the list
before making further changes. Do not create a different offering as a shortcut.

After a successful clear or manual roster change, the review refreshes. It can
also be refreshed through the workload Refresh button. A failed check hides
prior results rather than presenting stale information as a current clean scan.
The timestamp is displayed in Philippine Time.

## Database and deployment

This guard/review feature adds **no tables or columns** and requires **no new
SQL migration**. It reads existing class memberships, offerings and assignments
and uses the existing Scheduler audit table. The previously implemented clearing
workflow still requires `phase29_student_class_clear_history.sql`, and manual
class additions still require phase28.

Deploy the changed PHP helpers/endpoints and Scheduler/Dean pages together with
`college-dean/roster-review.js`, the updated workload controller and stylesheet.
Reload open Scheduler tabs so removal requests carry the CSRF token. Existing
student/profile/history records are not repaired automatically.

## Verification

Run database suites sequentially; all fixtures roll back. Coverage includes
39-student counting, CSV/manual/legacy memberships, paired parts, mixed/bulk
requests, college/term isolation, empty/cleared classes, access/CSRF checks,
failed audit rollback, a real held import lock, review classifications and the
assign-same-offering → clear → CSV reimport recovery flow.

```text
php tests/workload-student-guard.php
php tests/clear-class-roster.php
php tests/manual-class-roster.php
php -d extension=gd tests/college-dean-workload.php --http
php tests/program-chair-module.php
node tests/workload-removal-ui.cjs
node tests/college-dean-workload-ui.js
node tests/manual-class-roster-browser.cjs
```

The Scheduler UI test executes the actual removal handlers with a server
refusal, checking that the count/guidance is displayed as text, the correct
IDs/term/token are sent, and blocked requests never refresh as successful
removals. The browser suite uses the rendered Dean page and real controllers
with isolated responses to check review states, exact-class navigation,
escaping, retry behavior and desktop/mobile layout.
