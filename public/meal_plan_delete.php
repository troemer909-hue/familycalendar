<?php
require_once __DIR__ . '/../src/bootstrap.php'; requireLogin(); requireCsrf();
$res = currentApiClient()->request('DELETE', '/api/meal-plan/'.(int)($_POST['id']??0)); handleUnauthorized($res);
header('Location: meal_plan.php?week='.urlencode($_POST['week']??date('Y-m-d'))); exit;
