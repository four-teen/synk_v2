# Faculty Personal Data Sheet, Revised 2026

The professor's Personal Data Sheet follows the attached CS Form 212 Revised 2026 workbook. All eleven guided sections support independent partial saves: About you, Contact & IDs, Family, Education, Eligibility, Work history, Learning & development, Voluntary work, Other details & references, Declarations, and Photo & final review. The existing Profile form continues to save independently, and its progress indicator remains in the portal sidebar.

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
- `tbl_hr_faculty_pds_certification`: photo date, certification ID and issuance, date accomplished, per-page signature dates, recorded oath details and the faculty's declaration acknowledgment.
- `tbl_hr_faculty_pds_attachments`: private file bytes, slot, original display name, content type, checksum, size and update metadata, owned by Certification.

Preparation creates storage only. It does not copy records, import legacy data, or update shared student, faculty, academic, Management, or LMS records. No PDS draft is created until the faculty member saves a section.

## Prefilling and ownership

Names initially use existing structured faculty names when they match the saved profile name. Otherwise the saved full name is split into editable suggestions, including common suffixes and compound surnames. Faculty should review ambiguous name splits. Persisted PDS values, including deliberately empty fields, always take priority over future suggestions. The name suggestion does not rewrite the shared faculty directory or account.

Education initially offers existing profile degrees as editable suggestions. Once Education is saved, only its HR records are loaded, even if the saved list is empty. Adding, editing or removing PDS education never changes existing profile degrees. Family allows partial parent names, multiple child records, and spouse details. Explicitly selecting Not applicable for spouse or None for children removes those conditional records when Family is saved.

Eligibility and training similarly use existing records only as starting suggestions until their HR section is first saved. HR edits do not rewrite the existing eligibility catalog or training records. Work history includes optional office/unit, supervisor, duties and accomplishments for Work Experience Sheet data. These are employment-history inputs and have no connection to scheduling or workload logic.

Institutional Google email remains the sign-in and integration lookup. PDS contact email is a separate field and cannot change Google sign-in, `google_sub`, or account links. The internal HR record key is not an employee number or a user-facing login identifier.

Linked PDS records belong to the faculty identity. An account-only draft is connected to the faculty identity on its next save after linking. A stale page cannot save after its faculty link changes. If both identities already have separate HR records, the operation requires administrator review rather than automatically merging them.

## Saving

`professor/pds-save.php` accepts authenticated professor POST requests with JSON (up to 2 MiB), or multipart requests with a JSON `payload` and named file slots. Each includes the form CSRF token, resolved owner, section revision, and full supported field set. Unknown or omitted fields are rejected at both section and record level. Blank fields are allowed for drafts. Server validation covers options, dates, lengths, measurements, contact email, attendance order, ongoing study, nonnegative salary/hours, and multiline duties/declaration details. License validity may be in the future; historical events and certification dates may not. Conditional values are cleared when inapplicable. The server copies the residential address when the same-address option is selected.

Repeatable records use ordered arrays, with up to 20 spouse records and 100 child or education records. No record IDs or owner IDs are accepted inside rows. The server locks the owner and checks the section revision before replacing that section's rows inside the same transaction. Failed validation or a stale revision leaves all previously saved rows intact. Nested field errors identify the row and open its record card.

Other lists also support up to 100 records each. Current employment clears its end date. Explicit None clears voluntary records. Declaration answers default to unanswered; saving never infers No. Explicit No clears only that question's follow-ups. A blank answer retains any entered follow-up draft.

Sections have separate revisions. Concurrent saves, changed ownership, expired sessions, validation failures, and network failures preserve displayed edits and show a clear failure message. A successful response cannot replace edits or file selections made while its request was in flight. Nothing is kept in browser local/session storage. The review shows current saved details together with unsaved edits; saving a section does not submit or digitally sign the PDS.

## Private attachments and certification

Certification supports a photo, page 1–4 signatures/certificate files, right thumbmark, and an existing oath signature document. Files are saved only with Save section. Choose an actual image for photo/thumbmark; signature slots accept images, PDF or P7S signed-data files. Images are decoded and re-encoded, with a maximum of 6,000 pixels per side and 16 megapixels. Each file is limited to 2 MiB and each save to 6 MiB of attachments. Large sets can be uploaded in multiple saves. PHP's server upload/post limits also apply.

Files live in the HR database rather than a publicly served upload directory. `professor/pds-attachment.php` resolves the authenticated, active professor's current HR owner and returns only that owner's named slot, as an attachment with no-store and nosniff headers. Responses contain metadata, never file bytes in the JSON payload. Files omitted from a save remain intact; deletion requires an explicit supported slot in `removed_attachments`. File changes and Certification's revision commit or roll back together. Unknown slots, stale revisions and foreign owners cannot change stored files.

Uploaded P7S files are checked for signed-data structure, not certificate trust or cryptographic validity. Recording oath details stores an existing event; the application does not administer an oath. Declaration acknowledgment is an explicit, editable faculty checkbox and is never preselected. PDS export, digital signature verification, and an HR submission/approval workflow are separate from completing and saving these inputs.

Confirmed section saves and the existing Profile save show a SweetAlert2 success toast matching the assessment workspace: top right, checkmark, close button, and a 3.5-second progress bar that pauses on hover or focus. Profile-specific feedback files keep the HR UI independent of LMS behavior. Failed saves never show success feedback, and a local toast remains available if the alert library cannot load.

## Verification

- `php tests/hr-faculty-pds.php`: name suggestions and section validation.
- `php tests/hr-faculty-pds.php --database`: rollback-only persistence, ownership/link changes, conflicts, and shared-data isolation checks.
- `php tests/hr-faculty-pds-remaining.php --database`: all remaining scalar/record fields, conditional normalization, file content checks, isolation, attachment revision conflicts and atomic rollback.
- `php tests/hr-faculty-pds-http.php`: local HTTP and real multipart endpoint checks, private owner downloads, rejected foreign/student access, temporary sample accounts and rollback-only writes.
- `php tests/faculty-pds-preview.php --hr --saved`: rendered form/schema parity for all eleven sections, saved-list precedence, radio restoration, file links, escaping, and form boundaries.
- `node tests/hr-faculty-pds-dom.cjs`: offline DOM checks for every section, nested records, declaration radio serialization, multipart uploads, file replacement/removal, review, errors, concurrency and preserving edits. Its temporary testing dependency is documented at the top of the test.

The migration and database/HTTP checks require access to the configured local MySQL server. The live browser should also be checked when computer-use access is available.
