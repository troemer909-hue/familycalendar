<?php
require_once __DIR__ . '/../src/bootstrap.php';
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8" />
  <title>Datenschutz – Family-Kalender</title>
  <meta name="description" content="Datenschutzerklärung von Family-Kalender" />
  <link rel="stylesheet" href="styles.css" />
<?= pwaHeadTags() ?>
</head>
<body>
<div class="main-area">
  <div class="page-header"><?= brandLogo('', 'login.php') ?></div>
  <div class="form-card legal-page" style="max-width:720px;">
    <p class="eyebrow">Rechtliches</p>
    <h1>Datenschutzerklärung</h1>
    <p class="hint-text card-intro">Informationen zur Verarbeitung personenbezogener Daten bei Family-Kalender.</p>

    <section id="verantwortlicher">
      <h2>1. Verantwortlicher</h2>
      <p>
        Verantwortlicher im Sinne der Datenschutz-Grundverordnung (DSGVO) ist:<br /><br />
        Agentur Hoffmann Promotion GmbH<br />
        Schäferstraße 56<br />
        01067 Dresden<br />
        Deutschland<br /><br />
        Geschäftsführer: Thomas Roemer<br />
        Amtsgericht Dresden, HRB 32059<br />
        E-Mail: <a href="mailto:datenschutz@clubbify.de">datenschutz@clubbify.de</a>
      </p>
    </section>

    <section>
      <h2>2. Allgemeine Hinweise zur Datenverarbeitung</h2>
      <p>
        Der Schutz personenbezogener Daten ist wichtig. Daten werden nur
        verarbeitet, wenn dies für die technische Bereitstellung der App,
        die Bearbeitung von Anfragen oder die Durchführung des
        Nutzungsvertrags (Familienkalender-Konto) erforderlich ist. Dazu
        können insbesondere Name, E-Mail-Adresse, IP-Adresse sowie die von
        Ihnen und Ihren Familienmitgliedern eingetragenen Kalenderdaten
        gehören.
      </p>
    </section>

    <section>
      <h2>3. Hosting und Server-Logfiles</h2>
      <p>
        Beim Aufruf der App verarbeitet der Webserver technische Daten wie
        IP-Adresse, Zeitpunkt, aufgerufene Datei, Referrer-URL, Browser,
        Betriebssystem, Statuscode und übertragene Datenmenge. Die
        Verarbeitung dient der sicheren und stabilen Bereitstellung, der
        Fehleranalyse und dem Schutz vor missbräuchlichen Zugriffen.
        Rechtsgrundlage ist Art. 6 Abs. 1 lit. f DSGVO.
      </p>
    </section>

    <section>
      <h2>4. SSL-/TLS-Verschlüsselung</h2>
      <p>
        Die App verwendet SSL-/TLS-Verschlüsselung. So werden übermittelte
        Daten während des Transports vor dem Zugriff Dritter geschützt.
      </p>
    </section>

    <section id="konto">
      <h2>5. Registrierung und Nutzerkonto</h2>
      <p>
        Bei der Registrierung einer Familie werden Familienname, Ihr Name,
        Ihre E-Mail-Adresse und ein von Ihnen gewähltes Passwort
        verarbeitet. Das Passwort wird ausschließlich in gehashter Form
        gespeichert, niemals im Klartext. Rechtsgrundlage ist Art. 6
        Abs. 1 lit. b DSGVO (Erfüllung des Nutzungsvertrags).
      </p>
    </section>

    <section>
      <h2>6. Kalenderdaten und Familienmitglieder</h2>
      <p>
        Von Ihnen angelegte Termine, Erinnerungen und Profile von
        Familienmitgliedern werden verarbeitet, um die Kernfunktion der
        App (gemeinsamer Familienkalender) bereitzustellen. Laden Sie ein
        Familienmitglied per E-Mail ein, wird dessen E-Mail-Adresse zur
        Zustellung der Einladung verarbeitet. Rechtsgrundlage ist Art. 6
        Abs. 1 lit. b DSGVO.
      </p>
    </section>

    <section>
      <h2>7. Cookies und Sitzungsverwaltung</h2>
      <p>
        Zur Anmeldung wird ein technisch notwendiges Session-Cookie
        gesetzt, das Sie nach dem Login als eingeloggt erkennt. Dieses
        Cookie ist für den Betrieb der App zwingend erforderlich und kann
        nicht deaktiviert werden, ohne die Nutzung der App unmöglich zu
        machen. Rechtsgrundlage ist Art. 6 Abs. 1 lit. f DSGVO. Darüber
        hinaus werden keine Analyse- oder Marketing-Cookies eingesetzt.
      </p>
    </section>

    <section>
      <h2>8. Push-Benachrichtigungen (optional)</h2>
      <p>
        Aktivieren Sie Push-Benachrichtigungen für Termin-Erinnerungen,
        wird dafür ein technischer Abonnement-Schlüssel Ihres Browsers
        gespeichert, über den Benachrichtigungen zugestellt werden können.
        Diese Funktion ist freiwillig und kann jederzeit in den
        Benachrichtigungseinstellungen wieder deaktiviert werden.
        Rechtsgrundlage ist Ihre Einwilligung, Art. 6 Abs. 1 lit. a DSGVO.
      </p>
    </section>

    <section>
      <h2>9. Empfänger und Auftragsverarbeitung</h2>
      <p>
        Innerhalb des Unternehmens erhalten nur die Stellen Zugriff, die
        Daten zur Erfüllung ihrer Aufgaben benötigen. Externe
        Dienstleister, etwa für Hosting oder E-Mail-Versand, können im
        Rahmen einer Auftragsverarbeitung eingesetzt werden. Eine
        Weitergabe an Dritte darüber hinaus erfolgt nicht, außer wenn
        dies gesetzlich vorgeschrieben ist.
      </p>
    </section>

    <section>
      <h2>10. Speicherdauer</h2>
      <p>
        Konto- und Kalenderdaten werden gespeichert, solange das
        Nutzerkonto besteht. Auf Wunsch werden Konto und zugehörige Daten
        gelöscht; eine formlose Nachricht an
        <a href="mailto:datenschutz@clubbify.de">datenschutz@clubbify.de</a>
        genügt.
      </p>
    </section>

    <section id="rechte">
      <h2>11. Ihre Rechte</h2>
      <p>
        Sie haben im gesetzlichen Rahmen insbesondere das Recht auf
        Auskunft, Berichtigung, Löschung, Einschränkung der Verarbeitung,
        Datenübertragbarkeit, Widerspruch sowie den Widerruf einer
        Einwilligung. Für die Ausübung Ihrer Rechte genügt eine Nachricht
        an <a href="mailto:datenschutz@clubbify.de">datenschutz@clubbify.de</a>.
      </p>
    </section>

    <section>
      <h2>12. Beschwerderecht</h2>
      <p>
        Sie können sich bei einer Datenschutzaufsichtsbehörde beschweren.
        Für in Sachsen ansässige nichtöffentliche Stellen ist grundsätzlich
        zuständig: Sächsische Datenschutz- und Transparenzbeauftragte,
        Maternistraße 17, 01067 Dresden, Telefon +49 351 85471-101,
        <a href="mailto:post@sdtb.sachsen.de">post@sdtb.sachsen.de</a>.
      </p>
    </section>

    <section>
      <h2>13. Datensicherheit und Änderungen</h2>
      <p>
        Es werden angemessene technische und organisatorische Maßnahmen
        eingesetzt, um personenbezogene Daten vor Verlust, Manipulation
        und unbefugtem Zugriff zu schützen (u.a. Passwort-Hashing,
        verschlüsselte Übertragung). Diese Erklärung wird angepasst, wenn
        sich rechtliche Anforderungen, die technische Gestaltung oder
        eingesetzte Dienste ändern.
      </p>
      <p><strong>Stand: Oktober 2026</strong></p>
    </section>

    <p style="margin-top:24px;">
      <a href="impressum.php">Impressum</a>
      <span style="margin:0 8px;">·</span>
      <a href="login.php">Zur Anmeldung</a>
    </p>
  </div>
</div>
</body>
</html>
