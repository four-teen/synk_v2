# Management Financial: fresh start

The user cancelled the old-system migration. The Financial menu now opens an empty
workspace for a new recording process. Do not restore old student email links,
legacy Financial tables, or the old import utilities.

The starting Management period is **2026-2027 / 1st Semester / Final** for CSSO
and PSITS. This is the explicit operational setting even though the calendar date
falls in October. Keep `tbl_management_period_settings` and its Management-only
controls; do not change shared Academic Settings, student profiles, workloads,
scheduling views, or academic reports.

The cleanup removed:

- `tbl_management_financial_records`: 48,519 imported history rows.
- `tbl_management_legacy_student_links`: 4,058 old student identity links.
- `tbl_management_financial_accounts`: 1,271 imported email-linked accounts.
- `tbl_management_financial_imports`: one import audit row.
- 29 imported contributions, 11 imported events, and four imported event sessions
  from the existing catalog tables. Those tables remain empty for new entries.

Organizations, Management account assignments, shared student identities and
memberships, academic settings, schedules, and workload data were preserved.
Checksums verified 68 protected tables unchanged during the cleanup. The original
SQL dump in Downloads was not modified or deleted.

The Financial page retains its fresh-start workspace. New payment collection is
available from Students → Financial overview → Pay. New receipts and allocations
use only fresh native recording tables, without legacy IDs, imported balances,
or old-system receipts.

The Students page now has a **Financial overview** account view. It uses
the selected student's profile and the organization's active period catalogs.
Contributions total automatically; absence fines apply only after attendance is
closed. QR scans and closure history use fresh Management-only attendance tables.
Payments use Distribute or Selected and generate receipts visible to Management
and the student. Deductions and student violation assignments remain future work;
no legacy import schema is restored. See
[management-student-financial-overview.md](management-student-financial-overview.md).
