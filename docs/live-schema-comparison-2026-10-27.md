# Live database schema comparison

Compared the schema in `synk_db (10.27.26).sql` with the configured local Synk
database on 2026-10-27. The supplied dump identifies MariaDB 10.4.27 and contains
71 base tables. The connected local database reports MySQL 5.7.25 and contains 77
base tables. The dump SHA-256 is
`9aeb12e39115c9622f13796ded5d9c3cd934f35b155f030063e21be2685ec179`.

The comparison parsed table definitions and ALTER statements only. It did not
execute the dump or use its INSERT statements. No live-database changes were made.

The local schema has six tables missing from the dump:

| Table | Purpose |
| --- | --- |
| `tbl_management_period_settings` | Management Midterm/Final selection by organization and semester |
| `tbl_management_attendance_events` | Per-event attendance state and roster-period snapshot |
| `tbl_management_attendance_roster` | Frozen student roster for each event |
| `tbl_management_attendance_logs` | QR attendance scans |
| `tbl_management_student_qr_tokens` | Student QR tokens |
| `tbl_management_attendance_audit` | Attendance open, close, and reopen audit |

Three existing Management catalog tables need schema updates:

| Table | Local change |
| --- | --- |
| `tbl_management_contributions` | Add nullable `exam_term ENUM('MIDTERM','FINAL')`; add index `(college_id, organization_id, ay_id, semester, exam_term, contribution_id)`; extend `status` to accept `ACTIVE` and `INACTIVE` |
| `tbl_management_events` | Add nullable `exam_term ENUM('MIDTERM','FINAL')`; add index `(college_id, organization_id, ay_id, semester, exam_term, event_id)`; extend `status` to accept `ACTIVE` and `INACTIVE` |
| `tbl_management_event_sessions` | Extend `status` to accept `ACTIVE` and `INACTIVE` |

All other table definitions match after normalizing MySQL/MariaDB display
differences such as implicit text collations, numeric default formatting, and
redundant parentheses in generated-column expressions. There are no dump-only
tables and no other missing local tables.

The SQL patch keeps the existing `DRAFT`, `OPEN`, `CLOSED`, and `ARCHIVED` enum
values, then adds `ACTIVE` and `INACTIVE`. This preserves any existing status
values without reading or converting records. As a result, the three live status
enums will be wider than their local counterparts, which contain only
`ACTIVE`/`INACTIVE`. Narrowing them to match exactly requires a separate data
conversion decision.

The new `exam_term` fields are nullable and remain `NULL` for existing rows. No
historical term is inferred. The patch contains no record inserts, updates,
deletes, or drops, and creates no sample values or account assignments.

## Manual SQL

1. After any failed attempt, rerun the read-only preflight against the intended
   live database. Confirm the selected database is `synk_db` and the server is
   MariaDB 10.4. Review the current columns, indexes, and tables for partial work.
2. Run the complete upgrade file in that same database. Its column/index additions
   and table creation are conditional, so they can resume after partial execution.
3. Rerun the preflight to verify the resulting schema.

- [Preflight SQL](../backend/sql/live_schema_preflight_2026_10_27.sql)
- [Schema upgrade SQL](../backend/sql/live_schema_upgrade_2026_10_27.sql)

The upgrade file uses direct `ALTER TABLE` statements and contains no `PREPARE`
or `information_schema` references. Its `ADD COLUMN IF NOT EXISTS` and `ADD INDEX
IF NOT EXISTS` clauses target MariaDB 10.4, the version identified by the supplied
dump. They let the upgrade continue if an addition already succeeded before an
interrupted attempt. Confirm the live server version in the preflight before
running the patch.
