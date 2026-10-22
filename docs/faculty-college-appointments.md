# College leadership in faculty profiles

The Dean and Program Chair directories place faculty under their appointment in
the college and selected academic term. A generic designation such as `DEAN` or
`PROGRAM CHAIRMAN` does not identify a college and does not grant leadership.

Administrators maintain appointments under **Faculty Masterlist > Leadership
appointments** (`administrator/faculty-appointments.php`). Select the college,
academic year and semester, then appoint a Dean or a Program Chair for a program
in that college. Faculty may come from any home college and need no login account.
Each post has one holder per term. Use Replace to change the holder and End for
this term to withdraw an appointment. Restore reactivates an ended post.
Existing records use revisions to reject stale updates from another tab.

An appointment supplies directory membership even without a teaching roster.
Home-college faculty and active teaching-roster members also appear. Shared
teachers appear under Faculty members outside their leadership college. A faculty
member with several appointments has one card, under Dean, then Program Chair,
then Faculty in that order. All programs chaired in the college appear on the
same card. One shared profile/photo remains attached to the faculty record.

Teaching in another college makes the card visible for reference. It does not
grant that college permission to edit a profile belonging to another home
college. Existing home-college/fallback-roster editing rules remain in effect;
an explicit leadership appointment also permits management in its college.
Directory placement never changes login roles, account scope, signatories,
workload designation names, or designation units. Manage portal access in User
Accounts and workload signatures through the existing signatory controls.

Run `backend/sql/phase30_faculty_college_appointments.sql` after the existing
faculty, multi-role account, and program-chair signatory schema. The directory
and administrator page can also initialize missing appointment storage outside
transactions. The additive migration imports only unambiguous active Dean
account assignments linked to faculty and existing active program-chair
signatories, for the **current term only**. It never infers appointments from
generic titles, writes historical appointments, or overwrites existing posts.
The imports are initial baselines; subsequent appointments are maintained on the
Leadership appointments screen independently of portal access. Populate each
new term explicitly; assignments are not carried forward automatically.

Unverified leadership titles appear as Faculty member until an administrator
records the college/program and term. This preserves the original designation
for workload reports while avoiding the appearance of another college's Dean
or Program Chair as local leadership.

Validation: `php tests/faculty-college-appointments.php` and
`php -d extension=gd tests/college-dean-profile-http.php`. Fixture changes are
rolled back; profile/photo upload checks remove their temporary files.
