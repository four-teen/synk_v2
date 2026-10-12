# Student email automation

In College Dean → Students, choose **Fill Missing Emails**. The preview includes students matching the current search, program, section, and term across every directory result page. Reset filters to include all imported students in the assigned college.

Review the proposed addresses, select eligible rows individually or with **Select all eligible emails**, then choose **Save Selected**. Existing emails are preserved. The directory refreshes after saving. A shared student receives one email on their existing unique profile, so their subject memberships stay attached to the same student ID.

Addresses use the complete stored first name followed by the last name, lowercase, with spaces and punctuation removed. Accents are normalized in email addresses; student names retain their spelling. Separate middle names and suffixes are excluded.

| Stored first name | Last name | Proposed email |
| --- | --- | --- |
| Elbren | Antonio | elbrenantonio@sksu.edu.ph |
| Carol Jan | Ambrocio | caroljanambrocio@sksu.edu.ph |
| José | Peña | josepena@sksu.edu.ph |

The spelling is taken from the profile: “Jan” remains “jan”. Confirm that the proposals match actual school accounts before selecting them. Generating an address does not provision an institutional mailbox.

Duplicate addresses are checked against existing emails and proposed addresses for other student IDs throughout the directory, including other colleges. Initials, damaged letters, unsupported spellings, and invalid email lengths are held for review. Ask an administrator to verify the profile names or save confirmed school addresses for these records. Automatic generation does not invent a numeric suffix. Use **Show only emails needing review** to locate these cases.

Future Dean CSV imports display proposed emails during **Preview Import** and fill eligible blank emails when confirming new class memberships. They preserve existing email values and leave conflicting emails blank while allowing the class import to proceed. CSV name parsing and student ID matching retain their existing behavior. Students already in the selected class are skipped; use **Fill Missing Emails** to update their missing addresses.

Previews expire after 30 minutes and belong to the signed-in Dean's assigned college. Saving rechecks membership, exact names, student numbers, existing emails, and collisions. Changed records require another preview. A failed save rolls back the whole selected batch.

No schema migration is required. This uses the existing `tbl_student_management.email_address` field. Deploy the code, reload Student Management, and run the preview/save workflow on the live site to update its database. Local saves do not transfer student data through Git. Student identity, class memberships, and scheduling calculations are unchanged by the batch action.

Verification:

```powershell
php tests/student-emails.php
node tests/student-emails-browser.cjs
php tests/college-dean-students.php
node tests/college-dean-students-ui.js
php -d extension=gd tests/college-dean-workload.php --http
node tests/college-dean-workload-ui.js
php tests/student-identity.php
```

Run database tests sequentially. Their fixtures use rolled-back transactions and temporary local-only routes. The browser test requires Chrome or Edge and uses isolated API responses.
