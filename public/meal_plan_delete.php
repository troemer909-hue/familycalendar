<?php
require_once __DIR__ . '/../src/bootstrap.php'; requireLogin(); requireCsrf();
$id=(int)($_POST['id']??0); $client=currentApiClient(); $res=$client->request('DELETE',"/api/meal-plan/{$id}"); handleUnauthorized($res);
if(!$res['ok']) { header('Location: meal_plan.php?error='.urlencode($res['data']['error']??'Löschen fehlgeschlagen.')); exit; } header('Location: meal_plan.php'); exit;
