<?php
require_once __DIR__ . '/../src/bootstrap.php'; requireLogin(); requireCsrf();
$client = currentApiClient(); $id = (int)($_POST['id'] ?? 0);
$body = ['entry_date'=>$_POST['entry_date']??'', 'meal_slot'=>$_POST['meal_slot']??'', 'label'=>$_POST['label']??'', 'notes'=>$_POST['notes']??''];
$res = $id ? $client->request('PUT', "/api/meal-plan/$id", $body) : $client->request('POST', '/api/meal-plan', $body);
handleUnauthorized($res); $week = $_POST['week'] ?? date('Y-m-d');
header('Location: meal_plan.php?week='.urlencode($week).($res['ok']?'':'&error='.urlencode($res['data']['error']??'Speichern fehlgeschlagen.'))); exit;
