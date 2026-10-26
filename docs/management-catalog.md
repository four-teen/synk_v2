# Management contribution and event catalog

The catalog is now empty for a fresh start. The user cancelled legacy migration:
29 imported contributions, 11 events, and four event sessions were removed. The
old catalog import scripts and import-specific tests were removed. Do not restore
old definitions or Financial account links from the supplied SQL dump.

The existing organization setup and catalog schema remain in place:

| Table | Purpose |
| --- | --- |
| `tbl_management_organizations` | Organizations and their college ownership |
| `tbl_management_contributions` | New contribution definitions and amounts |
| `tbl_management_events` | New event definitions |
| `tbl_management_event_sessions` | Sessions belonging to new events |
| `tbl_management_violations` | New violation types and standard amounts |

Administrator manages organizations and Management account assignments. Native
contributions, events, and violations are managed from their Management pages.
New records use the assigned organization and existing current academic
year/semester. Edits preserve a record's original year/semester. Midterm/Final is
stored only in `tbl_management_period_settings`; the current starting Management
period is **2026-2027 / 1st Semester / Final**.

Lists provide organization scope, search, pagination, and catalog status filters.
Contribution forms manage title, description, date, amount, audience, status, and
an optional event in the same organization and academic semester. Events support
scheduled sessions, exact decimal fine rates, grace periods, reference amounts,
and attendance rules. The violation catalog stores types and standard amounts;
it does not assign charges to students.

Writes validate Google authentication, role, current organization assignment,
CSRF token, current Management period/revision, and record version. Source
provenance columns remain nullable for native records; they do not create any
legacy student/account linkage. No schema changes occur during web requests.

Install schema only with `backend/sql/management_live_install.sql`. It creates the
six Management tables and required Management account columns without importing
business rows. The cancelled Financial import tables are not included.

Relevant verification:

```powershell
php tests/management-live-sql.php
php tests/management-crud.php
php tests/management-portal.php
node tests/management-portal-browser.cjs
```

See [management-financial.md](management-financial.md) for the Financial reset and
[management-portal.md](management-portal.md) for account and period settings.
