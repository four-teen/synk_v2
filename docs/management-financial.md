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

Payment collection and the fresh recording workflow have not been implemented in
this reset. The retained Financial page intentionally shows an empty state. Build
new records directly in Synk after the new process is agreed, without depending
on legacy IDs, imported balances, or old-system receipts.
