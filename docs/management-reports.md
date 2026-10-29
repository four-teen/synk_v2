# Management reports

Open **Management > Reports**, or **More > Reports** on a phone. All report types
use one page with an academic year, semester, Management term, and report-type
selector. These are viewing filters, not changes to current Management settings.
Changing the type or period refreshes event choices. **Generate report** creates a
complete preview; **Print / Save as PDF** uses an A4 portrait print layout.

Available reports:

- **Collection Summary:** actual receipts and allocations, separated into
  Contributions, Event absence fines, and Violation fines. Overall receipts and
  payers are unique; category counts can overlap when a receipt is split.
- **Detailed Collections:** saved receipt allocations grouped by category, with
  receipt number/time, student identity, charge title, cashier, and amount.
  Numbering continues across payment categories. One receipt can have several
  allocation rows; its header amount is never repeated as category revenue.
- **Event Absence Fines:** finalized absences with positive assessed fines,
  collections against those fines, and outstanding balances, grouped by event.
  Select one event or all events in the period. Assessed fines come from the
  attendance snapshot, not a subsequently edited catalog rate. Saved event
  exemptions are excluded from assessed fines.
- **Event Attendance:** one event's saved roster, absent, present, then exempted,
  with one continuous number sequence. Open attendance is explicitly provisional:
  the first category is **Not yet recorded**, not finalized absences. Unstarted
  attendance does not fabricate a roster or fine assessments.
  Exempted students who actually scan remain Present, with their exemption reason.

**Event absence fine** is the report label for money assessed due to absence from
an event. It is distinct from an event-linked contribution. Contributions remain
Contributions even when linked to an event.

The native ledger accepts contribution, event-fine, and applied student-violation
payment items. Violation fines count only receipt allocations to individual
student assessments; unapplied catalog rates are never counted as collections.
If the student-violation recording upgrade is not installed, this category remains
**Unavailable**. With the upgrade installed and no violation payments, it correctly
shows zero. Report generation itself makes no schema or data changes.

The institution name is temporarily **INSTITUTION NAME**. Expand **Print header
and signatories** to set it and the Prepared by / Checked by / Approved by names
for this report. The actual generating account and Philippine timestamp remain
on the preview. These header values are not stored in the database. Changed
options require regeneration before the Print button can be used.

Access follows existing permissions: financial reports require View records or
Collect payments. Attendance reports require View records, Scan attendance, or
Manage events. The Reports menu exposes only the report types the account may
read; expired/revoked officer access is rechecked on each request. Every query
is restricted to the assigned organization/college and all three period fields.

Suggested next additions, once the corresponding data is recorded:

1. An audited void/reversal workflow for correcting mistakenly applied incidents
   while preserving paid charges and receipts.
2. Cashier and collection-date filters for daily collection handovers, while
   retaining the academic-year/semester/term boundary.
3. A frozen report reference and approval/sign-off workflow for official filing;
   current reports are read-only snapshots of the ledger when generated.

Validation: `php tests/management-reports.php` checks amount reconciliation,
period/organization isolation, attendance ordering, fine balances, provisional
states and officer access. `node tests/management-reports-browser.cjs` checks the
single-page controls, responsiveness, safe header text, continuous numbering and
print output. Native fixtures roll back; print QA files go to `tmp/pdfs` and PNG
previews to `.tmp`. Run database fixture suites sequentially.
