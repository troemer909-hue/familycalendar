<?php
require_once __DIR__ . '/../src/bootstrap.php';

// Token liegt im eigenen Langzeit-Cookie (siehe loadConfig()/saveConfig()
// in bootstrap.php), nicht mehr in $_SESSION - session_destroy() allein
// würde ihn nicht mehr entfernen.
saveConfig(['token' => null]);

$_SESSION = [];
session_destroy();

header('Location: login.php');
exit;
