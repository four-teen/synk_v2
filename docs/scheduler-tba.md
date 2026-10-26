# Bulk TBA schedules

On **Class Scheduling**, select a program, academic year, and semester:

- **Generate TBA** creates the required lecture/laboratory blocks for offerings
  that have no schedule blocks. Existing blocks, including partial schedules,
  are skipped. Lecture/laboratory meetings receive the same grouping used by
  the class editor.
- **Set All to TBA** converts existing meetings in the selected program and
  term. It clears days, start/end time, and room. Meeting IDs, groups, faculty
  assignments, faculty-need assignments, and enrollment links are preserved.
  Offerings with no blocks are skipped; use Generate TBA for those offerings.

Both actions preview all sections in the selected program and term. The subject
search box does not limit a bulk action. Locked offerings and both sides of a
merged schedule are skipped and listed with reasons. Already-TBA meetings do not
receive duplicate changes or history entries. A class containing a mixture of
scheduled and TBA meetings has only its remaining scheduled meetings converted.

Apply validates the college, program, term, scheduler role, and CSRF token again.
If the offering scope, meeting values, or merge links changed after preview,
Apply rejects the preview and asks the scheduler to refresh. Changes are saved
in one transaction under the existing schedule write lock. A history failure
rolls back the entire action. Each created or converted meeting receives a TBA
history event, and affected offerings become pending.

These actions affect the live workspace. Saved schedule sets retain their saved
values. Existing per-meeting editing and Room TBA remain available for arranging
the classes later.

The installed TBA schema is required; this workflow runs no schema migrations.

Validation (run sequentially):

```powershell
php tests/scheduler-tba-bulk.php
php tests/scheduler-tba.php
php tests/scheduler-assigned-schedule.php
node tests/scheduler-tba-browser.cjs
```

HTTP fixtures roll back temporary records. Browser checks intercept save requests
and cover preview/cancel/apply, stale previews, error recovery, escaped subject
text, and desktop/mobile layouts.
