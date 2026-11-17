<?php

declare(strict_types=1);

namespace Familienkalender\Controllers;

use Familienkalender\Core\Database;
use Familienkalender\Core\TenantContext;

/** Handles the read-only points intent after Alexa account linking. */
final class AlexaPointsIntentHandler
{
    /** @return array<string,mixed> */
    public function handle(): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT COALESCE(SUM(mpl.points), 0) AS balance
               FROM family_members fm
               LEFT JOIN member_points_ledger mpl ON mpl.family_member_id = fm.id
              WHERE fm.id = :member_id AND fm.family_id = :family_id'
        );
        $stmt->execute([
            'member_id' => TenantContext::familyMemberId(),
            'family_id' => TenantContext::familyId(),
        ]);
        $balance = (int) $stmt->fetchColumn();

        return [
            'version' => '1.0',
            'response' => [
                'outputSpeech' => ['type' => 'PlainText', 'text' => 'Du hast aktuell ' . $balance . ' Punkte.'],
                'shouldEndSession' => true,
            ],
        ];
    }
}
