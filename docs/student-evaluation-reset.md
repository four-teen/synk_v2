# Administrator student evaluation reset

Open Administrator → Access and Term Control → Student Evaluation Reset. Choose
a campus, its college, an academic year and semester, then Filter. The academic
term defaults to the shared current term without changing Academic Settings.
There is no all-colleges or all-terms deletion option.

Students who evaluated counts distinct students with submitted responses. Submitted
evaluations counts student/faculty responses, separately from saved drafts. All
questionnaire versions are included. Only explicit saved college ownership is
eligible; unassigned legacy records remain untouched and are reported separately.

Review deletion shows the exact college, campus, term and counts. Confirmation
requires the typed phrase and checkbox, CSRF, an active Administrator role and
a session-bound preview valid for 15 minutes. Current-term student collection must
be closed first using the Dean's existing controls. No access settings are changed
by reset. A changed response, answer, subject snapshot or collection setting
invalidates the review. Changed browser filters immediately hide the old review.

Deletion is limited to matching rows in `tbl_student_faculty_evaluations`, their
`tbl_student_faculty_evaluation_answers`, and evaluation-only
`tbl_student_faculty_evaluation_subject_scope` snapshots. Profiles, accounts,
enrollments, faculty, supervisors' evaluations, appointments, exclusions,
previous clearing history, other colleges/terms, LMS and Management are preserved.
The reset writes a metadata-only history row; no deleted answers or comments are
copied into that history. Existing historical clearing archives remain intact.

Prepare the new operation history once:

```text
php backend/tools/prepare_student_evaluation_reset.php
```

This additive preparation creates `tbl_student_evaluation_reset_history` and
deletes no evaluations. Web requests never create or alter tables. The reset
requires InnoDB for its participating tables. It locks the same current-term,
college and collection setting used by student writers, checks the complete
review fingerprint, and commits deletion and its operation log together.

Verification:

```text
php tests/student-evaluation-reset.php
php tests/student-evaluation-reset-http.php
node tests/student-evaluation-reset-browser.cjs
```

Tests use synthetic records inside an outer rollback transaction, including
deletion failure injection, stale previews, scope tampering, role revocation,
open collection, replay, multiple deletion batches, real student re-evaluation
after reset, and protected-table checksums. Browser confirmation posts are
intercepted; HTTP deletion fixtures roll back before their requests finish.
