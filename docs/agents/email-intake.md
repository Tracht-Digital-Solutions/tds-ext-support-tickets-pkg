# Email and contact intake

## Contact-form ingest: `POST /tickets/contact`

Server-to-server. Authenticated with the ingest token (`?token=` or `X-Ingest-Token`,
constant-time compare). Creates a `source='contact'` ticket with no customer, `from_*`
details and a validated payload (name ≥ 2, valid email, message ≥ 20).

## IMAP ingest

- `Service\ImapTicketIngest` uses webklex/php-imap over sockets (no ext-imap). It needs
  **ext-zip**, which the CI setup-php enables. webklex only loads on `connect()`.
- Triggers: `POST /tickets/ingest` (ingest token, external scheduler) and admin
  `POST /admin/tickets/ingest` ("Jetzt abrufen"). `GET /admin/tickets/imap-test` tests the
  connection.
- Dedupe on Message-ID.
- A mail is threaded onto an owned ticket by `#<id>` in the subject, or by
  In-Reply-To/References matching a stored Message-ID whose ticket carries the sender's
  `from_email`.
- Pure parsing helpers are unit-tested without a mailbox (`ImapParsingTest`).

## Ingest token

DB setting first, else `INGEST_TOKEN` from the environment. Neither set → ingest **503**.

## Configuration: `Service\ImapConfig`

DB first (`SettingsStore` namespace `support-tickets`, *Einstellungen → Support-Tickets →
E-Mail-Eingang (IMAP)*), environment as fallback. Same pattern as the core's SMTP settings.

- `GET /admin/tickets/imap` reports what the ingest **actually** uses, including
  `source: db|env|none`. The settings namespace alone would show an empty form on a host
  whose mailbox comes from `.env`, and the first "fix" would overwrite a working mailbox.
- The password and ingest token are secrets: masked on read, **blank on save keeps them**.
- **Connection fields follow the host, all or nothing.** A panel-configured mailbox never
  takes single fields from the env; otherwise a login failure has no explicable cause.
- **`ImapConfig` is not a container entry.** PHP-DI autowires unknown classes, and a value
  object with a private constructor resolves to "class is not instantiable" in every
  container without an explicit definition (every isolated test).
  `SupportTicketsModule::imapConfig()` resolves it per request, so a panel save takes
  effect on the next request.

## `ingest_mode`: what an unthreaded mail becomes

| Mode | Behaviour |
|---|---|
| `off` | No polling |
| `reply` | **Default.** Thread replies only |
| `allowlist` | Also open tickets for listed addresses and/or whole domains (matched on the domain boundary) |
| `all` | Open a ticket for any sender |

Opening tickets for anyone is not a safe default: an address that receives mail also
receives spam. A created ticket has `source='email'`, `from_name` / `from_email` from the
envelope, a subject via `cleanSubject()`, stored attachments and
`Notifier::onNewTicket()`.

## `ingest_match_company` (default on)

Binds the sender to a company when `company.email` matches **exactly**, which also makes the
ticket visible in that company's portal.

- That table belongs to `tds-ext-customers-pkg`, which isn't composed into the customer
  product. A missing table is normal, so `findCompanyIdByEmail()` catches and returns null.
- Full address only: a domain match would hand a freemail mailbox to whoever registered the
  domain first.

## Poll report

`poll()` returns `mode` and **`polled`** alongside the counters. An all-zero report from a
mailbox that was never contacted looks like an empty inbox; `polled` tells "nothing new"
from "not configured / switched off".

## Environment fallback

All of these only apply behind the panel settings:

| Variable | Effect when unset |
|---|---|
| `TICKET_ADMIN_EMAIL` | No admin notification |
| `TICKET_UPLOAD_DIR` | Uploads 503 |
| `INGEST_TOKEN` | Ingest 503 (unless stored) |
| `IMAP_HOST`, `IMAP_PORT`, `IMAP_USER`, `IMAP_PASSWORD` (alias `IMAP_PASS`), `IMAP_SECURITY` (ssl / tls / none), `IMAP_FOLDER` | Poll no-ops without host/user |
| `TICKET_INGEST_MODE`, `TICKET_INGEST_MATCH_COMPANY` | Defaults `reply` / on |

`IMAP_PASSWORD` is the documented name; `IMAP_PASS` stays as an alias for older hosts.
