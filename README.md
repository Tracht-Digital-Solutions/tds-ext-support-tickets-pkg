# tds-ext-support-tickets-pkg

The **support-ticket system** as a frontend extension, ported from
`tds-customer-api`. Customers open + follow tickets in the portal; admins triage
them. Built on the frontend platform's core services:

- **Auth** — RBAC (`tickets:read`/`tickets:write`) + company scoping from the
  core `UserContext` (the extension never verifies tokens).
- **Email** — notifications through the **core `Mailer`** (no per-extension SMTP).
- **Data** — the extension's own `ticket` / `ticket_status` / `ticket_comment`
  tables via the core's shared `PDO`.

## Surface (checkpoint-1)

- **Customer:** `GET /tickets`, `POST /tickets`, `GET /tickets/{id}`,
  `POST /tickets/{id}/comments`, `GET /tickets/summary` (the "Offene Tickets"
  widget). Scoped to the JWT's active company; statuses flagged
  `visible_to_customer=0` show a neutral fallback label.
- **Admin:** `GET /admin/tickets`, `GET /admin/tickets/{id}` (incl. internal
  notes), `POST /admin/tickets/{id}/comments` (with `is_internal`),
  `PATCH /admin/tickets/{id}` (status/assignee/priority/type/customer-action),
  `GET /admin/ticket-statuses`.
- **Frontend:** nav "Tickets" → `/tickets`, the ticket list island, the open-count
  dashboard widget, DE/EN i18n.

## E-Mail-Eingang (IMAP)

Incoming mail becomes tickets. Configure it in the admin frontend under
**Einstellungen → Support-Tickets → E-Mail-Eingang (IMAP)** — server, port,
encryption, folder, user, password — and test it there ("Verbindung testen").
Settings are stored in the core's runtime settings store (secrets AES-256-GCM
encrypted); the host's `IMAP_*` env vars remain a fallback, and
`GET /admin/tickets/imap` reports which of the two is actually in use.

**Die Annahme-Regel** (`ingest_mode`) decides what a mail that belongs to no
existing ticket becomes:

| Regel | Wirkung |
|---|---|
| `off` | Das Postfach wird nicht abgerufen. |
| `reply` *(Standard)* | Antworten landen am passenden Ticket. Alles andere wird verworfen. |
| `allowlist` | Zusätzlich: Mails erlaubter Adressen/Domains öffnen ein neues Ticket. |
| `all` | Jede unbekannte Mail öffnet ein Ticket — auch Spam. |

Replies always thread (Message-ID dedupe, `#<id>` subject marker or
In-Reply-To/References), regardless of the rule. With **"Absender einer bekannten
Firma zuordnen"** on, a sender whose address matches a company in the directory
gets their ticket bound to that company, which also makes it visible in that
company's portal.

Polling happens on demand: **"Jetzt abrufen"** in the settings section, or
`POST /tickets/ingest?token=…` from an external scheduler (the production host
has no cron and no `proc_open`). The token is set in the same section.

## Still to port (later checkpoints)

Status-registry CRUD + colour tones editor, attachments, the full board UI
(detail view + comment thread + new-ticket form), and richer notifications.

## Develop

```bash
npm install        # pulls tds-frontend-contract from GitHub Packages (needs NPM_TOKEN)
npm run build && npm run type-check
composer install   # resolves tds-frontend-contract from its public VCS repo
composer test      # phpunit — route/RBAC coverage; DB-backed tests skip without TDS_TEST_DB_DSN
```

## Enable it

Host `astro.config.mjs`: add the manifest to `frontendHost({ extensions: [...] })`.
Base API: add `new SupportTicketsModule()` to `Modules::enabled()`. Set
`TICKET_ADMIN_EMAIL` (+ the core `MAIL_DSN`) for admin notifications.
