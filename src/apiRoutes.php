<?php

declare(strict_types=1);

use Familienkalender\Controllers\AdminFamilyController;
use Familienkalender\Controllers\AdminPlanController;
use Familienkalender\Controllers\AuthController;
use Familienkalender\Controllers\CalendarSourceController;
use Familienkalender\Controllers\CategoryController;
use Familienkalender\Controllers\EventController;
use Familienkalender\Controllers\IcsExportController;
use Familienkalender\Controllers\MemberController;
use Familienkalender\Controllers\NotificationPreferenceController;
use Familienkalender\Controllers\PushSubscriptionController;
use Familienkalender\Controllers\ReminderController;
use Familienkalender\Controllers\SelfUpdateController;
use Familienkalender\Controllers\TaskListController;
use Familienkalender\Core\Response;
use Familienkalender\Core\Router;
use Familienkalender\Middleware\AuthMiddleware;

/**
 * Baut die komplette API-Routentabelle auf - inhaltlich identisch mit dem,
 * was früher direkt in public/api.php stand. Ausgelagert, damit sowohl
 * public/api.php (echte HTTP-Requests) als auch LocalApiClient
 * (In-Process-Aufrufe von den Client-Seiten aus, siehe dort) exakt
 * dieselben Routen kennen - EINE Quelle statt einer zweiten, potenziell
 * abweichenden Kopie.
 */
function buildApiRouter(): Router
{
    $router = new Router();
    $authMiddleware = new AuthMiddleware();

    // -- Auth (öffentlich) --------------------------------------------------
    $auth = new AuthController();
    $router->post('/api/auth/register', [$auth, 'register']);
    $router->post('/api/auth/login', [$auth, 'login']);
    $router->post('/api/auth/password-reset/request', [$auth, 'requestPasswordReset']);
    $router->post('/api/auth/password-reset/confirm', [$auth, 'confirmPasswordReset']);
    $router->post('/api/auth/invite/accept', [$auth, 'acceptInvite']);

    // -- Mitglieder (geschützt) ----------------------------------------------
    $members = new MemberController();
    $router->get('/api/members', [$members, 'index'], [$authMiddleware]);
    $router->post('/api/members', [$members, 'createProfile'], [$authMiddleware]);
    $router->post('/api/members/{id}/invite', [$members, 'invite'], [$authMiddleware]);
    $router->delete('/api/members/{id}/login', [$members, 'downgrade'], [$authMiddleware]);
    $router->post('/api/members/{id}/transfer-admin', [$members, 'transferAdmin'], [$authMiddleware]);

    // -- Termine (geschützt) --------------------------------------------------
    $events = new EventController();
    $router->get('/api/events', [$events, 'index'], [$authMiddleware]);
    $router->get('/api/events/{id}', [$events, 'show'], [$authMiddleware]);
    $router->post('/api/events', [$events, 'create'], [$authMiddleware]);
    $router->put('/api/events/{id}', [$events, 'update'], [$authMiddleware]);
    $router->delete('/api/events/{id}', [$events, 'delete'], [$authMiddleware]);
    $router->delete('/api/events/{id}/series', [$events, 'deleteSeries'], [$authMiddleware]);

    // -- Erinnerungen (geschützt, persönlich je Login-Nutzer) ---------------
    $reminders = new ReminderController();
    $router->get('/api/events/{id}/reminders', [$reminders, 'index'], [$authMiddleware]);
    $router->post('/api/events/{id}/reminders', [$reminders, 'create'], [$authMiddleware]);
    $router->delete('/api/reminders/{id}', [$reminders, 'destroy'], [$authMiddleware]);

    // -- Termin-Kategorien (geschützt, Verwaltung nur im "plus"-Plan) -------
    $categories = new CategoryController();
    $router->get('/api/categories', [$categories, 'index'], [$authMiddleware]);
    $router->post('/api/categories', [$categories, 'create'], [$authMiddleware]);
    $router->put('/api/categories/{id}', [$categories, 'update'], [$authMiddleware]);
    $router->delete('/api/categories/{id}', [$categories, 'destroy'], [$authMiddleware]);

    // -- Aufgabenlisten (geschützt, kein Plan-Gating - siehe TaskListController) --
    $taskLists = new TaskListController();
    $router->get('/api/task-lists', [$taskLists, 'index'], [$authMiddleware]);
    $router->get('/api/tasks/due', [$taskLists, 'dueTasks'], [$authMiddleware]);
    $router->post('/api/task-lists', [$taskLists, 'create'], [$authMiddleware]);
    $router->delete('/api/task-lists/{id}', [$taskLists, 'destroy'], [$authMiddleware]);
    $router->get('/api/task-lists/{id}/tasks', [$taskLists, 'tasks'], [$authMiddleware]);
    $router->post('/api/task-lists/{id}/tasks', [$taskLists, 'createTask'], [$authMiddleware]);
    $router->put('/api/tasks/{id}', [$taskLists, 'updateTask'], [$authMiddleware]);
    $router->delete('/api/tasks/{id}', [$taskLists, 'deleteTask'], [$authMiddleware]);

    // -- Push-Benachrichtigungen (geschützt, optional - siehe PushSender) ---
    $push = new PushSubscriptionController();
    $router->get('/api/push/vapid-public-key', [$push, 'vapidPublicKey'], [$authMiddleware]);
    $router->post('/api/push/subscribe', [$push, 'subscribe'], [$authMiddleware]);
    $router->delete('/api/push/subscribe', [$push, 'unsubscribe'], [$authMiddleware]);

    // -- Benachrichtigungseinstellungen (geschützt, persönlich je Login-Nutzer)
    $notificationPreferences = new NotificationPreferenceController();
    $router->get('/api/notification-preferences', [$notificationPreferences, 'show'], [$authMiddleware]);
    $router->put('/api/notification-preferences', [$notificationPreferences, 'update'], [$authMiddleware]);

    // -- Kalenderquellen: ICS/CSV-Import & ICS-Abos (geschützt) -------------
    $calendarSources = new CalendarSourceController();
    $router->get('/api/calendar-sources', [$calendarSources, 'index'], [$authMiddleware]);
    $router->post('/api/calendar-sources/import-ics', [$calendarSources, 'importIcs'], [$authMiddleware]);
    $router->post('/api/calendar-sources/import-csv', [$calendarSources, 'importCsv'], [$authMiddleware]);
    $router->post('/api/calendar-sources/stream', [$calendarSources, 'createStream'], [$authMiddleware]);
    $router->post('/api/calendar-sources/{id}/sync', [$calendarSources, 'sync'], [$authMiddleware]);
    $router->put('/api/calendar-sources/{id}', [$calendarSources, 'update'], [$authMiddleware]);
    $router->delete('/api/calendar-sources/{id}', [$calendarSources, 'destroy'], [$authMiddleware]);

    // -- Outbound-ICS-Export --------------------------------------------------
    $icsExport = new IcsExportController();
    $router->get('/api/ics-export', [$icsExport, 'show'], [$authMiddleware]);
    $router->post('/api/ics-export/token', [$icsExport, 'generate'], [$authMiddleware]);
    $router->delete('/api/ics-export/token', [$icsExport, 'revoke'], [$authMiddleware]);
    // Bewusst OHNE $authMiddleware - der Feed wird über das geheime Token in
    // der URL selbst geschützt (siehe IcsExportController-Klassendoc).
    $router->get('/api/ics/{token}', [$icsExport, 'feed']);

    // -- Plattform-Verwaltung (geschützt, nur TenantContext::isPlatformAdmin) --
    $adminPlans = new AdminPlanController();
    $router->get('/api/admin/plans', [$adminPlans, 'index'], [$authMiddleware]);
    $router->get('/api/admin/plans/{id}', [$adminPlans, 'show'], [$authMiddleware]);
    $router->post('/api/admin/plans', [$adminPlans, 'create'], [$authMiddleware]);
    $router->put('/api/admin/plans/{id}', [$adminPlans, 'update'], [$authMiddleware]);
    $router->delete('/api/admin/plans/{id}', [$adminPlans, 'destroy'], [$authMiddleware]);

    $adminFamilies = new AdminFamilyController();
    $router->get('/api/admin/families', [$adminFamilies, 'index'], [$authMiddleware]);
    $router->put('/api/admin/families/{id}', [$adminFamilies, 'update'], [$authMiddleware]);

    // -- Selbst-Update (geschützt, nur TenantContext::isPlatformAdmin) --------
    $selfUpdate = new SelfUpdateController();
    $router->get('/api/self-update/status', [$selfUpdate, 'status'], [$authMiddleware]);
    $router->post('/api/self-update/run', [$selfUpdate, 'run'], [$authMiddleware]);

    // -- Healthcheck ------------------------------------------------------------
    $router->get('/api/health', static function (): void {
        Response::json(['status' => 'ok', 'time' => date('c')]);
    });

    return $router;
}

/**
 * Wie buildApiRouter(), aber nur einmal pro PHP-Prozess aufgebaut -
 * calendar.php ruft z.B. zwei Endpunkte nacheinander auf (/api/members,
 * /api/events), die Routentabelle muss dafür nicht zweimal neu
 * zusammengestellt werden (siehe LocalApiClient).
 */
function apiRouter(): Router
{
    static $router = null;
    if ($router === null) {
        $router = buildApiRouter();
    }
    return $router;
}
