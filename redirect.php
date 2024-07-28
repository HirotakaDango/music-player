<?php
// Determine the back URL or default to the current URL with a specified path
$backUrl = isset($_GET['back']) ? $_GET['back'] : (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/admin/images_section/';

// Redirect to the specified URL
header("Location: " . $backUrl);
exit();
?>