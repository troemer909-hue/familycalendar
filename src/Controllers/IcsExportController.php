<?php

declare(strict_types=1);

namespace Familienkalender\Controllers;

use Familienkalender\Core\Database;
use Familienkalender\Core\IcsExporter;
use Familienkalender\Core\Request;
use Familienkalender\Core\Response;
use Familienkalender\Core\TenantContext;

/**
 * Outbound-ICS-Export: ein geheimes Token pro Familie
 * (families.ics_export_token), über das externe Kalender-Clients
 * (Google/Outlook/Apple Kalender) den Familienkalender read-only
 * abonnieren können - eine Art Gegenstück zum Import/Abo in
 * CalendarSourceController, nur in die andere Richtung.
 *
 * show()/generate()/revoke() sind normale, Bearer-Token-geschützte
 * API-Routen; feed() dagegen ist bewusst NICHT per $authMiddleware
 * geschützt (kein Login im üblichen Sinn) - Kalender-Apps können keine
 * Bearer-Header senden, die Sicherheit kommt stattdessen vom geheimen,
 * unratbaren Token selbst in der URL (siehe generate()).
 */
final class IcsExportController
{
    /**
     * GET /api/ics-export
     * Liefert das aktuelle Token (oder null, falls noch keiner erzeugt
     * wurde) - für jedes Familienmitglied lesbar, da es ohnehin nur
     * Zugriff auf das gibt, was man im Kalender bereits sieht.
     */
    public function show(Request $request): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT ics_export_token FROM families WHERE id = :family_id');
        $stmt->execute(['family_id' => TenantContext::familyId()]);

        Response::json(['token' => $stmt->fetchColumn() ?: null]);
    }

    /**
     * POST /api/ics-export/token
     * Erzeugt ein neues Token (oder ersetzt ein bestehendes - der alte
     * Link funktioniert danach nicht mehr). Nur Admin, analog zu anderen
     * familienweiten Einstellungen (Kalenderquellen etc.).
     */
    public function generate(Request $request): void
    {
        TenantContext::requireAdmin();

        $token = bin2hex(random_bytes(16));

        Database::connection()
            ->prepare('UPDATE families SET ics_export_token = :token WHERE id = :family_id')
            ->execute(['token' => $token, 'family_id' => TenantContext::familyId()]);

        Response::json(['token' => $token]);
    }

    /**
     * DELETE /api/ics-export/token
     * Deaktiviert den Export, ohne einen neuen zu erzeugen.
     */
    public function revoke(Request $request): void
    {
        TenantContext::requireAdmin();

        Database::connection()
            ->prepare('UPDATE families SET ics_export_token = NULL WHERE id = :family_id')
            ->execute(['family_id' => TenantContext::familyId()]);

        Response::json(['status' => 'revoked']);
    }

    /**
     * GET /api/ics/{token}.ics
     * Öffentlicher Feed, siehe Klassendoc - KEIN $authMiddleware.
     */
    public function feed(Request $request): void
    {
        $tokenParam = (string) $request->params['token'];
        // Der Router kennt keine zusammengesetzten Segmente wie
        // "{token}.ics" - die ".ics"-Endung landet deshalb als Teil des
        // Parameters und wird hier abgeschnitten.
        $token = str_ends_with($tokenParam, '.ics') ? substr($tokenParam, 0, -4) : $tokenParam;

        if ($token === '') {
            Response::error('Kalender-Abo nicht gefunden.', 404);
        }

        $db = Database::connection();
        $stmt = $db->prepare('SELECT id, name FROM families WHERE ics_export_token = :token');
        $stmt->execute(['token' => $token]);
        $family = $stmt->fetch();

        if (!$family) {
            Response::error('Kalender-Abo nicht gefunden oder deaktiviert.', 404);
        }

        // Rollierendes Zeitfenster statt "alle Termine aller Zeiten" -
        // wiederkehrende Termine sind bereits materialisiert (siehe RRule),
        // ein unbegrenzter Export würde mit der Zeit beliebig groß werden.
        $stmt = $db->prepare(
            'SELECT id, title, description, location, starts_at, ends_at, all_day
             FROM events
             WHERE family_id = :family_id
               AND starts_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
               AND starts_at <= DATE_ADD(NOW(), INTERVAL 2 YEAR)
             ORDER BY starts_at ASC'
        );
        $stmt->execute(['family_id' => $family['id']]);

        $events = array_map(static function (array $row): array {
            $row['all_day'] = (bool) $row['all_day'];
            return $row;
        }, $stmt->fetchAll());

        Response::ics(IcsExporter::build($family['name'], $events));
    }
}
