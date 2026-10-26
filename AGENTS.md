# Management terms

Midterm and Final are used only by the Management module. Keep their settings,
storage, validation, and UI inside Management-specific files and tables. Never add
these terms to shared Academic Settings, academic helpers, workloads, scheduling
views, or academic reports. Management may read the existing shared academic year
and semester without changing their data or behavior. Preserve this boundary when
building legacy history migration, payments, attendance, and clearance features.

The Management financial calendar is August-October = 1st Semester/Midterm,
November-December = 1st Semester/Final, January-March = 2nd Semester/Midterm,
and April-June = 2nd Semester/Final. The academic year begins in August: August
2026 through June 2027 belongs to 2026-2027. July and ambiguous dates require
review. Apply corrections only to Management history; preserve original source
values. Do not overwrite shared student profiles.

The user cancelled legacy migration and requested a fresh Management start at
2026-2027 / 1st Semester / Final. All imported financial history, legacy email
links/accounts, import audit tables, and imported contribution/event catalog
rows were removed. Keep Management-only period settings and existing organization
and account assignments. Do not reimport the old SQL dump, restore legacy links,
or recreate the removed financial import schema. Build the new recording process
from fresh entries only. The explicit current Final setting overrides calendar
inference; never switch it back to Midterm automatically from today's date.
