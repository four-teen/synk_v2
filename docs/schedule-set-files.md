# College schedule and workload file sets

The Class Scheduling page's **Schedule Sets** panel exports named CSV files and
imports them back into the live college workspace. A file contains all existing
lecture/laboratory meetings for every scheduled program in the selected college,
academic year and semester, together with faculty and faculty-need assignments.
The Program filter and subject search do not limit a file set.

## Workflow

1. Choose **Export Set CSV**. A naming dialog opens with the selected year and
   semester, such as `2026-2027 - First Semester`. Edit or append to that name
   (for example, ` - Set A`), then choose **Download CSV**. Check that the file is
   saved on your computer. Cancel sends no export request. Export leaves live
   data unchanged and creates no database snapshot.
2. Use the existing **Set All to TBA** workflow for each program to prepare another
   arrangement while retaining assignments. Locked and merged offerings retain
   the existing TBA restrictions. Existing scheduling/workload editors are unchanged.
3. Export another version as `Set B`.
4. Choose **Import Set CSV**, select an original export, and review its college,
   term, program, offering, meeting and workload counts. Confirm replacement only
   after exporting the current version if you want to retain it.

The browser requests a download; it cannot confirm that the user retained the
file. File sets live on the user's computer. Excel can open the UTF-8 CSV for
reference. Restore from the original unedited file, not an Excel-resaved copy.

## Scope and compatibility

- A file restores only to its original college and academic term in the same
  database. Master records, student lists, appointments, designations, academic
  settings, and shared faculty/room identities are not exported or changed.
- Meeting IDs, types, groups, offering/section/curriculum references and merge
  relationships must still match. TBA preserves those identities. Deleting or
  regenerating meetings or changing merge topology requires a fresh export.
- Schedule placement and workload are restored together, in place. Unchanged
  assignment IDs remain intact. Both actual faculty and faculty-need assignments
  are included. The file does not create faculty or faculty-need master records.
- CSV files are the only schedule-set workflow. The database-set interface,
  automatic requests and status tracking have been removed. Stale requests to
  the retired database save/load/rename/delete/list actions return HTTP 410.
  Existing archived snapshot records are left intact; no tables or records are
  deleted as part of this interface change.

## Validation and persistence

The versioned CSV has a metadata row, one row per physical meeting (including TBA
and room-TBA), and merge-reference rows. Pipe-delimited IDs preserve assignment
associations without duplicating meetings. Counts and a SHA-256 content checksum
detect truncation and accidental edits. The checksum is an integrity check, not
authentication; every identifier and proposed change is validated server-side.
Formula-like display labels are escaped for spreadsheet safety. Files are limited
to 8 MB and 20,000 records.

Import uses the existing Scheduler authentication, assigned-college access, term
guard, CSRF protection, schedule write lock, roster-import lock, room access/type
checks, policy helpers, merge resolution, workload membership rules and student
class checks. It validates all proposed room, section and assignee conflicts,
including inherited sections and workloads outside the selected college.

Locked classes cannot change, including through inherited meetings. Changing
workload for a class with active students is rejected using the existing student
class lookup; placement-only restoration retains student links. Current faculty
and faculty needs must remain active in the same college term.

Preview performs no writes. Confirm uploads the same file again and revalidates
under the locks. A fingerprint of the file and current college workspace rejects
a stale preview. Schedule placement, assignment differences, offering status and
audit history are written in one transaction. Any validation or persistence
failure leaves the live workspace unchanged. No file-set tables, imported rows,
or temporary saved-set records are created.

## Verification

```powershell
php tests/schedule-set-files.php
php tests/scheduler-assigned-schedule.php
php tests/scheduler-tba-bulk.php
node tests/schedule-set-files-browser.cjs --screenshot
```

Run database integration suites sequentially. File-set tests exercise the actual
multipart HTTP handlers inside rolled-back fixtures, including multi-program
scope, round trips, student protection, invalid files, stale previews, conflicts,
merge references and injected history-write failure. The HTTP fixture verifies
compatibility with the installed PHP web runtime as well as the CLI runtime.
