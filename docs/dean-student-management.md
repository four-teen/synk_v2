# Dean Student Management

Open College Dean → Students. The directory starts with the configured current academic year and semester. **Reset Filters** returns to that semester. You can choose another semester, a whole academic year with **All semesters**, or **All academic terms**.

The compact **Student Name** column shows the student ID first, the full name on a single second line, and the email in lowercase italic text below it. **Class Sections** shows every class group in which the student has imported subjects for the selected term. It can legitimately contain several sections. The verified home section is omitted from this display.

## Add and edit students

Choose **Add Student**, enter the exact student ID, full first name, last name, optional middle name/suffix, and verified school email. Choose a program in your college, year level, academic year, and semester. Leading zeros in student IDs are preserved. Duplicate student IDs are rejected.

New profiles appear in the directory even before their first class list is imported. They have no invented class membership or verified home section. Import their actual subjects later through Faculty Workload.

Use a row's **Edit** button, **Edit Student** in Student Details, or **Edit Student** in the email preview to correct the profile. Enter all given names in **Complete first name**. **Suggest Email** proposes the existing first-name/last-name formula while checking for duplicate proposals. You can instead enter the actual institutional address when the formula differs. Saved addresses must end in `@sksu.edu.ph` and must not already belong to another student. Blank email is allowed while awaiting verification.

Name and email changes apply to the same shared student profile across terms. The student ID remains fixed, and editing does not move the student's subjects or change scheduling. A changed email is also carried to the student's existing portal profile, when that profile has an unambiguous matching identity. Conflicting login profiles require administrator verification.

Student login uses the existing school Google sign-in. These actions manage the directory identity and email; they do not create a mailbox or password. A verified directory email is required for student sign-in.

## Delete selected academic-term records

1. Choose a specific semester or **academic year – All semesters**. Deletion is disabled for **All academic terms**.
2. Set program, class section, and search filters as needed.
3. Tick individual students or the page checkbox. Selections carry across pages. **Select All Matching Students** selects every matching result, up to 5,000 students. Changing filters clears selection.
4. Choose **Delete Selected Term Records**. Review the term, student IDs, number of class-list records, and any manual directory entries.
5. Tick the review acknowledgement and choose **Delete Term Records**.

Only matching active class-list memberships are marked inactive. A manual directory entry created for that term can also be removed from the active directory. Shared student profiles, their login emails, other colleges, other semesters, and registrar enrollment records are preserved. Students with other active matching class lists can still appear in the directory.

Student counts derived from CSV rosters are refreshed after removal. Manually entered offering counts and scheduling calculations/assignments remain unchanged. Reimporting a correct CSV can restore a removed membership through the existing import workflow.

Delete previews expire after 15 minutes and are tied to the signed-in Dean and assigned college. Saving checks the preview again; changed records or access require another preview. Failed updates and deletions roll back the entire action.

## Deployment and verification

No new or altered tables are required. This uses the existing student directory, enrolled-subject links, portal profile, and approved enrollment tables. No real students are added, edited, or removed merely by deploying the code.

Run database tests sequentially:

```powershell
php tests/student-management.php
node tests/student-management-browser.cjs
php tests/college-dean-students.php
node tests/college-dean-students-ui.js
php tests/student-emails.php
node tests/student-emails-browser.cjs
php -d extension=gd tests/college-dean-workload.php --http
```

Database fixtures roll back, with before/after checks for real data and scheduling records. Browser tests use the actual page and controllers with isolated API responses and require Chrome or Edge.
