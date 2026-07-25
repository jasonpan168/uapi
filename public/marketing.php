<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
header("Location: store.php?tab=marketing");
exit;

