# Faculty Personal Data Sheet, Revised 2026

The professor's Personal Data Sheet follows the attached CS Form 212 Revised 2026 workbook. All eleven guided sections support independent partial saves: About you, Contact & IDs, Family, Education, Eligibility, Work history, Learning & development, Voluntary work, Other details & references, Declarations, and Photo & final review. The existing Profile form continues to save independently, and its progress indicator remains in the portal sidebar.

The Profile and PDS titles share aligned rows above the tab navigation. Both visible and inactive title text reserve the same space so wrapped headings and the Draft badge cannot move the tabs. Tab changes update the accessible heading, selected tab and panel together, including keyboard and hash navigation. Text, date, number, file and searchable inputs fill their available field width; editing fields use a single column throughout both tabs.

Removing a PDS record, an additional Profile degree, a credential selection or a saved attachment opens a styled Proceed/Cancel confirmation with Cancel focused. Cancelling preserves values and saved-file links. Proceed applies the pending edit; it reaches storage only after the corresponding Save action. A local accessible dialog remains available if SweetAlert cannot load. Save-success messages wait until an open removal dialog has closed.

## Database preparation

Run `php backend/tools/prepare_hr_faculty_pds.php` against the configured local database. The additive, repeatable SQL sources are `backend/sql/hr_faculty_pds.sql` and `backend/sql/hr_faculty_pds_remaining.sql`. All new tables use the HR prefix:

- `tbl_hr_faculty_profiles`: internal ownership link to a faculty record, or an account until a faculty link exists.
- `tbl_hr_faculty_pds_personal`: structured names, birth and personal information, citizenship, and measurements.
- `tbl_hr_faculty_pds_contact`: structured residential/permanent addresses, contact details, and government/agency identifiers stored as text.
- `tbl_hr_faculty_pds_family`: parents' names, spouse/children applicability, and the Family revision.
- `tbl_hr_faculty_pds_spouses` and `tbl_hr_faculty_pds_children`: ordered family records belonging to that Family section.
- `tbl_hr_faculty_pds_education`: the Education revision, including a deliberately empty saved list.
- `tbl_hr_faculty_pds_education_records`: ordered schooling, degrees, attendance years, units, graduation, honors, status, address and specialization.
- `tbl_hr_faculty_pds_eligibility`, `tbl_hr_faculty_pds_work`, `tbl_hr_faculty_pds_learning`, `tbl_hr_faculty_pds_voluntary`, and `tbl_hr_faculty_pds_other`: independent section revisions and scalar applicability settings.
- `tbl_hr_faculty_pds_eligibilities_records`, `tbl_hr_faculty_pds_work_records`, `tbl_hr_faculty_pds_trainings_records`, and `tbl_hr_faculty_pds_voluntary_records`: ordered eligibility, employment, training and voluntary records.
- `tbl_hr_faculty_pds_skills_records`, `tbl_hr_faculty_pds_recognition_records`, `tbl_hr_faculty_pds_memberships_records`, and `tbl_hr_faculty_pds_references_records`: the four Other details lists.
- `tbl_hr_faculty_pds_declarations`: all twelve explicit Yes/No/unanswered answers, their follow-ups, case details, country, indigenous group, disability ID and solo-parent ID.
- `tbl_hr_faculty_pds_certification`: certification ID and issuance, one date for all PDS pages, and the faculty's declaration acknowledgment. The date accomplished is authoritative and is written to all four stored page-date columns in the same save. The earlier photo-date and oath administration columns remain stored but are no longer faculty inputs.
- `tbl_hr_faculty_pds_attachments`: retained historical file storage. Photo, signature and thumbmark uploads are retired; no stored files are reused by the active PDS.

Preparation creates storage only. It does not copy records, import legacy data, or update shared student, faculty, academic, Management, or LMS records. No PDS draft is created until the faculty member saves a section.

## Prefilling and ownership

Names initially use existing structured faculty names when they match the saved profile name. Otherwise the saved full name is split into editable suggestions, including common suffixes and compound surnames. Faculty should review ambiguous name splits. Persisted PDS values, including deliberately empty fields, always take priority over future suggestions. The name suggestion does not rewrite the shared faculty directory or account.

Education initially offers existing profile degrees as editable suggestions. Once Education is saved, only its HR records are loaded, even if the saved list is empty. Adding, editing or removing PDS education never changes existing profile degrees. Family allows partial parent names, multiple child records, and spouse details. Explicitly selecting Not applicable for spouse or None for children removes those conditional records when Family is saved.

Eligibility and training similarly use existing records only as starting suggestions until their HR section is first saved. HR edits do not rewrite the existing eligibility catalog or training records. Work history includes optional office/unit, supervisor, duties and accomplishments for Work Experience Sheet data. These are employment-history inputs and have no connection to scheduling or workload logic.

Institutional Google email remains the sign-in and integration lookup. PDS contact email is a separate field and cannot change Google sign-in, `google_sub`, or account links. The internal HR record key is not an employee number or a user-facing login identifier.

Linked PDS records belong to the faculty identity. An account-only draft is connected to the faculty identity on its next save after linking. A stale page cannot save after its faculty link changes. If both identities already have separate HR records, the operation requires administrator review rather than automatically merging them.

## Saving

`professor/pds-save.php` accepts authenticated professor POST requests with JSON (up to 2 MiB). Older multipart requests can be parsed, but all uploaded files and attachment removal requests are rejected. Each save includes the form CSRF token, resolved owner, section revision, and full supported field set. Unknown or omitted fields are rejected at both section and record level. Blank fields are allowed for drafts. Server validation covers options, dates, lengths, measurements, contact email, attendance order, ongoing study, nonnegative salary/hours, and multiline duties/declaration details. License validity may be in the future; historical events and certification dates may not. Conditional values are cleared when inapplicable. The server copies the residential address when the same-address option is selected.

Repeatable records use ordered arrays, with up to 20 spouse records and 100 child or education records. No record IDs or owner IDs are accepted inside rows. The server locks the owner and checks the section revision before replacing that section's rows inside the same transaction. Failed validation or a stale revision leaves all previously saved rows intact. Nested field errors identify the row and open its record card.

Other lists also support up to 100 records each. Current employment clears its end date. Explicit None clears voluntary records. Declaration answers default to unanswered; saving never infers No. Explicit No clears only that question's follow-ups. A blank answer retains any entered follow-up draft.

Sections have separate revisions. Concurrent saves, changed ownership, expired sessions, validation failures, and network failures preserve displayed edits and show a clear failure message. A successful response cannot replace edits made while its request was in flight. Nothing is kept in browser local/session storage. The review shows current saved details together with unsaved edits; saving a section does not submit or digitally sign the PDS.

## Printed PDS and shared page date

The PDS photo area is reserved for a separately attached printed photograph. The page shows the professor's existing profile photo as an on-screen reference, with no PDS photo upload or photo-date input. The reference image is excluded from browser printing, and the 3.5 cm × 4.5 cm photo frame stays blank. The review follows the same rule. Previously stored PDS photos are retained in the database without being displayed, reused or accepted as new uploads.

Signatures, the right thumbmark and the oath officer's signature are added to the printed PDS. There are no digital signature, certificate or thumbmark inputs. Certification presents one Date for all PDS pages, backed by `date_accomplished`. Every successful save sets `signature_date_1` through `signature_date_4` to the same value; clearing it clears all five stored date values together. Individual page dates cannot be posted or edited independently. ID issuance remains a separate historical event. Oath administration is completed by the attorney's office, so its date and officer fields are absent from the faculty form and rejected in faculty save payloads. Existing oath values are preserved in storage.

The active attachment whitelist is empty. Previously stored files remain in the HR database without being displayed, downloaded by the PDS endpoint, overwritten or deleted by a date save. No database columns, tables or saved files are removed by this change.

Declaration acknowledgment remains an explicit, editable faculty checkbox and is never preselected. PDS export and an HR submission/approval workflow are separate from completing and saving these inputs.

Confirmed section saves and the existing Profile save show a SweetAlert2 success toast matching the assessment workspace: top right, checkmark, close button, and a 3.5-second progress bar that pauses on hover or focus. Profile-specific feedback files keep the HR UI independent of LMS behavior. Failed saves never show success feedback, and a local toast remains available if the alert library cannot load.

## Verification

- `php tests/hr-faculty-pds.php`: name suggestions and section validation.
- `php tests/hr-faculty-pds.php --database`: rollback-only persistence, ownership/link changes, conflicts, and shared-data isolation checks.
- `php tests/hr-faculty-pds-remaining.php --database`: remaining scalar/record fields, conditional normalization, shared page dates, rejected independent date overrides, retired uploads, historical-file preservation and atomic rollback.
- `php tests/hr-faculty-pds-http.php`: local HTTP section saves, rejection of photo/signature/thumbmark uploads and historical-file downloads, sample accounts and rollback-only writes.
- `php tests/faculty-pds-preview.php --hr --saved`: rendered form/schema parity for all eleven sections, saved-list precedence, radio restoration, file links, escaping, and form boundaries.
- `node tests/hr-faculty-pds-dom.cjs`: offline DOM checks for every section, nested records, declaration radio serialization, the single PDS date, absent file inputs, review, errors, concurrency and preserving edits. Its testing dependency is documented at the top of the test.

The migration and database/HTTP checks require access to the configured local MySQL server. The live browser should also be checked when computer-use access is available.
