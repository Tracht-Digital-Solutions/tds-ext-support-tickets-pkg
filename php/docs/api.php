<?php
/**
 * API documentation for this module's routes — consumed through `ApiDocSource`
 * and rendered in the admin frontend's API reference (`GET /wiki.json`).
 *
 * `pattern` must match the Slim pattern in `register()` VERBATIM, inline regex
 * included: it is the join key for the route introspection.
 * `php/tests/SupportTicketsApiDocsTest.php` fails the build if the documented
 * set and the registered set drift apart in either direction.
 */

declare(strict_types=1);

$ticketId = ['in' => 'path', 'name' => 'id', 'type' => 'int', 'required' => true, 'description' => 'Id des Tickets.'];
$attachmentId = ['in' => 'path', 'name' => 'aid', 'type' => 'int', 'required' => true, 'description' => 'Id des Anhangs.'];
$statusId = ['in' => 'path', 'name' => 'id', 'type' => 'int', 'required' => true, 'description' => 'Id des Status.'];

$attachmentBody = ['in' => 'body', 'name' => 'file', 'type' => 'multipart', 'required' => true, 'description' => 'Die Datei, höchstens 25 MB, Mime-Typ von der Positivliste.'];

$statusFields = [
    ['in' => 'body', 'name' => 'name', 'type' => 'string', 'required' => true, 'description' => 'Anzeigename, darf nicht leer sein.'],
    ['in' => 'body', 'name' => 'color', 'type' => 'string', 'description' => 'Chip-Farbe aus `TicketRepository::STATUS_COLORS`; Vorgabe `neutral`.'],
    ['in' => 'body', 'name' => 'sort_order', 'type' => 'int', 'description' => 'Position in der Liste.'],
    ['in' => 'body', 'name' => 'visible_to_customer', 'type' => 'bool', 'description' => 'Ob der Kunde diesen Status sieht; Vorgabe true.'],
    ['in' => 'body', 'name' => 'is_terminal', 'type' => 'bool', 'description' => 'Abschließender Status (Ticket gilt als erledigt).'],
    ['in' => 'body', 'name' => 'is_default', 'type' => 'bool', 'description' => 'Status für neue Tickets.'],
];

return [
    [
        'method' => 'GET',
        'pattern' => '/tickets/summary',
        'tag' => 'Portal',
        'summary' => 'Offene Tickets der aktiven Firma',
        'description' => 'Die `dataEndpoint`-Route des Dashboard-Widgets.',
        'permission' => 'tickets:read',
        'responses' => [
            ['status' => 200, 'description' => 'Zählwerte für das Widget.'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Kein `tickets:read`.'],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/tickets',
        'tag' => 'Portal',
        'summary' => 'Tickets der aktiven Firma',
        'description' => 'Die Kundensicht: eingeschränkt auf die aktive Firma '
            . '(`X-Act-As-Customer`) und ohne interne Kommentare.',
        'permission' => 'tickets:read',
        'responses' => [
            ['status' => 200, 'description' => '`{tickets: [...]}`'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Kein `tickets:read`.'],
        ],
    ],
    [
        'method' => 'POST',
        'pattern' => '/tickets',
        'tag' => 'Portal',
        'summary' => 'Ticket anlegen',
        'description' => 'Der Anfangsstatus kommt aus dem Status-Register '
            . '(`is_default`), nicht aus der Anfrage. Unbekannte Werte für `type` und '
            . '`priority` fallen auf die Vorgabe zurück, statt die Anfrage abzulehnen. '
            . 'Löst die Benachrichtigung an die Agenten aus.',
        'permission' => 'tickets:write',
        'params' => [
            ['in' => 'body', 'name' => 'subject', 'type' => 'string', 'required' => true, 'description' => 'Betreff, darf nicht leer sein.'],
            ['in' => 'body', 'name' => 'description', 'type' => 'string', 'required' => true, 'description' => 'Beschreibung, darf nicht leer sein.'],
            ['in' => 'body', 'name' => 'type', 'type' => 'question|bug|feature|other', 'description' => 'Vorgabe `question`.'],
            ['in' => 'body', 'name' => 'priority', 'type' => 'low|normal|high|urgent', 'description' => 'Vorgabe `normal`.'],
        ],
        'responses' => [
            ['status' => 201, 'description' => '`{id}` des Tickets.'],
            ['status' => 400, 'description' => 'Keine aktive Firma — ein Ticket braucht einen Absender.'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Kein `tickets:write`.'],
            ['status' => 422, 'description' => '`subject` oder `description` fehlt.'],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/tickets/{id:[0-9]+}',
        'tag' => 'Portal',
        'summary' => 'Ein eigenes Ticket samt Verlauf lesen',
        'description' => 'Nur Tickets der aktiven Firma; ein fremdes ergibt 404, nicht 403. '
            . 'Interne Kommentare sind hier nie enthalten.',
        'permission' => 'tickets:read',
        'params' => [$ticketId],
        'responses' => [
            ['status' => 200, 'description' => 'Das Ticket mit Kommentaren und Anhängen.'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Kein `tickets:read`.'],
            ['status' => 404, 'description' => 'Unbekannt oder nicht in der aktiven Firma.'],
        ],
    ],
    [
        'method' => 'POST',
        'pattern' => '/tickets/{id:[0-9]+}/attachments',
        'tag' => 'Portal',
        'summary' => 'Anhang an ein eigenes Ticket hängen (multipart)',
        'permission' => 'tickets:write',
        'params' => [$ticketId, $attachmentBody],
        'responses' => [
            ['status' => 201, 'description' => 'Die Metadaten des Anhangs.'],
            ['status' => 400, 'description' => 'Kein gültiges Feld `file` im Upload.'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Kein `tickets:write`.'],
            ['status' => 404, 'description' => 'Unbekannt oder nicht in der aktiven Firma.'],
            ['status' => 413, 'description' => 'Datei größer als 25 MB.'],
            ['status' => 415, 'description' => 'Mime-Typ nicht erlaubt — der abgelehnte Typ steht in der Antwort.'],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/tickets/{id:[0-9]+}/attachments/{aid:[0-9]+}',
        'tag' => 'Portal',
        'summary' => 'Anhang eines eigenen Tickets herunterladen',
        'permission' => 'tickets:read',
        'params' => [$ticketId, $attachmentId],
        'responses' => [
            ['status' => 200, 'description' => 'Die Datei als Byte-Stream.'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Kein `tickets:read`.'],
            ['status' => 404, 'description' => 'Unbekanntes Ticket, fremde Firma, oder kein solcher Anhang.'],
        ],
    ],
    [
        'method' => 'POST',
        'pattern' => '/tickets/{id:[0-9]+}/comments',
        'tag' => 'Portal',
        'summary' => 'Auf ein eigenes Ticket antworten',
        'description' => 'Eine Kundenantwort ist immer öffentlich — `is_internal` gibt es '
            . 'nur auf der Verwaltungsseite.',
        'permission' => 'tickets:write',
        'params' => [
            $ticketId,
            ['in' => 'body', 'name' => 'body', 'type' => 'string', 'required' => true, 'description' => 'Text der Antwort, darf nicht leer sein.'],
        ],
        'responses' => [
            ['status' => 201, 'description' => 'Der angelegte Kommentar.'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Kein `tickets:write`.'],
            ['status' => 404, 'description' => 'Unbekannt oder nicht in der aktiven Firma.'],
            ['status' => 422, 'description' => '`body` fehlt.'],
        ],
    ],
    [
        'method' => 'POST',
        'pattern' => '/tickets/contact',
        'tag' => 'Ingest',
        'summary' => 'Kontaktformular-Einsendung als Ticket aufnehmen',
        'description' => 'Server-zu-Server, **kein Browser-Login**: das Kontaktformular hat '
            . 'keine Sitzung, also gilt ein gemeinsames Geheimnis (`INGEST_TOKEN`), '
            . 'zeitkonstant verglichen. Solche Tickets haben `customer_id = NULL` und '
            . 'tragen die Absenderdaten in den `from_*`-Feldern — daher muss '
            . '`ticket.customer_id` nullable sein.',
        'auth' => 'token',
        'params' => [
            ['in' => 'query', 'name' => 'token', 'type' => 'string', 'description' => 'Das Ingest-Token. Alternativ als Header `X-Ingest-Token`.'],
            ['in' => 'body', 'name' => 'name', 'type' => 'string', 'required' => true, 'description' => 'Mindestens 2 Zeichen.'],
            ['in' => 'body', 'name' => 'email', 'type' => 'string', 'required' => true, 'description' => 'Adresse des Absenders.'],
            ['in' => 'body', 'name' => 'message', 'type' => 'string', 'required' => true, 'description' => 'Mindestens 20 Zeichen.'],
            ['in' => 'body', 'name' => 'company', 'type' => 'string', 'description' => 'Optional.'],
        ],
        'responses' => [
            ['status' => 201, 'description' => '`{id}` des angelegten Tickets.'],
            ['status' => 401, 'description' => 'Token fehlt oder stimmt nicht.'],
            ['status' => 422, 'description' => 'Name, E-Mail oder Nachricht genügen den Mindestanforderungen nicht.'],
            ['status' => 503, 'description' => '`INGEST_TOKEN` nicht gesetzt — die Route ist damit abgeschaltet.'],
        ],
    ],
    [
        'method' => 'POST',
        'pattern' => '/tickets/ingest',
        'tag' => 'Ingest',
        'summary' => 'IMAP-Postfach abrufen und E-Mails zu Tickets machen',
        'description' => 'Von außen angestoßen, weil der Produktionshost weder Cron noch '
            . '`proc_open` erlaubt — es gibt also keinen Hintergrundprozess, der das '
            . 'selbst täte. Gelesen wird über Sockets (`webklex/php-imap`), nicht über '
            . '`ext-imap`. Antworten auf eine bestehende Ticket-Mail werden an das '
            . 'Ticket gehängt; alles andere legt ein neues Ticket an, sofern die '
            . 'Annahme-Regel (Einstellungen → E-Mail-Eingang) den Absender zulässt. '
            . 'Zugangsdaten und Regel stehen in den Panel-Einstellungen, ersatzweise '
            . 'in `IMAP_*` der `.env`.',
        'auth' => 'token',
        'params' => [
            ['in' => 'query', 'name' => 'token', 'type' => 'string', 'description' => 'Das Ingest-Token. Alternativ als Header `X-Ingest-Token`.'],
        ],
        'responses' => [
            ['status' => 200, 'description' => 'Bericht über den Abruf: `processed`, `created`, `appended`, `skipped`, die geltende Regel (`mode`) und `polled` — letzteres unterscheidet „nichts Neues" von „gar nicht erst verbunden".'],
            ['status' => 401, 'description' => 'Token fehlt oder stimmt nicht.'],
            ['status' => 503, 'description' => 'Kein Ingest-Token hinterlegt — die Route ist damit abgeschaltet.'],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/admin/tickets',
        'tag' => 'Verwaltung',
        'summary' => 'Alle Tickets über alle Firmen',
        'auth' => 'admin',
        'responses' => [
            ['status' => 200, 'description' => '`{tickets: [...]}`'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Angemeldet, aber kein Admin.'],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/admin/tickets/{id:[0-9]+}',
        'tag' => 'Verwaltung',
        'summary' => 'Ein Ticket samt internen Kommentaren lesen',
        'auth' => 'admin',
        'params' => [$ticketId],
        'responses' => [
            ['status' => 200, 'description' => 'Das Ticket mit allen Kommentaren, auch den internen.'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Angemeldet, aber kein Admin.'],
            ['status' => 404, 'description' => 'Unbekannte Id.'],
        ],
    ],
    [
        'method' => 'POST',
        'pattern' => '/admin/tickets/{id:[0-9]+}/attachments',
        'tag' => 'Verwaltung',
        'summary' => 'Anhang an ein beliebiges Ticket hängen (multipart)',
        'auth' => 'admin',
        'params' => [$ticketId, $attachmentBody],
        'responses' => [
            ['status' => 201, 'description' => 'Die Metadaten des Anhangs.'],
            ['status' => 400, 'description' => 'Kein gültiges Feld `file` im Upload.'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Angemeldet, aber kein Admin.'],
            ['status' => 404, 'description' => 'Unbekannte Id.'],
            ['status' => 413, 'description' => 'Datei größer als 25 MB.'],
            ['status' => 415, 'description' => 'Mime-Typ nicht erlaubt.'],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/admin/tickets/{id:[0-9]+}/attachments/{aid:[0-9]+}',
        'tag' => 'Verwaltung',
        'summary' => 'Anhang eines beliebigen Tickets herunterladen',
        'auth' => 'admin',
        'params' => [$ticketId, $attachmentId],
        'responses' => [
            ['status' => 200, 'description' => 'Die Datei als Byte-Stream.'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Angemeldet, aber kein Admin.'],
            ['status' => 404, 'description' => 'Kein solcher Anhang.'],
        ],
    ],
    [
        'method' => 'POST',
        'pattern' => '/admin/tickets/{id:[0-9]+}/comments',
        'tag' => 'Verwaltung',
        'summary' => 'Auf ein Ticket antworten oder eine interne Notiz hinterlassen',
        'description' => '`is_internal` ist der wichtige Schalter: eine interne Notiz ist '
            . 'für den Kunden unsichtbar **und löst keine Benachrichtigung aus**. Eine '
            . 'öffentliche Antwort tut beides.',
        'auth' => 'admin',
        'params' => [
            $ticketId,
            ['in' => 'body', 'name' => 'body', 'type' => 'string', 'required' => true, 'description' => 'Text, darf nicht leer sein.'],
            ['in' => 'body', 'name' => 'is_internal', 'type' => 'bool', 'description' => 'true = interne Notiz, unsichtbar für den Kunden, ohne Benachrichtigung.'],
        ],
        'responses' => [
            ['status' => 201, 'description' => 'Der angelegte Kommentar.'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Angemeldet, aber kein Admin.'],
            ['status' => 404, 'description' => 'Unbekannte Id.'],
            ['status' => 422, 'description' => '`body` fehlt.'],
        ],
    ],
    [
        'method' => 'PATCH',
        'pattern' => '/admin/tickets/{id:[0-9]+}',
        'tag' => 'Verwaltung',
        'summary' => 'Ticket ändern (Status, Zuweisung, Priorität, Rückfrage)',
        'description' => 'Echtes Teil-Update: nur mitgesendete Felder werden geschrieben '
            . '(`array_key_exists`, ein bewusstes `null` löst also eine Zuweisung). '
            . 'Ein **Statuswechsel benachrichtigt den Kunden**, sofern der Status für '
            . 'ihn sichtbar ist. Unbekannte Werte für `priority`/`type` fallen auf die '
            . 'Vorgabe zurück.',
        'auth' => 'admin',
        'params' => [
            $ticketId,
            ['in' => 'body', 'name' => 'status_id', 'type' => 'int', 'description' => 'Neuer Status aus dem Register.'],
            ['in' => 'body', 'name' => 'assignee_id', 'type' => 'int', 'description' => 'Zuständiger Agent; `null` hebt die Zuweisung auf.'],
            ['in' => 'body', 'name' => 'priority', 'type' => 'low|normal|high|urgent', 'description' => 'Unbekanntes ⇒ `normal`.'],
            ['in' => 'body', 'name' => 'type', 'type' => 'question|bug|feature|other|contact', 'description' => 'Unbekanntes ⇒ `question`.'],
            ['in' => 'body', 'name' => 'customer_action_required', 'type' => 'bool', 'description' => 'Markiert das Ticket als „wartet auf den Kunden".'],
            ['in' => 'body', 'name' => 'customer_action_note', 'type' => 'string', 'description' => 'Wonach genau gefragt wird; `null` löscht.'],
        ],
        'responses' => [
            ['status' => 200, 'description' => 'Das geänderte Ticket.'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Angemeldet, aber kein Admin.'],
            ['status' => 404, 'description' => 'Unbekannte Id.'],
        ],
    ],
    [
        'method' => 'POST',
        'pattern' => '/admin/tickets/ingest',
        'tag' => 'Verwaltung',
        'summary' => 'IMAP-Abruf von Hand auslösen („Jetzt abrufen")',
        'description' => 'Dieselbe Arbeit wie `POST /tickets/ingest`, aber über die '
            . 'Admin-Sitzung statt über das Ingest-Token.',
        'auth' => 'admin',
        'responses' => [
            ['status' => 200, 'description' => 'Bericht über den Abruf.'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Angemeldet, aber kein Admin.'],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/admin/tickets/imap-test',
        'tag' => 'Verwaltung',
        'summary' => 'IMAP-Verbindung prüfen',
        'description' => 'Diagnose vor dem ersten Abruf. Eine abgelehnte Anmeldung ist ein '
            . 'Befund, kein Fehler der Anfrage — die Antwort trägt ihn als Ergebnis. '
            . 'Geprüft wird die **gespeicherte** Konfiguration, nicht das noch nicht '
            . 'gespeicherte Formular.',
        'auth' => 'admin',
        'responses' => [
            ['status' => 200, 'description' => 'Das Prüfergebnis samt Fehlermeldung, falls die Verbindung scheitert.'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Angemeldet, aber kein Admin.'],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/admin/tickets/imap',
        'tag' => 'Verwaltung',
        'summary' => 'Wirksame Postfach-Konfiguration',
        'description' => 'Was der Abruf tatsächlich verwendet — inklusive `source` '
            . '(`db` = Panel-Einstellungen, `env` = `IMAP_*` der `.env` des Hosts, '
            . '`none` = nicht eingerichtet) und der geltenden Annahme-Regel. Nötig, '
            . 'weil `GET /admin/settings/support-tickets` nur zeigt, was gespeichert '
            . 'ist: auf einem Host, der sein Postfach aus der `.env` bezieht, wäre das '
            . 'ein leeres Formular über einer laufenden Anbindung. Enthält keine '
            . 'Geheimnisse, nur ob Passwort und Token hinterlegt sind.',
        'auth' => 'admin',
        'responses' => [
            ['status' => 200, 'description' => 'Host, Port, Verschlüsselung, Ordner, Benutzer, Regel, Allowlist und die Herkunft der Konfiguration.'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Angemeldet, aber kein Admin.'],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/admin/ticket-settings',
        'tag' => 'Einstellungen',
        'summary' => 'Benachrichtigungs-Schalter lesen',
        'description' => 'Je Ereignis (neues Ticket, Antwort, Statuswechsel) getrennt '
            . 'schaltbar.',
        'auth' => 'admin',
        'responses' => [
            ['status' => 200, 'description' => '`{settings: {...}}`'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Angemeldet, aber kein Admin.'],
        ],
    ],
    [
        'method' => 'PUT',
        'pattern' => '/admin/ticket-settings',
        'tag' => 'Einstellungen',
        'summary' => 'Benachrichtigungs-Schalter speichern',
        'description' => 'Antwortet mit dem **gespeicherten** Stand, nicht mit dem '
            . 'gesendeten — die Oberfläche zeigt damit, was wirklich gilt.',
        'auth' => 'admin',
        'params' => [
            ['in' => 'body', 'name' => 'settings', 'type' => 'object', 'description' => 'Die zu setzenden Schalter; unbekannte Schlüssel werden verworfen.'],
        ],
        'responses' => [
            ['status' => 200, 'description' => '`{settings: {...}}` — der gespeicherte Stand.'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Angemeldet, aber kein Admin.'],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/admin/ticket-statuses',
        'tag' => 'Status-Register',
        'summary' => 'Konfigurierte Ticket-Status auflisten',
        'description' => 'Die Status sind Daten, keine Aufzählung im Code — sie lassen sich '
            . 'anlegen, umbenennen und einfärben.',
        'auth' => 'admin',
        'responses' => [
            ['status' => 200, 'description' => '`{statuses: [{id, name, color, sort_order, visible_to_customer, is_terminal, is_default}]}`'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Angemeldet, aber kein Admin.'],
        ],
    ],
    [
        'method' => 'POST',
        'pattern' => '/admin/ticket-statuses',
        'tag' => 'Status-Register',
        'summary' => 'Status anlegen',
        'auth' => 'admin',
        'params' => $statusFields,
        'responses' => [
            ['status' => 201, 'description' => '`{id}`'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Angemeldet, aber kein Admin.'],
            ['status' => 422, 'description' => '`name` fehlt oder `color` ist keine bekannte Chip-Farbe.'],
        ],
    ],
    [
        'method' => 'PATCH',
        'pattern' => '/admin/ticket-statuses/{id:[0-9]+}',
        'tag' => 'Status-Register',
        'summary' => 'Status ändern',
        'auth' => 'admin',
        'params' => array_merge([$statusId], $statusFields),
        'responses' => [
            ['status' => 200, 'description' => '`{ok: true}`'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Angemeldet, aber kein Admin.'],
            ['status' => 404, 'description' => 'Unbekannte Id.'],
            ['status' => 422, 'description' => '`name` fehlt oder `color` ist keine bekannte Chip-Farbe.'],
        ],
    ],
    [
        'method' => 'DELETE',
        'pattern' => '/admin/ticket-statuses/{id:[0-9]+}',
        'tag' => 'Status-Register',
        'summary' => 'Status löschen',
        'description' => 'Zwei Sperren, beide 409: ein Status, den Tickets tragen, kann '
            . 'nicht weg (die Tickets hätten sonst keinen), und der **letzte** Status '
            . 'kann nicht weg (neue Tickets hätten sonst keinen Anfangszustand).',
        'auth' => 'admin',
        'params' => [$statusId],
        'responses' => [
            ['status' => 200, 'description' => '`{ok: true}`'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Angemeldet, aber kein Admin.'],
            ['status' => 404, 'description' => 'Unbekannte Id.'],
            ['status' => 409, 'description' => 'Status wird von Tickets verwendet, oder es wäre der letzte.'],
        ],
    ],
];
