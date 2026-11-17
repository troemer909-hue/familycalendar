<?php
require_once __DIR__ . '/../src/bootstrap.php'; requireLogin(); requireCsrf();
$id=(int)($_POST['id']??0); $res=currentApiClient()->request('POST', "/api/meal-plan/$id/add-to-shopping-list", ['label'=>trim($_POST['label']??'')]); handleUnauthorized($res);
$target='meal_plan.php?week='.urlencode($_POST['week']??date('Y-m-d'));
if(!$res['ok']) $target.='&error='.urlencode($res['data']['error']??'Zutat konnte nicht hinzugefügt werden.');
header('Location: '.$target); exit;
