# Student financial overview

Open **Financial overview** from the Management Students list. The wider modal is
a financial account view scoped to the assigned organization and the header's
selected academic year, semester, and Management Midterm/Final term.

The left sidebar contains an initials avatar placeholder, student name and ID,
course, combined class section/year level, email, and selected period.
Course and year information comes from the student's class memberships in the
selected college and semester, without changing the shared student profile.

The main area contains:

1. Selected period, an **Unpaid** or **No charges** status, and a **Pay** button.
2. Four summary cards: automatic contribution total, event count with absence
   status, amount paid, and total to pay.
3. Contributions: title, event date, configured amount, payment status, and total.
4. Events & attendance: event date, configured absence fine, attendance status,
   total events, total absences, and absence amount to pay.
5. Student violations and payment history panels.
6. An account total showing automatic contribution amounts, event charges,
   violation charges, payments received, and total to pay.

The profile stays in position while financial details scroll independently. The
sidebar scrolls only when its content exceeds the available height. On smaller
screens, a height-limited profile sits above the independently scrolling financial
sections. Long tables scroll within their own panels. Close remains accessible.

## Automatic totals and attendance

All active contributions for the organization, academic year, semester, and
Management term apply automatically to each student in that college/semester.
Amounts use integer centavos for exact arithmetic. No date-based term inference
or shared profile updates occur.

Total to pay is contributions plus finalized event absence fines. Amount paid,
deductions, and assigned violations are currently zero: payment collection,
waivers/deductions, and student violation assignment remain separate future work.
The violation catalog is never treated as a student's committed violations. Pay
remains disabled until payment collection is implemented. No legacy financial
history is used.

Attendance uses the event's **Take attendance** action. It is available only for
active events in the current Management period, on or after the event date.
Opening attendance freezes the eligible college/semester roster and absence fine.
An open or unopened event shows **Attendance pending** and adds no absence fine.
Students with a valid scan show **Present**. Closing attendance marks roster
students without a scan **Absent**, adding the frozen fine to their overview.
Students added after the roster snapshot are not retroactively fined.

Repeated scans are idempotent. Closing and scanning lock the same event, so a scan
cannot slip through after closing. Date, fine, active status, and deletion are
locked once attendance begins. Attendance can be reopened in the current period
for corrections: existing scans/roster remain, and absence fines pause until
attendance is closed again. Every open, close, and reopen is audited.

## Student QR

Students can also open **My Finances** from their portal (**Finances** on phones).
The read-only page uses the same automatic totals and finalized attendance rules
as Management. It includes all active organizations in the student's current
college/semester directory memberships, each using its own saved Management term.
The Student academic viewing preference does not change these financial periods.
Present, absent, pending, and not-in-roster events are listed with their payable
amounts. The authenticated session determines the student; URL IDs and period
parameters cannot expose another account. Financial term resolution and display
remain in Management-specific helper/view files. Payments and student violation
assignment remain deferred, with corresponding empty recorded-data sections.

Real student accounts have **My QR** in the desktop sidebar and **QR** in the mobile
menu. **Save QR to phone** downloads a PNG containing the student's full name,
student ID, and a large QR with a four-module white border. The saved code uses the
same stable identity as the live code and can be shown for future attendance.
The on-screen QR scales to the phone width while retaining its clear border.
The opaque code
is generated only for the authenticated student; submitting another student ID
cannot obtain that student's code. Staff preview cannot issue attendance QR codes.
The QR grants no account access. Management must verify the displayed student name
after scanning; possession of an image is not independent proof of identity.

The reader supports the camera, a QR image, and a connected hardware scanner.
After a successful scan it shows the student's uploaded photo (or placeholder),
name, student ID, and the original recorded time. A repeated scan shows **Already
recorded** and preserves that time. Camera selection appears when multiple cameras
are available. On phones, saved student details appear above the camera so the
operator can verify the student's identity immediately. Opening the connected-scanner section focuses its input; scanners
that send Enter submit the code. The camera keeps scanning for the next student.
Failed save requests can be retried without creating duplicate attendance; the
reader does not show a previous student's success while a new save is pending or
has failed. A request timeout reports that attendance needs confirmation by rescanning.
QR encoding/decoding is local, using pinned vendored libraries. Camera permission
requires HTTPS or localhost. Camera tracks stop when attendance closes or the
modal is dismissed, including when an outstanding permission request finishes late.
Pressing Stop also cancels a pending camera request; a delayed permission response
cannot restart it. A QR held continuously in view is submitted once until it moves
out of view or the camera restarts. Switching cameras, hiding the browser tab, or
leaving the page also stops the previous camera. The video shows the full frame.
Saved QR images up to 10 MB are decoded separately from the live camera canvas.

## Installation and verification

Run `php backend/tools/prepare_management_attendance.php`, or import
`backend/sql/management_attendance.sql`. The complete Management installer includes
the same five native tables. No shared tables are altered and no business rows are
created by installation.

Relevant checks: `php tests/management-attendance.php`,
`php tests/management-crud.php`, `php tests/student-portal.php`,
`node tests/management-qr-roundtrip.cjs`, and
`node tests/management-portal-browser.cjs`. The student QR screen and PNG download
are checked with `node tests/student-qr-browser.cjs`, including decoding at
320/360/390px widths and full-name wrapping.
