# Professor learning materials

Open **Manage Class → Learning materials** to upload a syllabus, slides, or notes.
Each professor's assigned subject/section in an academic year and semester has
two file slots. Upload one or two files at once; remove an existing file before
adding another when both slots are occupied. Download preserves the original name
and bytes. Removal frees a slot; the original document can be uploaded again.

Only `.pdf`, `.ppt`, and `.pptx` are accepted, with a maximum of 10 MB per file
(reduced automatically when PHP's upload/request limits are lower). Extension
and file contents are checked on the server. Empty, truncated uploads, renamed
non-documents, malformed Open XML presentations and macro-enabled PPTX packages
are rejected. PDF signature/ending and the legacy PowerPoint compound-document
stream are checked; this is format validation, not an antivirus scan.

Storage is entirely inside the separate LMS database. Install with
`php backend/install_learning_materials_lms.php`. `tbl_lms_material_collections`
scopes ownership to faculty, offering, academic year and semester;
`tbl_lms_material_files` stores metadata and binary content. A collection row lock
serializes uploads and removals. Two unique enum slots enforce the cap even for
concurrent requests. Batch validation and transactions prevent partial uploads.
Pages only check storage readiness; they never create tables.

Every API action requires the existing signed class context, current workload
ownership, active professor identity, and a session CSRF token. Downloads recheck
identity, signature and ownership, return attachments with `nosniff`, and never
expose a public upload path. List/overview requests fetch metadata only. Neither
core academic records nor Management settings or history are modified.

Checks:

- `php tests/learning-materials_lms.php` — rollback-only format, scope, limit,
  removal and authorization checks.
- `php tests/learning-materials-http_lms.php` — actual PHP web runtime, multipart
  uploads, exact downloads, headers and two sessions competing for one slot.
  Uses tagged test files in an unused material collection and removes them afterward.
- `php tests/professor-class_lms.php` — class links, views and ownership regression.
