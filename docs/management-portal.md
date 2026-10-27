# Management portal foundation

Management is an organization-scoped Synk role, administered through **Administrator →
Accounts**. The portal is reusable by any college and has no dependency on the
external CSSO/ISMRS application. The user cancelled legacy imports; new catalog and Financial records must be
created directly in Synk. The external application is never changed.

## Shared module presentation

Management uses the Dean/Chair Sneat layout: fixed card-menu sidebar, detached
account header with avatar and campus badge, viewing-term settings modal, shared
footer, core controls, and the same list typography and colors. Sidebar rules live
in `assets/css/college-portal-sidebar.css`, imported by the existing Dean theme;
the Dean and Chair continue to use the same rules. Management also loads
`college-dean/custom_css.css` and `college-dean/students.css` directly. Its local
`portal.css` contains layout extensions rather than a separate color/font theme.
Student identity cells follow the Dean's ID, name, and italic email format.

Management navigation groups pages by usage: **Overview** contains Dashboard;
**Daily Operations** contains Financial and Students; **Setup** contains
Contributions, Events, and Violations. Every page retains its existing route,
organization access checks, active-page indicator, and selected viewing period.

Below 1200px, Management replaces the sidebar with a fixed bottom navigation bar:
Home, Students, Events, Financial, and More. More opens a dismissible bottom sheet
for Contributions and Violations. All links retain the viewing period. The page
reserves space for the bar and the phone's safe area; dialogs hide the bar and
prevent it from receiving focus. Desktop navigation continues to use the sidebar.

Below 576px, event, contribution, and violation tables become labeled cards with
full-width attendance actions. Student financial tables also use labeled records
without sideways scrolling. Table roles and headers remain available to screen
readers. Controls have touch targets of at least 44px, inputs use 16px text to
avoid focus zoom, and financial details scroll as one panel on phones.

QR attendance fills the phone viewport, including short landscape screens. Its
Done button stays in the footer, the latest scanned student stays visible while
scrolling, and recent scans and attendance closure are separate
expandable sections. Managing attendance status opens the closure section;
Take attendance starts the camera. Rotation preserves the camera stream. Closing
the dialog or hiding the page stops the camera. Camera access still requires HTTPS
or localhost and the user's browser permission; reading a QR image and using a
connected scanner remain available.

## Account setup and sign-in

1. Create the organization in **Administrator → Institution Setup → Organizations**,
   selecting its campus and college. Every organization belongs to exactly one college.
2. In Accounts, add or edit the account and select **Management**.
3. Choose **Management Campus → College → Organization** and save.
   All three must be active. Assignment is independent of Scheduler, Chair, and Dean scopes.
   One account has one organization; an organization can have multiple accounts.
   Assignments persist when the academic term changes.
4. Open `/synk/management/login.php` and continue with the registered Google account.

There is no email or password field on this login. Access requires an existing
active account, an active Management role, and an active assigned organization/college/campus.
There is no self-registration or college selection during sign-in.

Management-only accounts may use personal `@gmail.com` or the configured SKSU
domain. Accounts with other staff roles retain the configured SKSU-domain rule.
The email must match the Administrator-created account. Google identity, verified
email, OAuth state, nonce, audience, issuer, expiration, and subject binding are
validated through Synk's existing server-side Google flow. The Management flow
has independent OAuth state and returns failures to its own login. Google state
from concurrently opened student/staff sign-ins is preserved.

The portal uses the existing Google client and registered callback URL,
`/backend/auth_google_callback.php`; it does not need another callback registration.
For personal Gmail sign-in, the existing Google OAuth application must permit
external accounts (and list permitted testers if it is in testing mode). This
cannot be changed from the PHP application.

The public login says **CCS Management. One Place.** by default, following the
student login design. To change that heading on another installation, set
`management_login_title` in ignored `backend/auth_config.local.php`. After login,
organization name, college, campus, and catalog data use the current assignment.
Every request reloads assignment and account/role status from the database.
Forged organization, college, or campus IDs cannot widen access. Account switching is
available for shared identities authenticated with Google. Legacy password
sessions cannot switch into Management. Sign-out returns to the Management login.

## Management-only Midterm and Final

**Midterm and Final belong only to Management.** Shared Academic Settings, shared
academic helpers, workloads, scheduling views, and academic reports retain their
original academic-year/semester behavior. Management reads the existing current
academic year and semester; it never writes those settings.

`tbl_management_period_settings` stores the current Management term separately for
each organization, academic year, and semester. Only `MIDTERM` and `FINAL` are
allowed. A new organization/semester defaults to Midterm without writing on page
loads. Prepare the Management-only table with
`php backend/tools/prepare_management_exam_terms.php`, or import
`backend/sql/create_management_period_settings.sql`. It is also included in the
complete Management installation SQL. No shared table is altered by these files.

In Management's header settings, choose the academic year, semester, and term to
view. The selected term persists through navigation, pagination, and search.
Use current period restores the organization's operational Management period.
Expand **Set current Management term** in that same dialog to save Midterm or
Final for the assigned organization in the current academic year/semester. Other
organizations keep their own setting. Changing the viewing selection does not
change the operational period.

Student enrollments and violation definitions cover the whole semester.
Contributions and events store their Management-only Midterm/Final term, use the current
period on creation, and are listed only for the selected year/semester/exam term.
Changing a period filters records without moving them. Catalog records outside the
current operational period are available for viewing only. Sessions inherit their
parent event's period, and contribution event links require the same full period.
Historical definitions
without a reliable term are not assigned one by inference. Future
Management payments, clearance, and attendance history must use Management's
period helper and storage. The shared academic helper must not gain an exam term.

Management writes validate the current year/semester, Management term, and settings
revision while holding the assignment and period locks. A changed period rejects
an outdated form with HTTP 409, including after switching Final back to Midterm.
All current-term saves require Management authentication, assigned organization,
and CSRF validation. `synk_management_assert_payment_context` rejects an explicitly
term-specific Management charge from a different term. Payment collection remains
future work. Legacy Financial history and account links have been removed; the
starting operational period is 2026-2027 / 1st Semester / Final. Do not switch the
Management term automatically from today's calendar date.

## Student directory and analytics

The **Financial** menu is an empty workspace for a fresh recording process. Legacy
history, email links, and Financial import tables were removed. See
[management-financial.md](management-financial.md) for the reset scope. Student
identities and directory memberships remain available in the existing directory.

Dashboard counts and program breakdowns use active imported subject memberships
in `tbl_student_management_enrolled_subjects` joined to Synk's shared
`tbl_student_management` identities. College/campus/term membership comes from
the imported class, not the shared identity's original home program. This handles
students imported in more than one college without granting access to another
college's classes. Students are counted once per selected term, including when
they have multiple imported subjects. The Students page starts with three summary cards and uses the header's selected
academic year/semester. The heading reads **DIRECTORY | Student listing** on one
line. Its listing form contains a wide name/student-number search that applies
after typing pauses, and a 25/50/100 students-per-page selector that applies
immediately. There is no Search button or repeated period/count heading. The
list shows Student Name (ID, name, email) and Actions. **Financial overview** opens
the wider financial account view with the selected student's profile sidebar,
contributions, events/attendance, violations, payment history, and account total.
Active contributions are automatically totaled. Closed event attendance adds
absence fines for roster students without a scan; open events remain pending.
Payment collection, deductions, and student violation assignment remain future work. See
[management-student-financial-overview.md](management-student-financial-overview.md).
The separate
directory term, program, class section, email status, sort, refresh, and reset
controls have been removed. Old bookmarked filter values do not override the
header's period or silently restrict the listing. The profile uses the selected
college's class context for course, year level, and section information.
The college name is displayed at the bottom of the Management sidebar.
Profile edits, account
changes, email generation, and roster deletion remain in the existing roles.

Contributions and Events provide organization-scoped lists, filters, pagination, and
add/edit/delete dialogs. Event forms show Event date, status, and Absence fine
together, with no attendance/location or session controls. Simplified saves retain
existing location and session records. The dashboard shows actual definition counts for the selected term.
The imported definitions and their relationships are described in
[management-catalog.md](management-catalog.md). Payment collection, student charge
assignments, and attendance capture are separate future workflows.

Violations provides an organization/term type-and-amount list at
`/synk/management/violations.php`. Types are uppercase and unique within an
organization, academic year, and semester. Amounts use `DECIMAL(12,2)` and permit
zero. This catalog does not assign violations or charges to students.

New contributions, events, and violations take organization ownership from the
account and the academic year/semester from Academic Settings. These fields are
read-only in the forms and validated on the server. The viewing-term selector
affects reporting; adding from a historical view still uses the current term.
Editing historical records preserves their original term. A term change while a
form is open requires a reload. Writes lock the current settings row until commit.

Payment collection has not been built. Its required integration contract is
`synk_management_lock_current_term` plus `synk_management_assert_payment_context`
in `backend/management_term_helper.php`, called within the payment transaction
after locking the assignment and charge. It rejects charges from another
organization or a noncurrent academic year/semester. This helper is tested; it
must be called by the future payment endpoint before any payment insert.

Administrator can rename organizations and change their status. Organizations
with accounts or catalog records cannot be deleted or moved to another college.
Inactive organizations lose Management access immediately. CSSO and PSITS retain
their organization IDs and account assignments. All legacy imported catalog and
Financial rows were removed for the fresh start.

## Database deployment

For manual live deployment, select the intended Synk database in phpMyAdmin and
import `backend/sql/management_live_install.sql` as one file. It combines the eleven
Management table definitions, account role/scope/Google-field preparation, and the
earlier Violations schema upgrade. Existing enum role values and their metadata
are preserved. The file checks for existing columns, keys, and constraints before
adding them and can be rerun. It contains no catalog data import, account mapping,
or academic-settings change. DDL commits implicitly; back up the live database first.
The disposable-database test is `php tests/management-live-sql.php`.

For a new installation, first install the reviewed catalog schema from
`backend/sql/create_management_catalog.sql`. Then run these repeatable
scripts against that server's configured database:

```powershell
php backend/tools/prepare_management_portal.php
php backend/tools/prepare_management_violations.php
php backend/tools/prepare_management_organizations.php
php backend/tools/prepare_management_exam_terms.php
php backend/tools/prepare_management_contribution_periods.php
php backend/tools/prepare_management_contribution_status.php
php backend/tools/prepare_management_event_periods.php
php backend/tools/prepare_management_attendance.php
```

The repeatable preparation script extends `tbl_useraccount.role` with `management`
and adds nullable `management_college_id`. It also installs missing Google identity
metadata from the existing phase 4 authentication contract: `auth_provider`,
`google_sub` (unique), `google_email_verified`, `last_login_at`, `last_google_name`.
It preserves existing passwords, account values, primary roles, college assignments,
and imported data. The local installation has been prepared. Requests do not run
this migration. Role memberships use existing `tbl_useraccount_roles` storage.
The additive violation migration creates `tbl_management_violations` with college
ownership, exact amounts, and timestamps. The organization migration adds nullable
`organization_id`, `ay_id`, and `semester`, foreign keys, and a unique type per
organization/term, replacing the previous college/type constraint. It also adds
`tbl_useraccount.management_organization_id` with a composite college/organization
foreign key. No new membership table or term renewal is needed. It is repeatable
and does not seed sample data. Its CRUD uses the Management CSRF/access checks,
assignment/row locks, record versions, and foreign-key deletion restrictions.

## Validation

Run database fixture suites sequentially:

```powershell
php tests/management-portal.php
php tests/management-crud.php
php tests/management-attendance.php
php tests/management-contribution-periods.php
php tests/management-event-periods.php
php tests/college-dean-accounts.php --http
php tests/account-switching.php --http
php tests/student-portal.php
php tests/professor-account-links-http.php
node tests/college-dean-account-form.js
node tests/management-portal-browser.cjs
node tests/management-qr-roundtrip.cjs
```

Fixtures roll back test accounts and student imports. Browser tests use an isolated
headless profile and token-guarded localhost fixture routes, intercept account
requests, and check login, analytics, student pagination, mobile navigation, account
role/campus/college/organization controls, Administrator organization CRUD, contribution/event forms, delete dialogs,
student financial totals, QR camera scanning, attendance closure, camera cleanup,
touch navigation, portrait/landscape scanning, and 320–1440px layouts. Browser mutations are intercepted; real
CRUD HTTP scenarios use a separate transaction per request and roll back fixtures.
The local existing CCS account was explicitly assigned to CSSO. Repeatable deployment
migrations do not assign arbitrary accounts; use Accounts to choose their organization.
Google token-signature verification
uses the shared authentication implementation; automated tests do not complete a
real Google consent flow with the user's credentials.
