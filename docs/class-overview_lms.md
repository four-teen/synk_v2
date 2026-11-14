# Professor class overview

Class views share a compact, full-width navigation bar with square outer corners
and icons above five short labels: Overview, Students, Activities, Grading and
Grade Book. The current view shows only its centered icon in a circle using the
disabled primary button's lavender tint, seated in a smooth curved recess in the
white bar. The selected link retains its full accessible name.
The recess uses a concentric circular arc with a consistent 4px gap around the
selected circle and mirrored shoulders. Separate unscaled SVG geometry matches
the desktop, phone and narrow-phone circle sizes to preserve equal spacing.
The bar is 48px tall on desktop and 44px on phones, with 44px minimum touch
targets. All five links stay in one row on desktop and phones,
retain their signed class URLs and full accessible names, and expose the selected
view through `aria-current="page"`. Focus outlines and reduced motion remain
available. The class-page header keeps the academic-term badge and profile
controls while omitting the Professor Portal and Manage Class badges.

The page content shares the top navbar's outer edges. A compact toolbar places
the workload back button on the left and the tightly spaced, right-aligned page
title and subtitle on the right. The subject header shows the subject and section/
program only; the duplicate term badge, enrollment total, and workspace label are
removed. The enrollment count remains in the Students card.

Manage Class shows six cards, with three columns on desktop, two on tablet, and
one on phones. Students, Activities & assessments, Manage Grading System and Grade Book open
their existing signed class views. The entire card is clickable and keyboard
accessible. Hover and focus make the click target visible; reduced motion is
respected.

Footers show the recorded enrollment total from the class workload, the number
of non-archived assessments across Midterm and Final, and whether each grading
period has saved active components totaling 100%. Assessment and grading summaries
are read from the separate LMS database, scoped to professor, offering, academic
year, and semester. Aggregate queries do not load questions or answer keys. Missing
or unavailable storage uses purpose text in place of invented zero counts.

All six overview cards use pastel headers with a colored top border, following
the workload cards. Their white bodies retain the existing metrics and descriptions.
Grade Book has a purpose footer with no invented count and opens the signed
[gradebook workspace](gradebook_lms.md). Announcements opens its signed class editor.
Learning materials opens the signed [upload workspace](learning-materials_lms.md)
and displays its current file count out of two. The overview does not write core
academic, scheduling, workload, or Management records.
