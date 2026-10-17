<?php
require_once __DIR__ . '/../src/bootstrap.php';
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8" />
  <title>Impressum – Family-Kalender</title>
  <meta name="description" content="Impressum von Family-Kalender" />
  <link rel="stylesheet" href="styles.css" />
<?= pwaHeadTags() ?>
</head>
<body>
<div class="main-area">
  <div class="page-header"><?= brandLogo('', 'login.php') ?></div>
  <div class="form-card legal-page" style="max-width:720px;">
    <p class="eyebrow">Rechtliches</p>
    <h1>Impressum</h1>
    <p class="hint-text card-intro">Anbieterkennzeichnung für Family-Kalender.</p>

    <section>
      <h2>Anbieter</h2>
      <p>
        Agentur Hoffmann Promotion GmbH<br />
        Schäferstraße 56<br />
        01067 Dresden<br />
        Deutschland
      </p>
    </section>

    <section>
      <h2>Vertretungsberechtigte Person</h2>
      <p>Geschäftsführer: Thomas Roemer</p>
    </section>

    <section>
      <h2>Kontakt</h2>
      <p>
        E-Mail: <a href="mailto:datenschutz@clubbify.de">datenschutz@clubbify.de</a><br />
        Website: <a href="https://www.eastcon.eu/" target="_blank" rel="noopener noreferrer">www.eastcon.eu</a>
      </p>
    </section>

    <section>
      <h2>Registereintrag</h2>
      <p>
        Registergericht: Amtsgericht Dresden<br />
        Handelsregisternummer: HRB 32059
      </p>
    </section>

    <section>
      <h2>Steuerangaben</h2>
      <p>Steuernummer: 302/105/13068</p>
    </section>

    <section>
      <h2>Verantwortung für Inhalte</h2>
      <p>
        Für die Inhalte dieser Website gelten die allgemeinen gesetzlichen
        Vorschriften. Für Inhalte externer Seiten, auf die verlinkt wird,
        sind deren jeweilige Betreiber verantwortlich.
      </p>
    </section>

    <section>
      <h2>Urheberrecht</h2>
      <p>
        Die Inhalte dieser Website sind urheberrechtlich geschützt. Eine
        Nutzung außerhalb der gesetzlichen Grenzen erfordert die Zustimmung
        der jeweiligen Rechteinhaber.
      </p>
    </section>

    <p style="margin-top:24px;">
      <a href="datenschutz.php">Datenschutzerklärung</a>
      <span style="margin:0 8px;">·</span>
      <a href="login.php">Zur Anmeldung</a>
    </p>
  </div>
</div>
</body>
</html>
