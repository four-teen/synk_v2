# Kawani

Open `/synk/hris/` for Kawani. Sign-in uses Synk's existing Google OAuth client, institutional domain and callback. Kawani approves official addresses and stores its Google bindings and roles in `tbl_hr_employee_accounts`. An existing Synk identity is matched by its official email and checked for active status and a matching Google subject. An employee approved by HR can also sign in with their official Google account before they have a Synk account. Kawani sessions and sign-out are scoped to this module.

Run the installer from the Synk root with the approved administrator's registered Google email:

```powershell
php hris/grant-admin.php <registered-university-email>
```

The installer provisions Kawani’s database tables and grants access to the supplied active Synk account. For an existing installation, run `employee-accounts.sql` after `schema.sql` and `faculty-status.sql`. It carries forward existing Kawani grants and adds support for attributing HR status reviews to Kawani-only administrators. Repeating the migration preserves later approvals and removals.

In **Management → Account**, select an employee and their official email. Check **Administrator**, **User**, or both under **Select account to use**. Administrator grants every Kawani tool. Checking User reveals the permissions for that role. Dual-role accounts start in Administrator and can switch roles from the avatar menu using the same Google sign-in.

Administrators use **Edit account** to manage roles and permissions, including their own. Keep at least one verified Administrator. Users with Account Management can manage eligible User accounts within their own permissions and reduce the permissions on their own User role. Administrators handle grants for Account Management and changes to Administrator roles. Another administrator handles changes to your own official email or account removal.

On an existing installation, run `account-permissions.sql` after `employee-accounts.sql` in the main `synk_db` database to install the User permissions and optional second role. Existing Users receive checkmarks for the tools they already had. Repeating the migration preserves saved selections, including an empty selection.

The dashboard, Employee Profiles, and PDS Tracker read the existing Professor Profile and HR PDS tables. Kawani groups records by the Synk faculty link and displays the PDS agency employee number, with the Professor Profile ID as a fallback. Saved sections reflect PDS draft progress. HR staff manage Work history in Kawani. Faculty view those entries and edit their other PDS sections in the Professor Portal.

Existing installations can apply `faculty-status.sql` to enable the **HR status** control in Employee Profiles. Records begin at **Not yet reviewed**. HR staff can save **Active**, **Enactive**, or **Retired**. Active requires the reviewer to confirm that they verified the employee’s identity and current employment against official HR records. Kawani stores the status, reviewer, and date with the directory record. An unlinked record that later acquires a different directory identity goes through review under the new identity.

**New Employee** creates a shared Synk faculty identity and saves its initial details in the existing faculty-owned profile storage. When an official employee ID is unavailable, the form generates a temporary ID from the first, middle, and last initials and eight zeros. An optional official SKSU Google email can be recorded for later approval in **Management → Account**. New employees begin at **Not yet reviewed** in Kawani. The shared faculty identity starts inactive for roster eligibility. Roster activation, teaching assignments, and Professor Portal account linking use their existing Synk workflows.

In Employee Profiles, **Delete** opens a confirmation dialog. **Proceed to delete** lists the employee's profile, personnel details, HR status and reviews, PDS entries, and Kawani records by table with their row counts. **Delete employee** rechecks the preview and removes the listed records in one transaction. An administrator handles deletion when the employee has current or prior Kawani access. The review identifies any links that block deletion, including Synk sign-in links, academic records, references from other employees' records, and a faculty profile photo saved outside the database.

Apply `personnel.sql` to add Kawani’s non-teaching position catalog and personnel details tables. **New Employee** requires a personnel category. Teaching staff use Synk’s academic rank list. For non-teaching staff, select an approved title from **Position Catalog** when the classification is Permanent or Temporary. Other classifications can leave the position blank. Record the plantilla item number when applicable. HR can confirm or change a category from Employee Profiles. A recognized rank suggests Teaching until HR saves the category. Personnel details and HR status are saved independently of roster eligibility.

Open **Management → Position Catalog** to add, edit, or delete non-teaching titles. The catalog stays open after saving and keeps the entered values. If a position is assigned, update the employee record before deleting the position. Catalog management uses the tables already installed by `personnel.sql`.

The dashboard rank and employment classification charts use the production ApexCharts 3.54.1 browser distribution and stylesheet from the `apexcharts` npm package. The bundled MIT license is in `assets/vendor/apexcharts/LICENSE`.

Manage opens the Personal Data Sheet at **About you** in the faculty preview. The preview presents Professor Profile and Personal Data Sheet details by section.

Run `php hris/tests/smoke.php` with local Apache and MySQL running to check sign-in routes, isolated OAuth state and role enforcement. The script rolls back the test account and role assignment.
