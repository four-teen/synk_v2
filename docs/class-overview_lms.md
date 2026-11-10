# Professor class overview

Manage Class shows six cards, with three columns on desktop, two on tablet, and
one on phones. Students, Activities & assessments, and Manage Grading System open
their existing signed class views. The entire card is clickable and keyboard
accessible. Hover and focus make the click target visible; reduced motion is
respected.

Footers show the recorded enrollment total from the class workload, the number
of non-archived assessments across Midterm and Final, and whether each grading
period has saved active components totaling 100%. Assessment and grading summaries
are read from the separate LMS database, scoped to professor, offering, academic
year, and semester. Aggregate queries do not load questions or answer keys. Missing
or unavailable storage uses purpose text in place of invented zero counts.

Grade Book has a purpose footer with no count. It, Learning materials, and
Announcements are planned tools; clicking their cards explains the feature using
the existing SweetAlert2 style. No gradebook, materials, or announcement storage or
recording is implemented in this overview change, and no core academic, scheduling,
workload, or Management records are changed.
