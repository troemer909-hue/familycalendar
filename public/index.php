<?php
require_once __DIR__ . '/../src/bootstrap.php';

$config = loadConfig();
header('Location: ' . (!empty($config['token']) ? 'calendar.php' : 'login.php'));
exit;
