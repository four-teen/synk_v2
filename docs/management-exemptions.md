# Management exemptions

Management → Exemptions maintains reusable student lists and the saved exemptions
for the selected organization and Management period. Events and Contributions
provide **Exempt students** and **View exemptions** actions. Students provides
**Exempt from events / contributions** with that student already selected.

Guidance appears in compact Notes. List cards keep the title, smaller italic
lowercase description, and student count together. Saved exemptions separate the
student identity, event or contribution, reason, status, and source list. Lowercase
description styling affects display only; stored reasons retain their original text.

## Apply and preserve membership

Create a list with a name, reason, and selected students. Grant exemptions by
selecting that list or selecting individual students, then choosing events and/or
contributions. Event exemptions waive absence fines and the event's currently
active linked contributions. Standalone contributions can be selected separately.
Violations are unaffected.

Each grant saves a student and charge independently of the list. Applying a list
of ten to Event 1, removing one student from the list, and applying it to Event 2
leaves ten exemptions on Event 1 and nine on Event 2. Reapplying the smaller list
to Event 1 does not remove anyone. Stale list revisions are rejected.

A contribution newly linked to an event inherits that event's saved active
students, including students since removed from the reusable list. Catalog edits
do not reactivate explicitly revoked contribution exemptions. Contributions with
exemption history retain their event link; records with exemption history cannot
be deleted.

Revoking an event exemption also revokes its inherited contribution exemptions.
An explicitly selected independent contribution exemption is preserved. Revoking
requires a reason and the current saved revision. Previous grants remain in the
audit trail. No attendance scans or receipts are deleted.

## Attendance, payments, and access

Students stay on the attendance roster. Without a scan, exempted students are
reported as **Exempted**, separately from absent students, with no absence fine.
An exempted student who scans is still **Present**, with the exemption reason
retained in financial views and attendance reports. Event counts exclude unscanned
exempted students from Remaining and finalized absences.

Student finances, staff financial overviews, payment allocation and absence-fine
reports all use the saved grants. Exempted contributions show their original rate,
an Exempted status, and zero payable balance. No exemption is recorded as a payment
or deducted twice. An exemption invalidates an old payment preview. If a student
has paid any part of a selected charge, the entire exemption application is
rejected for separate payment correction; existing receipts are preserved.

Faculty managers have exemption access. Student officers require the separate
**Manage exemptions** permission, defaulting to off on both fresh installation and
upgrade. Officers cannot grant or revoke their own exemptions. View records users
can read the exemption page. Writes are limited to the current Management period,
with organization, student membership, CSRF and revision checks and transactional
auditing. List updates do not change shared student profiles or academic settings.
The saved 2026-2027 / 1st Semester / Final setting is preserved.

## Installation and validation

Run `php backend/tools/prepare_management_exemptions.php` on an existing local
installation, or import `backend/sql/management_officers.sql` followed by
`backend/sql/management_exemptions.sql`. Both are repeatable and additive. The
complete `backend/sql/management_live_install.sql` also includes these schemas.
No legacy import schema, accounts or financial history is restored.

Run `php tests/management-exemptions.php` for the ten-to-nine snapshot scenario,
linked and standalone contributions, new contribution inheritance, revocations,
payment protections, organization/period boundaries and officer authorization.
Fixtures run in transactions and are rolled back.

On small screens, More opens a full-width multirow grid containing every permitted
Management page. Navigation icons use vivid orange (#ff6a00), scoped to the sidebar,
footer, expanded menu, and account menu. Status and other content icons retain
their existing colors.
