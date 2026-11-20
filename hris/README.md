# Kawani

Open `/synk/hris/` for Kawani. Its sign-in uses Synk's existing Google OAuth client, institutional domain and callback. The `hris_admin` and `hris_user` roles live in `tbl_hr_user_roles` and refer to an existing `tbl_useraccount.user_id`. The account management screen assigns a role only to an active employee whose faculty record is already linked to that Synk account in `tbl_useraccount_faculty_links`. Email is displayed as the linked login account; the HRIS role belongs to the account ID and employee link. The Kawani session and sign-out are scoped to this module.

Run the installer from the Synk root with the approved administrator's registered Google email:

```powershell
php hris/grant-admin.php <registered-university-email>
```

The installer creates the HRIS role and faculty review tables in the configured main database and grants access to the supplied active account. Installations that manage database schema separately can apply `schema.sql` and `faculty-review.sql`. Existing Kawani installations only need the new `faculty-review.sql` for review storage. Repeating the installer for the same account keeps one role assignment.

The Dashboard, Faculty Profiles, and PDS Tracker are administrator-only. Account Management lets administrators assign or change an employee's Administrator/User role and remove Kawani access from the selected faculty member. Removing access deactivates only the Kawani role; it does not change the Synk account or faculty record. It protects an administrator from changing their own role and prevents removing or demoting the final active administrator. User accounts can sign in to their separate HR Workspace; administrator pages remain restricted. The existing `tbl_hr_user_roles` table is sufficient; no additional account table is required.

The dashboard, Faculty Profiles, and PDS Tracker read the existing Professor Profile and HR PDS tables. Kawani groups records by the Synk faculty link and displays the PDS agency employee number, with the Professor Profile ID as a fallback. Saved sections reflect faculty draft progress. Profile and PDS edits still happen in the Professor Portal.

The dashboard rank and employment classification charts use the production ApexCharts 3.54.1 browser distribution and stylesheet from the `apexcharts` npm package. The bundled MIT license is in `assets/vendor/apexcharts/LICENSE`.

The Faculty Profiles directory links to separate Manage and Preview pages. Manage saves an internal status and note in `tbl_hr_faculty_reviews`. Preview reads saved Professor Profile and PDS entries. If the source records change after an HR review, Manage prompts the administrator to review the latest entries.

Run `php hris/tests/smoke.php` with local Apache and MySQL running to check sign-in routes, isolated OAuth state and role enforcement. The script rolls back the test account and role assignment.
