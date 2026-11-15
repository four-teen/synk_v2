<?php
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../backend/di_dashboard_helper.php';
require_once __DIR__ . '/../backend/faculty_profile_image_helper.php';

$pageTitle = 'Campus Faculty';
$pageStyles = ['campus-faculty.css?v=' . filemtime(__DIR__ . '/campus-faculty.css')];
$pageError = '';
try {
    $campusId = synk_di_dashboard_filter($_GET['campus_id'] ?? null);
    if (!$campusId) throw new DomainException('Choose a campus from Faculty by Campus on the dashboard.', 422);
    $dashboard = synk_di_dashboard($conn, ['campus_id' => $campusId, 'college_id' => $_GET['college_id'] ?? 0]);
    $campus = $dashboard['campuses'][$campusId];
    $collegeId = $dashboard['college_id'];
    $colleges = array_filter($dashboard['colleges'], static function (array $college) use ($campusId): bool {
        return (int)$college['campus_id'] === $campusId;
    });
    $pageTitle = $campus['campus_name'] . ' Faculty';
    $moduleTerm = $dashboard['term'];
} catch (Throwable $exception) {
    http_response_code($exception instanceof DomainException ? 422 : 503);
    $pageError = $exception instanceof DomainException ? $exception->getMessage() : 'Campus faculty are temporarily unavailable. Please try again.';
    if (!$exception instanceof DomainException) error_log('DI campus faculty: ' . $exception->getMessage());
}
include __DIR__ . '/page-start.php';
?>
<?php if ($pageError): ?>
  <div class="alert alert-warning" role="alert"><?= synk_leadership_h($pageError) ?></div>
  <a class="btn btn-outline-primary" href="index.php#campus-faculty">Back to Faculty by Campus</a>
<?php else: ?>
<div class="di-directory-heading">
  <div><span class="di-eyebrow">DI / Faculty by Campus / <?= synk_leadership_h($campus['campus_code']) ?></span><h1><?= synk_leadership_h($campus['campus_name']) ?> Faculty</h1><p>Explore faculty profiles across the campus or choose a college below.</p></div>
  <a class="btn btn-outline-secondary" href="index.php#campus-faculty"><i class="bx bx-arrow-back me-1" aria-hidden="true"></i>Back to dashboard</a>
</div>
<section class="card mb-4" aria-label="Filter campus faculty"><div class="card-body di-campus-faculty-toolbar">
  <form action="campus-faculty.php" method="get" class="di-campus-college-filter">
    <input type="hidden" name="campus_id" value="<?= $campusId ?>">
    <div><label class="form-label" for="campus-college">College</label><select class="form-select" id="campus-college" name="college_id"><option value="0">All colleges in this campus</option><?php foreach ($colleges as $id => $college): ?><option value="<?= $id ?>"<?= $collegeId === $id ? ' selected' : '' ?>><?= synk_leadership_h($college['college_name']) ?></option><?php endforeach; ?></select></div>
    <button class="btn btn-primary" type="submit">Show faculty</button>
    <?php if ($collegeId): ?><a class="btn btn-outline-secondary" href="campus-faculty.php?campus_id=<?= $campusId ?>">All colleges</a><?php endif; ?>
  </form>
  <div class="di-campus-faculty-term"><i class="bx bx-calendar" aria-hidden="true"></i><span>Current teaching faculty<strong><?= synk_leadership_h($moduleTerm['term_text']) ?></strong></span></div>
</div></section>
<?php if (!$dashboard['term_ready']): ?><div class="alert alert-info" role="status">Set the current academic year and semester to see teaching faculty for this campus.</div><?php endif; ?>
<section aria-labelledby="campus-faculty-heading">
  <div class="di-campus-faculty-list-heading"><div><h2 id="campus-faculty-heading"><?= synk_leadership_h($collegeId ? $colleges[$collegeId]['college_name'] : 'All campus faculty') ?></h2><p><?= number_format(count($dashboard['faculty'])) ?> faculty · Alphabetical A–Z</p></div><span class="di-campus-faculty-order"><i class="bx bx-sort-a-z" aria-hidden="true"></i>By name</span></div>
  <div class="di-campus-faculty-grid">
    <?php foreach ($dashboard['faculty'] as $record):
        $photoUrl = synk_faculty_profile_image_url($record['profile_user_id'], $record['faculty_id']);
        $collegeNames = [];
        foreach ($record['college_ids'] as $id) if (isset($colleges[$id])) $collegeNames[] = $colleges[$id]['college_name'];
        $detailsUrl = 'faculty-profile.php?' . http_build_query(['faculty_id' => $record['faculty_id'], 'campus_id' => $campusId, 'college_id' => $collegeId]);
    ?>
    <article class="card di-campus-faculty-card" data-faculty-id="<?= $record['faculty_id'] ?>"><div class="card-body">
      <div class="di-campus-faculty-avatar"><?php if ($photoUrl): ?><img src="<?= synk_leadership_h($photoUrl) ?>" alt="" loading="lazy"><?php else: ?><i class="bx bx-user" aria-hidden="true"></i><?php endif; ?></div>
      <h3><?= synk_leadership_h($record['name']) ?></h3><p class="di-campus-faculty-college"><?= synk_leadership_h(implode(' / ', $collegeNames)) ?></p>
      <dl><div><dt>Academic rank</dt><dd><?= synk_leadership_h($record['analytics_values']['faculty_rank'] ?: 'Not recorded') ?></dd></div><div><dt>Designation</dt><dd><?= synk_leadership_h($record['designation_name'] ?: 'Not recorded') ?></dd></div><div><dt>Highest completed degree</dt><dd><?= synk_leadership_h($record['highest_degree_name'] ?: 'Not recorded') ?><?php if ($record['highest_degree_name']): ?><small><?= synk_leadership_h($record['highest_degree']) ?></small><?php endif; ?></dd></div></dl>
      <a class="btn btn-outline-primary w-100" href="<?= synk_leadership_h($detailsUrl) ?>" aria-label="View details for <?= synk_leadership_h($record['name']) ?>">View Details<i class="bx bx-right-arrow-alt ms-1" aria-hidden="true"></i></a>
    </div></article>
    <?php endforeach; ?>
  </div>
  <?php if (!$dashboard['faculty']): ?><div class="card"><div class="card-body di-campus-faculty-empty"><i class="bx bx-user-voice" aria-hidden="true"></i><h3>No faculty to show</h3><p>No active faculty have subject assignments <?= $collegeId ? 'in this college' : 'at this campus' ?> for the current academic term.</p><?php if ($collegeId): ?><a class="btn btn-outline-primary" href="campus-faculty.php?campus_id=<?= $campusId ?>">View all campus faculty</a><?php endif; ?></div></div><?php endif; ?>
</section>
<?php endif; ?>
<?php include __DIR__ . '/page-end.php'; ?>
