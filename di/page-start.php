<?php if (!isset($moduleAccount, $pageTitle)) { http_response_code(404); exit; } ?>
<!DOCTYPE html>
<html lang="en" class="light-style layout-menu-fixed" dir="ltr" data-theme="theme-default" data-assets-path="../assets/" data-template="vertical-menu-template-free">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= synk_leadership_h($pageTitle) ?> | Synk</title>
  <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png">
  <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css">
  <link rel="stylesheet" href="../assets/vendor/css/core.css">
  <link rel="stylesheet" href="../assets/vendor/css/theme-default.css">
  <link rel="stylesheet" href="../assets/css/demo.css">
  <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css">
  <link rel="stylesheet" href="custom_css.css?v=<?= filemtime(__DIR__ . '/custom_css.css') ?>">
  <link rel="stylesheet" href="directory.css?v=<?= filemtime(__DIR__ . '/directory.css') ?>">
  <script src="../assets/vendor/js/helpers.js"></script><script src="../assets/js/config.js"></script>
</head>
<body><div class="layout-wrapper layout-content-navbar"><div class="layout-container">
<?php include __DIR__ . '/sidebar.php'; ?>
<div class="layout-page"><?php include __DIR__ . '/navbar.php'; ?><div class="content-wrapper">
<main class="container-xxl flex-grow-1 container-p-y">
