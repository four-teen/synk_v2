<?php
if (!isset($class,$materialsReady) || $view !== 'materials' || ($_SESSION['role'] ?? '') !== 'professor') { http_response_code(404); return; }
?>
<section class="card material-workspace" id="learningMaterials" aria-labelledby="materialTitle">
  <header class="material-header"><div><h2 id="materialTitle"><i class="bx bx-folder-open" aria-hidden="true"></i>Learning materials</h2><p>Your syllabus and teaching resources for this subject.</p></div><a class="btn btn-outline-secondary" href="<?= synk_professor_h(synk_professor_class_url_lms($class)) ?>">Back to overview</a></header>
  <?php if (!$materialsReady): ?>
    <div class="material-body"><p class="alert alert-info" role="status">Learning materials are temporarily unavailable. Please contact your administrator.</p></div>
  <?php else: ?>
    <div class="material-body">
      <div class="material-summary"><p><strong>Two files per subject</strong><span>PDF or PowerPoint (.pdf, .ppt, .pptx) only.</span></p><span id="materialCount" class="material-count" aria-live="polite"><?= count($materials) ?> / 2 files</span></div>
      <div class="material-columns">
        <form id="materialForm" class="material-upload" enctype="multipart/form-data">
          <span class="material-upload-icon"><i class="bx bx-cloud-upload" aria-hidden="true"></i></span>
          <h3>Upload syllabus or materials</h3>
          <p>Choose up to two files, including your syllabus, slides, or lesson notes.</p>
          <label for="materialFiles" class="form-label">PDF or PowerPoint files</label>
          <input id="materialFiles" class="form-control" type="file" name="materials[]" accept=".pdf,.ppt,.pptx,application/pdf,application/vnd.ms-powerpoint,application/vnd.openxmlformats-officedocument.presentationml.presentation" multiple required aria-describedby="materialFileHelp materialSelection">
          <p id="materialFileHelp" class="material-help">Maximum <?= synk_professor_h(rtrim(rtrim(number_format(synk_material_limit_lms()/1048576,2,'.',''),'0'),'.')) ?> MB per file. Remove a file to free a slot when both are used.</p>
          <p id="materialSelection" class="material-help" aria-live="polite"></p>
          <button class="btn btn-primary" id="materialUpload" type="submit"><i class="bx bx-upload" aria-hidden="true"></i>Upload files</button>
          <p id="materialFormStatus" class="material-help" role="status" aria-live="polite"></p>
        </form>
        <section class="material-library" aria-labelledby="materialLibraryTitle">
          <div class="material-list-heading"><h3 id="materialLibraryTitle">Uploaded files</h3><button class="btn btn-sm btn-outline-secondary" type="button" id="materialRefresh">Refresh</button></div>
          <div id="materialList" aria-live="polite"></div>
        </section>
      </div>
    </div>
    <script id="materialConfig" type="application/json"><?= json_encode(['endpoint'=>'learning_materials_api_lms.php','download'=>'learning_material_download_lms.php','csrf_token'=>$_SESSION['lms_material_csrf'],'context'=>array_intersect_key($_GET,array_flip(['offering_id','ay_id','semester','view','signature'])),'max_bytes'=>synk_material_limit_lms(),'materials'=>$materials],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
  <?php endif; ?>
</section>
