# Rotierende Aufgaben

## Umsetzungskonzept (Issue #58)

Die Rotation erweitert die bestehende automatische Aufgaben-Zuteilung in
`task_assignment_pools`. Sie erzeugt keine neuen Aufgabeninstanzen und
braucht keine separate Rotations- oder Verlaufstabelle. Jede Aufgabe rotiert
unabhängig anhand ihrer aktuellen `assigned_member_id`-Zuweisung.

## Verhalten

- Pool-Modus `fixed_order`; die Mitgliederreihenfolge steht in
  `task_assignment_pool_members.position`.
- Eine Aufgabe ohne bisherige Zuweisung startet beim ersten Listenplatz.
- Sonst beginnt die Suche beim nächsten Mitglied nach der bisherigen
  Zuständigkeit und läuft zyklisch weiter.
- Mitglieder, deren `max_tasks` für die aktuelle Ziehung erreicht ist, werden
  übersprungen. Sind alle Mitglieder ausgelastet, bleibt die Aufgabe
  unzugewiesen.
- Wird das zuletzt zuständige Mitglied aus dem Pool entfernt, beginnt die
  Aufgabe wieder beim ersten Listenplatz.
- Jede Aufgabe behält ihre eigene Rotationsposition. Ein Cron-Lauf wendet
  dieselbe vorhandene Ziehungslogik wie für die Zufalls- und Fair-Modi an.
- Die Reihenfolge lässt sich über Positionsnummern im Pool-Formular ändern;
  beim Speichern werden die Positionen auf `0..n-1` normalisiert.

## Datenbank

Die bestehenden Tabellen werden erweitert:

- `task_assignment_pools.mode` akzeptiert zusätzlich `fixed_order`.
- `task_assignment_pool_members.position INT NOT NULL DEFAULT 0` speichert
  die Reihenfolge.

Migration `024_task_assignment_fixed_order.sql` ergänzt beide Änderungen
idempotent. `database/schema.sql` enthält denselben endgültigen Zustand für
Neuinstallationen.

## Abgrenzung

Ein früherer Entwurf sah separate Rotationsregeln, neu erzeugte Aufgaben und
eine Verlaufsansicht vor. Issue #58 ersetzt diesen Entwurf durch die
Wiederverwendung der Aufgabenlisten-Pools. Pausieren einzelner Mitglieder,
Urlaubslogik und eine Rotationshistorie sind nicht Teil dieser Erweiterung.
