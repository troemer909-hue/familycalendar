#!/bin/sh
# Ersetzt den echten MTA für Integration-Tests (siehe ApiServer.php, das
# den PHP-Built-in-Server mit "-d sendmail_path=..." auf dieses Skript
# zeigen lässt) - schreibt die komplette Mail (Header + Body) einfach in
# eine Datei, damit Tests z.B. Reset-/Einladungs-Codes daraus auslesen
# können, ohne einen echten Mailserver zu brauchen.
cat >> "${FAKE_MAIL_CAPTURE_FILE:-/tmp/familienkalender_test_mail.eml}"
printf '\n---END-OF-MAIL---\n' >> "${FAKE_MAIL_CAPTURE_FILE:-/tmp/familienkalender_test_mail.eml}"
