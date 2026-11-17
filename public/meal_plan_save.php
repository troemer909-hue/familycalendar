<?php
require_once __DIR__ . '/../src/bootstrap.php'; requireLogin(); requireCsrf();
$client=currentApiClient(); $id=(int)($_POST['id']??0);
$data=['entry_date'=>$_POST['entry_date']??'','meal_slot'=>$_POST['meal_slot']??'','label'=>$_POST['label']??'','notes'=>$_POST['notes']??''];
$res=$client->request($id?'PUT':'POST',$id?"/api/meal-plan/{$id}":'/api/meal-plan',$data); handleUnauthorized($res);
if(!$res['ok']) { header('Location: meal_plan.php?week='.urlencode(substr($data['entry_date'],0,10)).'&error='.urlencode($res['data']['error']??'Speichern fehlgeschlagen.')); exit; }
header('Location: meal_plan.php?week='.urlencode(substr($data['entry_date'],0,10))); exit;
