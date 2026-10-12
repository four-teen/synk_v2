<?php // Opt-in photo crop controls for the Dean and Professor profile editors. ?>
<template id="faculty-photo-crop-template">
  <section class="faculty-photo-crop" hidden aria-labelledby="faculty-photo-crop-title">
    <input type="hidden" name="<?= synk_faculty_profile_h($profileEditor['photo_crop_field']) ?>" value="">
    <div class="faculty-photo-crop-heading">
      <h3 id="faculty-photo-crop-title">Frame the faculty photo</h3>
      <p>Keep the head and shoulders in view. Drag the photo or use the controls to adjust the crop.</p>
    </div>
    <div class="faculty-photo-crop-grid">
      <div class="faculty-photo-crop-preview">
        <canvas id="faculty-photo-crop-canvas" width="600" height="600" tabindex="0" role="img" aria-label="Faculty photo crop preview. Use arrow keys to adjust the crop." aria-describedby="faculty-photo-crop-status"></canvas>
        <span>Square preview · displayed as a circle in the header</span>
      </div>
      <div class="faculty-photo-crop-controls">
        <p id="faculty-photo-crop-status" role="status" aria-live="polite"></p>
        <label for="faculty-photo-zoom">Zoom</label>
        <input id="faculty-photo-zoom" type="range" min="1" max="8" step="0.01" value="1">
        <label for="faculty-photo-horizontal">Horizontal position</label>
        <input id="faculty-photo-horizontal" type="range" min="0" max="100" step="0.1" value="50">
        <label for="faculty-photo-vertical">Vertical position</label>
        <input id="faculty-photo-vertical" type="range" min="0" max="100" step="0.1" value="50">
        <div class="faculty-photo-crop-actions">
          <button type="button" class="btn btn-sm btn-outline-primary" id="faculty-photo-auto">Auto crop</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" id="faculty-photo-reset">Reset crop</button>
        </div>
        <label class="faculty-photo-crop-original" for="faculty-photo-original">
          <input id="faculty-photo-original" type="checkbox"> Keep the full photo
        </label>
        <small>The selected area will be saved when you choose Save profile.</small>
      </div>
    </div>
  </section>
</template>
