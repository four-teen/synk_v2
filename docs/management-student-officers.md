# Student officers under faculty supervision

Open **Management → Student Officers** (on phones, **More → Student Officers**).
This page is available to an active Management account linked to an active
faculty record. The account keeps its Administrator-assigned organization.

1. Search by student name, ID or school email. Results come from the assigned
   college's current academic-year/semester directory membership.
2. Select the student and enable one or more operations:
   - **View records:** read student financial overviews, receipts and catalogs.
   - **Scan attendance:** scan QR codes for attendance opened by faculty or an
     authorized event manager.
   - **Collect payments:** collect for other students and generate receipts.
   - **Manage contributions:** add, edit, and delete contributions, amounts and
     event links in the assigned organization and current Management period.
   - **Manage events:** add, edit, and delete events, sessions and fines; open,
     close, and reopen attendance. QR scanning needs **Scan attendance** separately.
   - **Manage violations:** add, edit, and delete the organization's violation
     types and standard amounts using the existing semester-based listings.
3. Set an expiry date and save. The date is inclusive in Philippine time. Access
   also stops when the academic semester changes; the faculty manager must renew.
4. The student signs in with their school Google account at
   `/management/login.php`. Their ordinary `/student/login.php` remains separate.

The page lists the faculty manager's own student officers. **Edit permissions /
Renew** changes permissions or extends access. **Revoke access** stops Management
access on the next request, including an already signed-in session.

Each management permission works independently, including without **View records**.
Students receive controls only for the catalogs their supervisor enables.
Officers cannot grant access, change Management periods, or record payments
against their own account. Page controls and server-side mutations enforce these
limits on every request. Paid charges and events with attendance retain their
existing edit/delete protections. Catalog changes, attendance lifecycle changes,
scans, and payments are attributed to the actual officer in the faculty manager's
recent activity. Payments retain their cashier identity on receipts.

An officer has one supervising faculty manager and one organization. Accounts or
officers already assigned elsewhere are never taken over by another manager.
Existing shared accounts with the same email require Administrator review.
Faculty reassignment, deactivation, loss of the Management role or faculty link,
student email changes, and loss of current membership suspend delegated access.
The student directory/profile and shared academic settings are never modified.

## Installation

Run `backend/sql/management_officers.sql` once in the live database after pulling
the code, or use `php backend/tools/prepare_management_officers.php` from a server
terminal. The combined `management_live_install.sql` includes the same additive
tables. No existing users are automatically granted officer permissions.
The setup is safe to rerun: it adds missing operation columns to existing officer
storage with every new permission disabled, retaining existing assignments and
permissions. Enable the new operations explicitly on each student's assignment.

Delegation creates a dedicated Google-only shared account and a retained
Management permission record; it does not give the student a Professor role.
Revocation retains the record and audit trail, so it cannot turn an officer into
an unrestricted Management account by removing its permissions row.

Validation: `php tests/management-officers.php`, `php tests/management-crud.php`,
`php tests/management-portal.php`, and `node tests/management-officers-browser.cjs`.
All fixtures are rolled back. Run database fixture suites sequentially.
