# Architecture

## Auth

- Auth is the core `UserContext`. Customer routes require `tickets:read` / `tickets:write`
  (admins bypass) and are scoped by `activeCompanyId()`. `/admin/*` routes require
  `isAdmin`. The module never verifies a token itself.
- Routes are closures that resolve `UserContext`, `TicketRepository` and `Notifier` from the
  container **at request time**; the core AuthMiddleware rebinds `UserContext` per request.
  Don't capture it at register time.
- **`author_type` is derived from the principal** (`owner` if admin, else `customer`), never
  trusted from the client.
- **`is_internal` comments are never returned to a customer** (the customer `comments()`
  query filters them).

## Routes

| Area | Routes |
|---|---|
| Portal | `GET /tickets/summary`, `GET` / `POST /tickets`, `GET /tickets/{id:[0-9]+}`, `POST /tickets/{id:[0-9]+}/comments`, `POST /tickets/{id:[0-9]+}/attachments`, `GET /tickets/{id:[0-9]+}/attachments/{aid:[0-9]+}` |
| Admin | `GET /admin/tickets`, `GET` / `PATCH /admin/tickets/{id:[0-9]+}`, comments and attachments as above under `/admin` |
| Statuses | `GET` / `POST /admin/ticket-statuses`, `PATCH` / `DELETE /admin/ticket-statuses/{id:[0-9]+}` |
| Notifications | `GET` / `PUT /admin/ticket-settings` |
| Intake | `POST /tickets/contact`, `POST /tickets/ingest`, `POST /admin/tickets/ingest`, `GET /admin/tickets/imap-test`, `GET /admin/tickets/imap` (see [email-intake.md](email-intake.md)) |

## Data model

- Own tables via the core PDO: `ticket`, `ticket_status`, `ticket_comment`,
  `ticket_attachment`, `ticket_setting`. Migrations `CreateSupportTickets*`; this module
  owns the `20260725*` version band.
- `customer_id` / `project_id` have **no foreign key** (other domains). `customer_id` is the
  JWT's active company, **nullable** for contact-form and email tickets.
- `status_id` keeps its FK to the status registry in the same DB.

## Statuses

Admin CRUD on the status registry. Exactly one default is enforced. Delete answers **409**
when the status is in use or is the last one. Status colours are admin-typed data, so the
board renders them through `resolveChipVariant()`.

## Attachments

- `Support\AttachmentStorage` stores bytes under `TICKET_UPLOAD_DIR/{scope}/{uuid}-{name}`
  with a MIME allow-list and a 25 MB limit. Unset `TICKET_UPLOAD_DIR` → uploads **503**.
- Downloads are cookie-authenticated streams, not signed URLs; the session cookie is sent
  on `<a download>`.
- Uploads go out as multipart `FormData`, never JSON.

## Email notifications

Via the core `Mailer` (`Notifier`); no per-extension SMTP. Three toggleable events
(`ticket_setting`, `Domain\TicketSettings`, a toggle island in the settings slot):

1. new ticket → admin (`TICKET_ADMIN_EMAIL`),
2. owner reply → customer,
3. status change → customer.

The customer recipient is the ticket's `from_email`. A portal ticket stores the creator's
`UserContext::email()` (contract ≥ 1.2.0) there. Everything no-ops when a toggle is off,
the mailer is unconfigured or there is no recipient.

## Frontend

- `islands/TicketBoard.tsx` — list → detail → reply thread, new-ticket form, uploads.
- `islands/NotificationSettings.tsx` — the three toggles save immediately and round-trip
  the whole map. The checkbox flips optimistically and **rolls back** on failure.
- `islands/ImapSettings.tsx` — the mailbox settings.
- Every mutation reports its outcome via toast with the HTTP status. A rejected reply must
  not clear the reply box.

## Open items

The contact-tickets split (forwarding between this module and `tds-ext-contact-tickets-pkg`).
