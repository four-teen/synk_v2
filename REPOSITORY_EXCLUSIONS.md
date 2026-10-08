SYNK files excluded from Git

These rules protect workstation credentials and application data while retaining the source files needed to run the application.

| Category | `.gitignore` rules | Reason |
| --- | --- | --- |
| Local credentials | `/backend/db_config.local.php`, `/backend/auth_config.local.php` | Database connection and Google OAuth settings |
| Environment files | `.env`, `.env.*`, with `!.env.example` | Keep real environment values private |
| Private keys and credentials | `*.pem`, `*.key`, `*.p12`, `*.pfx`, `/.aws/`, `/.ssh/` | Prevent credential/key uploads |
| Database dumps | `*.sql`, with exceptions for `/backend/sql/*.sql` and the two reviewed root schema scripts | Exclude full backups containing real records |
| Uploaded files | `/uploads/**`, with exceptions for directories, `.htaccess`, and `.gitkeep` | Exclude personal uploads while retaining access controls |
| Runtime and local backups | `/tmp`, `/temp`, `/.tmp/`, `/.codex_tmp_*` | Exclude generated reports, previews, verification files, and local snapshots |
| Saved sessions | `sess_*` | Exclude login/session data |
| Logs and debug output | `logs`, `*.log`, `*_debug.txt`, `/backend/debug*.txt`, `/backend/log*.txt` | Exclude operational/debug data |
| Generated/dependency files | `/dist`, `/build`, `node_modules`, `.npm`, `.eslintcache`, `.yarn-integrity`, `/.sass-cache`, `gulpfile.tmp.*` | Exclude generated output and development caches |
| Existing starter/demo directories | `/html-starter`, `/html-demo`, `/html-starter-demo` | Preserve the existing exclusions |
| System files | Existing OS thumbnail, desktop, shortcut, and stackdump rules | Exclude workstation artifacts |

The current database dump `sksu_balancing_db (12.9.25).sql` is excluded. The supplied `synk_db_100726.sql` is outside the repository directory; if copied into the project, the SQL dump rule excludes it too. Store future backups outside `backend/sql/`, which is reserved for reviewed, shareable migrations.

The following obsolete PHP pages/endpoints have explicit exclusions:

- `scheduler/manage-workload_bkup.php`
- `scheduler/manage-workload_bkup2.php`
- `scheduler/manage-workload_v1.php`
- `scheduler/manage-workload_v2.php`
- `scheduler/manage-room-utilization_copy2_work perfectly.php`
- `scheduler/manage-room-utilization_copy3_work perfectly.php`
- `backend/query_class_schedule_bk1.php`
- `backend/query_workload_bkup.php`
- `backend/query_workload_bkup2.php`
- `backend/query_workload_bkup3.php`

PHP preview, fixture, and backup pages inside ignored runtime directories are also excluded by their directory rules. Both actual local configuration files are excluded even though they use the `.php` extension.

Active application pages remain included, including `scheduler/manage-workload.php`, administrator/dean pages, `administrator/database-backup.php`, and the sanitized `backend/db.php` and `backend/auth_config.php` loaders. Active endpoints such as `backend/query_room_utilization_v2.php` and `backend/query_prospectus_copy.php` are retained; version numbers and the word "copy" alone are not exclusion criteria.

The following root schema scripts remain included:

- `sql_create_tbl_faculty_workload_simulation.sql`
- `sql_create_tbl_offering_enrollee_counts.sql`

Ignored files remain on the workstation; these rules do not delete them. Transfer the local configuration files, a fresh database backup, and uploads privately when recreating this installation. See `REPOSITORY_SETUP.md` for the required locations and setup steps.

For this workstation, a complete local file inventory is saved at `.tmp/github-preparation/excluded-files.txt`. That inventory is ignored by Git and blocked from direct web requests because it includes runtime file names.
