<?php
session_start();

require_once '../backend/executive_analytics_helper.php';

synk_exec_analytics_logout();

header('Location: login.php?logged_out=1');
exit;
