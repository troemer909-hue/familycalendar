<?php
require_once __DIR__ . '/../src/bootstrap.php'; requireLogin(); requireCsrf();
$id=(int)($_POST['id']??0); $client=currentApiClient(); $res=$client->request('POST',"/api/meal-plan/{$id}/add-to-shopping-list",['label'=>$_POST['label']??'']); handleUnauthorized($res);
if(!$res['ok']) { header('Location: meal_plan.php?error='.urlencode($res['data']['error']??'Zutat konnte nicht hinzugefügt werden.')); exit; } header('Location: meal_plan.php?notice='.urlencode('Zutat wurde zur Einkaufsliste hinzugefügt.')); exit;
