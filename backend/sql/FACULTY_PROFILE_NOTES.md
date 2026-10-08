# Faculty profile storage

`professor/manage-profile.php` adds a single profile per authenticated professor
account using the fields in `FACULTY-PROFILE-NEW-TEMPLATE-.xlsx` (Sheet1,
A10:O11, with education detail labels in L13:L16). Sample faculty rows are not
imported into real accounts.

Login assigns `tbl_useraccount.user_id` to `$_SESSION['user_id']` in
`synk_complete_user_login()` in `backend/auth_useraccount.php`.
`tbl_faculty_profiles.user_id` is both the profile primary key and a foreign key
to that account. The page takes identity exclusively from the session; posted
or URL account/faculty IDs cannot select a different profile.

`tbl_useraccount_faculty_links` already maps account `user_id` to scheduling
`tbl_faculty.faculty_id`. These are different identifiers. The profile page reads
this link for the existing faculty name and ID; it does not create or modify
links, accounts, faculty master records, roles, or workload records.

The additive `phase17_faculty_profiles.sql` script creates the new table. The
profile page also initializes this table if missing, following the existing
portal's schema-helper pattern. For a deployment where the application database
user cannot create tables, run the SQL script once before opening the page.

Names, campus, college, and employment classification are initially prefilled
where available. Profile edits are independent of their original account and
faculty records. Age is derived from date of birth. Continuous length of service
is recalculated from the optional start date; manual credited service is retained
when no start date is set. Reporting term information comes from the existing
portal's current academic term. The workbook's `NO.` column is a report row
number, not a profile identifier.

Campus and college are dropdowns populated from active `tbl_campus` and
`tbl_college` records. College choices follow the selected campus; changing
campus clears a college that does not belong to it. Form validation and saving
both reject unknown or mismatched selections. Names remain in the existing
profile columns, with surrounding whitespace trimmed; no schema change is
needed. Existing unlisted names are shown for correction rather than silently
cleared, and both selections remain optional.

CSRF checks protect saves. A revision counter rejects concurrent edits from
another tab. Partial profiles are allowed; full name is required, and a study
status of Completed or Ongoing requires its degree/program.

The profile completion card shows a percentage, section counts, and links to
missing details. It updates while editing and is recalculated on every load;
no completion percentage is stored. Each applicable field has equal weight:
six personal details, rank/classification/service length, and study status plus
four details per education level. Selecting Not applicable excludes that level's
four detail fields. Age, salary grade, optional credentials/notes, and the
optional service start date are not counted separately. A start date derives
the service length; manual credited service also counts. Invalid counted fields
need correction. Completion is advisory and does not change partial saving.

Faculty rank is searchable with Select2. `phase18_faculty_ranks.sql` creates and
seeds `tbl_faculty_ranks` with overall levels 1–18 and their fixed salary grades
12–29. The profile page initializes this table when missing. Profile rank labels
remain in the existing `faculty_rank` column for compatibility; the server
validates them against the lookup table and derives `salary_grade` on every
save. Previously saved unlisted rank text is retained as an option until its
owner selects a replacement. Work status is removed from the form and excluded
from writes; its existing database column and saved values remain intact.

`phase19_faculty_eligibilities.sql` creates a reference catalog and the
`tbl_faculty_profile_eligibilities` junction table. Each selection is keyed by
the same login `user_id` and a catalog `eligibility_id`. The form uses Select2
multiple selection, grouped by issuer/category and searchable by names and
abbreviations. Profile data and selections save together in one transaction;
stale saves cannot overwrite selections. Existing eligibility text remains in
the original `eligibility` column and is displayed as optional other/previous
eligibility. No old text is deleted or guessed into catalog matches. Job Order
is available in the profile's employment classification without changing the
scheduling faculty master table.

The catalog is a broad set of common credentials, not an exhaustive registry
or credential verification service. Other credentials and specialties can be
entered in the optional text field. TESDA certificates are grouped separately
from PRC licenses and CSC eligibility. Superseded qualifications are labeled
when included to let holders record older credentials.

Catalog references (reviewed October 1, 2026):

- CSC: https://csc.gov.ph/special-eligibilities
- CSC examination eligibility: https://csc.gov.ph/csc-opens-applications-for-local-treasury-fire-officer-penology-officer-exams
- PRC professions: https://www.prc.gov.ph/professional-regulatory-boards
- PRC additional registration titles: https://www.prc.gov.ph/article/prc-cdo-board-certificate-release/1520
- TESDA qualifications: https://www.tesda.gov.ph/Download?SearchTitle=&Searchcat=Training+Regulations
- TESDA certificates: https://www.tesda.gov.ph/About/TESDA/25
- Supreme Court: https://sc.judiciary.gov.ph/new-lawyers-sign-roll-of-attorneys/
- CESB: https://www.cesboard.gov.ph/2018/index.php?title=Documents%2FEligibility+and+Rank+Appointment%2Ferad.php
- NAPOLCOM: https://www.napolcom.gov.ph/r1/index-services.php

Verification:

Education suggestions use `phase20_faculty_education_options.sql`. The shared
table stores degree/program, institution, institution address, and specialization
under separate option types. Each category is reused across all three education
levels. Existing profile entries seed an empty catalog on first use. Thereafter,
successful profile saves contribute new values in the same transaction as the
profile and credentials. No entries are published from validation failures or
stale revisions. Original profile records remain independent; removing an entry
from one profile does not delete a shared suggestion used by others.

Select2 supports selecting or typing new values. `professor/education-options.php`
is a read-only, authenticated JSON search with pagination (25 results per page).
Typed values are retained when leaving the field or saving; Escape cancels the
unselected search text. Without Select2, regular text inputs still accept values.
Case and repeated whitespace are normalized for duplicate matching, while the
first saved display spelling is retained. Blank/N/A/None placeholders stay in
the professor's profile but are not added to the suggestion catalog. Distinct
abbreviations are not guessed to be equivalent, and university addresses are
kept independent so multiple campuses can be recorded.

- `php tests/faculty-profile.php` checks validation and derived fields.
- `php tests/faculty-profile.php --database` also verifies persistence, account
  isolation, foreign keys, and concurrent saves. It creates the new table if
  necessary; all test data changes run in a transaction and are rolled back.
