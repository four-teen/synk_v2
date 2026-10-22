# Student login grid and idle sessions

The College Dean dashboard has a full-width Student Login Activity card above
Program Offerings Overview. Each student in the assigned college's directory for
the selected academic year and semester has one square, including manual/retained
records and students with no email or successful sign-ins. Multiple subjects or
sections do not duplicate a student. Squares follow surname, first name, middle name, suffix and student ID
ascending. The grid includes all students, without a 1,000-row or pagination limit;
the card grows to fit every row on desktop and mobile, with no internal scrollbar.
Search filters the displayed squares.
Refreshes preserve existing square elements, grid height and open hover details
when the roster/order is unchanged. Unchanged summary figures are not repeatedly
announced to assistive technology.

Grey means zero successful logins. Light green means 1–4; green means 5–9;
dark green means 10 or more. Counts include only successful sign-ins explicitly
tagged with the selected academic year and semester. New authentication events
capture the system's current academic term, regardless of a staff/student viewing
preference. Phase33 recovers older events recorded since the last academic-settings
save: that save establishes the current term for the following period. The settings
boundary is converted from database-local time to the UTC used by login events.
Events before that boundary remain unclassified and visible in All login history,
but do not affect per-term colors or counts. Already classified events are preserved.
No semester dates are guessed from timestamps, academic-year labels or enrollments.
Failed attempts, page visits, heartbeats
and staff previews never add to those counts. Hover, keyboard focus or tap opens
a magnified square with the student's name, number, count, connection status and
last-seen/sign-out time in Philippine Time.

Each request carries the card's academic year and semester so two open tabs keep
independent viewing terms. Both fields are validated on the server. Changing the
navigation term reloads the corresponding student roster and login counts. A term
badge identifies the selected period on the card.

Orange is available only for the current academic term. It overrides the login-count shade when at least one Student Portal session
has connected within 90 seconds and has not exceeded 15 minutes without activity.
The card refreshes every 30 seconds; cached orange indicators expire locally when
their server-provided remaining time runs out. Closing a browser or losing its
connection therefore removes orange within about 90 seconds. A recent session connection
does not prove that a person is looking at the screen.
Historical terms display recorded activity without online indicators, live-session
timestamps or a claim that those students are online or logged out now.

Each actual sign-in creates a separate random presence key, not a PHP session ID
or OAuth token. Explicit logout ends that session. Another active device can keep
the student's square orange. Switching to a staff account ends the old student
presence; a stale Student tab cannot sign the staff account out. Existing student
sessions acquire tracking on their next guarded request, using their original
login time, without recording another successful login.

All four protected Student Portal pages enforce a 900-second idle limit before
accepting new activity or saving changes. Mouse/pointer movement, scrolling,
touch and keyboard interaction in a visible Student tab report activity, at most
once per 15 seconds. Background status requests only update connection presence;
they never reset inactivity. Server-side expiry is checked before every heartbeat
or protected page request, so an old tab cannot revive an expired session.
The browser redirects to sign-in after server-confirmed expiry with a clear
15-minute inactivity message. A tab with an outdated local timer first checks
the shared session, so activity in another tab can keep it signed in.
If a connection failure prevents that check, it leaves the protected screen at
the last confirmed deadline. Activity reports older than 30 seconds are discarded
after connection failures; they cannot extend a session minutes later.

Even if the browser sleeps or never sends another request, the dashboard derives
Logged out from the server activity deadline, and the next protected request is
rejected. No cron job or access to PHP session files is required. Presence reads
never modify student profiles, course lists or sign-in history. Staff account
previews do not create or refresh student presence. The Dean API rechecks active
role and college/campus scope and validates CSRF on every request; it returns no
presence keys or authentication metadata.

Apply `backend/sql/phase31_student_portal_sessions.sql` after phase27 login activity,
or run `php backend/tools/prepare_student_portal_sessions.php`. The additive migration
creates `tbl_student_portal_sessions` and leaves existing data unchanged. It was
applied to this local installation. New hosts must prepare it before deploying
the session guards.

For term-specific counts, also apply
`backend/sql/phase32_student_login_activity_terms.sql` followed by
`backend/sql/phase33_student_login_activity_term_recovery.sql`, or run
`php backend/tools/prepare_student_login_activity_terms.php`. This repeatable,
additive migration adds nullable `ay_id` and `semester` columns and an index to
sign-in history. Phase33 fills missing term fields only within the known current-term
period. It preserves timestamps, outcomes and previously assigned terms, can be
repeated, and leaves older or partially classified events unchanged.
Both were applied locally. Existing history/report views retain their all-time/date
range behavior; the dashboard grid uses the selected academic term.

Validation: `php tests/student-login-term-recovery.php`,
`php tests/student-live-activity.php`, `php tests/student-portal.php`,
`php tests/student-login-activity.php`, and
`php tests/college-dean-dashboard.php --http`, plus
`node tests/student-presence-ui.cjs` and
`node tests/student-live-activity-browser.cjs`. All database fixtures roll back;
browser API responses are intercepted.
