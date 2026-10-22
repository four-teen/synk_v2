# My Researches: Oracle to Synk

The Professor sidebar includes **My Researches**. Opening it automatically
matches the signed-in professor's institutional email with Oracle and loads
their assigned portfolio as adviser, panelist, statistician, or English critic.
Encoding, ownership, and coauthorship alone do not grant access. Role tabs filter the list, search finds records across
that portfolio, and infinite scrolling reveals more results automatically.

## Files and deployment

| Application | File | Purpose |
| --- | --- | --- |
| Oracle | `synk-research-api.php` in this folder | The single PHP file to upload beside Oracle's live `index.php` |
| Synk | `professor/oracle-research.php` | Authenticated portfolio, research details, and attachment downloads |
| Synk | `professor/oracle-research.css` | Research page styles |
| Synk | `professor/oracle-research.js` | Role tabs, account-only search, and infinite scrolling |
| Synk | `professor/sidebar.php` | Existing sidebar updated with the My Researches menu |
| Synk | `professor/index.php`, `professor/dashboard.css`, `professor/dashboard-charts.js`, `professor/dashboard.js` | Teaching and Oracle research dashboard with ApexCharts |
| Synk | `professor/dashboard-research.php` | Authenticated, account-only research summary for the dashboard |
| Synk | `backend/professor_dashboard_helper.php`, `backend/professor_dashboard_view.php` | Dashboard summaries and layout |
| Synk | `assets/img/sdgs/sdg-01.png` through `sdg-17.png` | Local SDG badges reused from Oracle |

No Oracle application files or database tables need changing. On Synk, the
sidebar update is required for the new menu. Synk's existing theme assets and
database configuration are reused.

**Upgrade from the first integration:** upload the revised
`synk-research-api.php` from this folder to the same live location. Keep the
existing shared secret. The new page requires API version **2**, which includes
academic assignments and the full-portfolio action. Version 1 only returned ownership and
coauthorship and cannot supply the advisory and other assignment groups. If
the old endpoint is still deployed, Synk displays an explicit update message
instead of an empty portfolio.

**Academic assignments only:** the current Oracle file grants access only for
adviser, panelist, statistician, and English critic assignments. Upload this
revised file to the live Oracle server, retaining the shared secret. Synk also
filters responses from the older version 2 endpoint immediately: encoder-only,
owner-only, and coauthor-only records are removed before rendering, indexing,
or counting. Details and downloads require an allowed academic role; downloads
first verify the signed account-specific detail response before requesting bytes.
An encoder who also has an allowed academic assignment remains included for that
academic role only.

The configured source in this folder retains the integration secret. Its
matching local copy is `oracle2026/synk-research-api.php`. The ZIP deployment
templates have blank secrets so an archive does not expose live credentials.

## Setup

1. Both servers need PHP **7.1 or newer** and PDO MySQL. Synk also needs cURL
   with HTTPS support and a certificate trust bundle. Oracle reuses its
   existing `app/Env.php`, `app/helpers.php`, `app/Database.php`, and `.env`.
2. For an existing installation, retain the same shared secret. For a new
   installation, generate one random 64-character lowercase hexadecimal value:

   ```powershell
   & 'C:\xampp\php\php.exe' -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
   ```

   Set that value in `shared_secret` near the top of the Oracle API and Synk
   research page. Keep configured copies out of source control. Each file can
   alternatively read the PHP process variable `SYNK_ORACLE_SHARED_SECRET` if
   its inline setting is empty. Oracle authenticates before loading `.env`, so
   that optional secret must be available in the process environment.
3. Upload the Oracle endpoint beside the live `index.php`:

   ```text
   https://sksu-oracle.com/synk-research-api.php
   ```

4. On Synk, deploy the research page, its CSS and JavaScript, the local SDG badge images, and the updated professor sidebar.
   The research page's `endpoint` setting must point to the final HTTPS address.
   If necessary, set its `ca_file` to a trusted CA bundle on the ICT server.
   Certificate verification remains enabled and redirects are rejected.
5. Run the signed health check from the ICT server:

   ```powershell
   & 'C:\xampp\php\php.exe' 'C:\VertrigoServ\www\synk\professor\oracle-research.php' --check
   ```

   Expected output:

   ```text
   Oracle research connection: authenticated, database and schema ready.
   ```

   Use the deployed server's PHP executable if it has a different path. The
   workstation's Apache uses PHP 7.1 and is also supported. A browser GET to
   the HTTPS Oracle API is not a health check; signed POST requests are required.
6. Sign into Synk and select **Professor**, then click **My Researches** in
   the sidebar. Direct links also work:

   ```text
   http://localhost/synk/professor/oracle-research.php
   http://isulan.sksu.edu.ph:8082/synk/professor/oracle-research.php
   ```

   Log in using the same hostname as the research page so the browser sends
   the correct Synk session cookie.

## Automatic role groups

| Display group | Oracle account relationship |
| --- | --- |
| Advisory | `tblresearches.adviser_accountid` |
| Panelist | `tblmanuscript_panelists.accountid`, or an exact ID in the existing `panelist_accountids` CSV field |
| Statistician | `tblresearches.statistician_accountid` |
| English Critic | `tblresearches.english_critic_accountid` |

Each relationship is matched to the `accountid` found through the faculty's
email. Normalized role tables join through
`manuscriptid = tblresearches.titleid`. The page starts on **All researches**,
with each study shown once. Selecting **Advisory**, **Panelist**, or another
role shows only studies carrying that exact role for the matched account.
Roles with no assignments are omitted. A study with several roles can match
each corresponding tab; the overall total still counts it once.

Ownership, coauthorship, and the encoder field are research metadata only.
They never establish access, create role tabs, or contribute to role counts.

Search matches title, author, abstract, record ID, year, type, status, and team
metadata within the already authorized portfolio. It ignores case and accents.
Search and the selected role are combined, and tab counts reflect the search.
Changing a tab or search starts a fresh visible batch. The complete portfolio
is searchable, including studies not yet displayed on screen.

Infinite scrolling appends 12 matching records at a time. A Load more button
also works when automatic intersection detection is unavailable. An end status
identifies when all matches are shown. When JavaScript is disabled, the page
falls back to displaying the complete authorized list without interactive filters.

The page loads all related metadata in one signed `portfolio` request. Search,
role selection, and progressive rendering then run within that authorized
snapshot, without requesting any other account's data. The single-column list
follows Oracle's main catalog: a book
icon, Oracle record ID, authors, a compact title, year/type/status indicators,
local SDG badges, an abstract excerpt, and a **View record** link. Cards omit
the submitted-date subtitle and the adviser/research-domain footer.

The full detail page includes the research team and attachment downloads. Information
includes authors, status, publication visibility, type, campus, program,
academic year, SDGs, research team, submitted/updated dates, and abstract text.
Linked records include both published and internal research for the authorized
account.

PDF, DOC, and DOCX abstract attachments up to 20 MiB can be downloaded through
Synk. Every download rechecks the same account relationships. Files must resolve
inside Oracle's `uploads/manuscript_abstracts` directory. Responses use an
attachment disposition and a generated filename; filesystem paths and the
shared secret are not exposed to the browser.

Research is maintained in Oracle. Opening or refreshing My Researches retrieves
the current records. No research is copied into Synk database tables.

## Professor dashboard

The dashboard loads teaching information immediately, then retrieves Oracle
research in the background. It shows the unique research total, the published
total, counts for each of the professor's roles, and the four most recently
updated related records. **View all** opens the complete My Researches page;
each recent title opens its authenticated detail page. Refresh retrieves a new
portfolio. Loading, empty, and unavailable states keep teaching usable when
Oracle is slow or unavailable; an unavailable count is never displayed as zero.

The research chart uses academic year on the horizontal axis and the number of
assigned researches on the vertical axis, with one colored line per program and
a program filter. The workload chart shows student enrollments on the horizontal
axis for each assigned class, using the academic year and semester set by the
administrator. Exact chart data remains available in tables below the graphs.

The same-origin `professor/dashboard-research.php` endpoint reuses the configured
`professor/oracle-research.php` file and its verified HTTPS client. There is no
second shared secret to configure and no Oracle server update is needed. The
endpoint accepts no query parameters, rechecks the active account and professor
role, and uses only the email from the current account row. Responses are private
and not cached. The browser receives only summary metadata and related records.

Profile completion appears in a compact card at the bottom of the professor
sidebar. The dashboard passes its existing calculated percentage; other portal
pages with a database connection calculate the same profile rules. When progress
cannot be read, the card links to Manage Profile without inventing a percentage.

## Access and communication

Synk requires a logged-in Professor session. It re-reads `tbl_useraccount` using
the session's `user_id`, checks an active account, and checks current professor
access in `tbl_useraccount_roles`. An explicitly revoked professor role is
denied. A primary-role fallback supports installations without the role table
or explicit role rows.

The email comes only from that current account row. The page does not accept
an email or account ID in its URL. Oracle lowercases and trims email, requires
the configured institutional domain, and requires exactly one enabled account
match. Missing, duplicate, and disabled accounts return clear errors.

Relations use account IDs. A name mentioned in a title, abstract, or free-text
author field does not establish access. CSV panel membership uses complete
IDs, so account 10 cannot match 110. Detail and attachment requests use the
same role check as the full portfolio.

Synk sends JSON over verified HTTPS, signed with HMAC-SHA256 and a Unix
timestamp. Oracle compares signatures using `hash_equals` and permits a
five-minute timestamp difference. Synk can retry once using the verified
HTTPS response's Date header when clocks differ by five to fifteen minutes.
The same HMAC and Oracle expiry checks still apply; no system clock is changed.
Larger differences require clock synchronization. No one-use nonce registry
is maintained; every supported operation is a read.

The shared secret authenticates Synk as the trusted integration. Synk enforces
the logged-in faculty restriction before signing requests. The Oracle endpoint
does not independently validate that faculty member's Google session.

Only outbound HTTPS from the ICT server to Oracle is required. The browser
talks to Synk; it does not call the Oracle API directly. Oracle loads only its
configuration helpers and `Database::connection()`. It avoids bootstrap,
sessions, migrations, and `Database::ensure*()` methods. Its database operations
are SELECT queries.

The existing schema is probed for the account/role fields above, research
metadata/abstract fields, both manuscript role tables, and metadata tables
`tblresearchtype`, `tblcampus`, `tblcourse`, and `tblacademic_year`. A missing
table or column produces an unavailable response and an Oracle PHP error-log
entry. The API does not alter the live schema. HTTPS must be visible to Oracle
PHP through native `HTTPS` or server-port settings; arbitrary forwarded
protocol headers are not trusted.

## Verification

```powershell
& 'C:\xampp\php\php.exe' tests/oracle-research.php
& 'C:\xampp\php\php.exe' tests/oracle-research.php --mysql
node tests/oracle-research-transport.cjs
node tests/oracle-research-browser.cjs
& 'C:\xampp\php\php.exe' tests/professor-dashboard.php
node tests/professor-dashboard-browser.cjs
```

Tests cover all four academic roles, simultaneous roles, deduplication, exact legacy panel
IDs, cross-account denial, private assigned records, authorized attachments,
HTML escaping, role tabs, account-only search, infinite scroll, sidebar selection,
and mobile navigation. Tests also verify exclusion of encoder-only, owner-only,
and coauthor-only records, including legacy API responses, guessed detail URLs,
and blocked attachment requests before any file bytes are fetched.
SQLite fixtures run in query-only mode. The MySQL variant creates and removes
a disposable database; application tables are not changed. HTTPS transport
tests use a local server with a disposable certificate, including a PHP 7.1
client check when available. Browser tests save desktop/mobile screenshots in
`.tmp`.

Final live acceptance requires uploading API version 2, running the health
check, and opening My Researches under a Professor session. The previous live
investigation found 24 public records listing ELBREN ANTONIO as adviser; these
were excluded by version 1's authorship-only scope.

Security references: [OWASP REST security](https://cheatsheetseries.owasp.org/cheatsheets/REST_Security_Cheat_Sheet.html),
[PHP cURL options](https://www.php.net/manual/en/function.curl-setopt.php),
[PHP signature comparison](https://www.php.net/manual/en/function.hash-equals.php).
