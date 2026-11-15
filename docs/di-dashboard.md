# DI academic and faculty dashboard

`di/index.php` uses the read-only `synk_di_dashboard` data source. Every request
reads the academic year and semester from `tbl_academic_settings`. Query-string
years/semesters and Scheduler working-term session values cannot override them.
If settings are unavailable, term counts stay zero; they never fall back to history.

The three dashboard groups are:

1. Academic overview. Campus and college counts describe active locations in the
   selected scope. Programs, subjects, prospectus versions, sections, and class
   offerings come from valid offerings in the official term. IDs are counted
   distinctly: two different subjects with the same code remain two subjects.
   Section/curriculum validity follows the existing shared read helper. Program
   deactivation does not erase valid existing term activity from these counts.
2. Faculty by campus. The principal count is active faculty with at least one
   current-term subject assignment, using the same validated workload source as
   the Professor portal. Lecture/lab rows and multiple colleges cannot duplicate
   a faculty member within a campus or the university total. Faculty teaching
   across campuses appear once in each applicable campus card, and once in the
   university total. A separate roster count includes distinct active faculty
   added to colleges for this term, including those without subjects yet.
   Empty active campuses retain their zero-count cards.
   Clicking a campus opens `di/campus-faculty.php` with all its teaching faculty
   in alphabetical name order. Its college filter contains only active colleges
   in that campus and follows teaching assignments. Cards show saved profile rank,
   current-term designation, and the highest completed degree's name (ongoing
   studies do not replace it). Missing profiles remain listed. The grid uses four
   columns on desktop, two on tablets, and one on phones. View Details opens the
   existing faculty review page and preserves the campus/college when returning.
3. Faculty profile statistics. One profile per teaching faculty member is read
   through the existing faculty/profile relationship, including faculty-owned
   profiles without a login account. Details use the professor profile validation
   and completion rules, at every completion level. Missing/invalid values are
   explicit. Highest completed degree and ongoing studies are separate. Profile
   details are the latest saved values; they are not historical term snapshots.

Campus/college filters use teaching assignments for the cohort, independently of
the faculty member's home or profile affiliation. The faculty directory and its
existing profile coverage report retain their independent, all-term behavior.
Dashboard reads do not initialize schemas or modify scheduling, workloads,
settings, faculty records, or profiles.

Validation:

```text
php tests/di-dashboard.php
node tests/di-dashboard-browser.cjs --screenshot
php tests/di-faculty-directory.php
php tests/professor-workload.php --database
```

Dashboard fixtures roll back all temporary records. A guarded connection rejects
database writes during analytics reads. Browser tests render the rolled-back
fixtures in an isolated headless browser, including empty/missing-term states.
