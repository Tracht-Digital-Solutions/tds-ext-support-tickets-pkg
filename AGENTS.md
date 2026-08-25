# AGENTS.md — tds-ext-support-tickets-pkg

Support-ticket extension, ported from `tds-customer-api`. Read
`tds-frontend-contract-pkg` + `tds-core-frontend-api` AGENTS first — this consumes the
contract and the core services.

## Model / deviations from customer-api

- **Own DB tables** (`ticket`/`ticket_status`/`ticket_comment`) via the core PDO.
  `customer_id`/`project_id` carry **no foreign key** (those entities live in
  another domain) — `customer_id` = the JWT active company, NULLABLE for
  contact-form tickets. `status_id` keeps its FK to the same-DB status registry.
- **Auth via the core `UserContext`** — customer routes require
  `tickets:read`/`tickets:write` (admins bypass) + scope by `activeCompanyId`;
  admin routes require `isAdmin`. The module never verifies a token.
- **Email via the core `Mailer`** (`Notifier`) — no per-extension SMTP; no-ops
  when the core mailer is unconfigured. Admin recipient = `TICKET_ADMIN_EMAIL`;
  customer recipient = ticket `from_email` when present (a customer directory for
  portal-customer emails is a later port).
- **`author_type` is derived from the principal** (`owner` if admin else
  `customer`), never trusted from the client. `is_internal` comments are never
  returned to a customer principal (the customer `comments()` query filters them).

- **Every mutation reports its outcome — via a toast, from tds-shared.** Four
  paths here used to `await` a request and discard the response:
  `NotificationSettings.save` (the checkbox flips optimistically, so a 403 left
  it lying about a stored setting — it now rolls back), `TicketDetailView.send`
  (cleared the reply box either way, i.e. a rejected reply looked sent, with the
  text gone), `upload`, and `NewTicketForm.submit`. `toast.success/…danger` come
  from `@tracht-digital-solutions/tds-shared/components`; **never mount a
  `ToastHost`** — the frontend host owns the single one. Failure messages carry
  the HTTP status, because that is what separates "session expired" from
  "service down" in a bug report.

## Gotchas

- **Never guard a container binding with `!$c->has(X::class)` — the IMAP ingest
  500'd because of it.** PHP-DI answers `has()` out of its definition sources,
  and *autowiring is one of them*: for any concrete, instantiable class the
  answer is always `true`, bound or not. So the single guard wrapping all five
  bindings never ran, and the container quietly autowired instead. For the
  repositories that is invisible. For `ImapTicketIngest` it is fatal, because its
  `$config` is built by the factory here and cannot be autowired:

  ```
  Entry "…\Service\ImapTicketIngest" cannot be resolved:
  Entry "…\Service\ImapConfig" cannot be resolved: the class is not instantiable
  ```

  `POST /tickets/ingest` answered **500**. Nothing went red: this repo's CI runs
  type-check + build, not tests, and a PHP-DI entry is built lazily, so a broken
  binding costs nothing until the route is hit. The module owns these classes and
  nothing else defines them, so **bind unconditionally**. Pinned by
  `ExtensionBindingsTest` in `tds-core-frontend-api`.

- **Call the API with `apiFetch` from `@tracht-digital-solutions/tds-shared/api`,
  never a relative `fetch`.** Every island used to define its own
  `const api = (path, init) => fetch(path, { credentials: "include", ...init })`
  with a RELATIVE path. In a product that resolves against the product's own
  static host (`management.`/`app.tracht-digital.de`), not the API — and the
  static host answers unknown paths with its SPA fallback, i.e. **200 + HTML**.
  So `res.ok` is `true`, `res.json()` throws, and the usual
  `.catch(() => setRows([]))` renders a calm, permanent empty state with no
  error and no console warning. `apiFetch` resolves the base from
  `<meta name="tds-api-base">` (written by the frontend host) and routes 401s
  through the host's confirm-against-`/me` backstop, which extension calls
  previously skipped entirely.
  The island tests match on the request PATH (`pathOf()`), which a relative
  fetch satisfies just as well — so one assertion per suite pins the **absolute
  host**. That is the line that fails if this ever regresses.


- Migration class names are **module-prefixed** (`SupportTickets*`) AND the
  numeric **version prefixes are globally unique** (this module owns the
  `20260725*` band) — the in-process auto-migrator loads every composed module's
  migrations into one shared `phinxlog`, so a reused class name OR version
  collides. Keep new migrations in this band.
- Routes are closures resolving `UserContext`/`TicketRepository`/`Notifier` from
  the container **at request time** (UserContext is rebound per request by the
  core AuthMiddleware). Don't capture UserContext at register time.
- DB-backed tests skip without `TDS_TEST_DB_DSN`; the committed test covers
  routes + RBAC without a DB (auth short-circuits before repo access).
- **This package is pinned `^0.7.x` by BOTH products, not `^0.1.x`.** The root
  `CLAUDE.md` states extensions stay in the `0.1.x` line; that is not universal
  (this one is 0.7, contact-tickets is 0.2). What actually matters is never
  leaving the minor line the consumers caret-pin — under 0.x a caret means
  `>=0.7.x <0.8.0`, so a 0.8.0 here silently stops reaching the products.
  `tests/packaging.test.ts` guards it.

## Tests
- **CI runs `test:run` since 2026-08-25 — before that, none of these suites
  ever ran on a runner.** `_build.yml` had type-check, lint:primitives and
  build. That included the `ApiDocSource` parity test, whose entire job is to
  fail when a route gains or loses documentation.
- **The suites used to run against a tds-shared a dozen minors old, and the
  first honest run cost 30 failures across the twelve shipping extensions.**
  This package declares tds-shared as a **peer** with a `>=0.19.0` floor, so a
  fresh install resolved 0.19.0 while every product build composes the current
  one. Three separate behaviours had moved underneath the tests, and each is
  worth knowing because a new suite will hit them again:
  - `apiFetch` consults the host-side runtime config (`/tds-runtime.json`)
    before it resolves a URL, so `fetch.mock.calls[0]` is that probe, not the
    endpoint. Call **`primeRuntimeConfig(null)`** in `beforeEach` — the panel
    products never ship that file (they render `<meta name="tds-api-base">`),
    so "absent" is also what happens in production.
  - `apiFetch` is **async**: the request leaves on a later microtask than the
    render. Reading `mock.calls` on the line after `render(...)` yields
    `undefined`; `await waitFor(() => expect(fetch).toHaveBeenCalled())` first.
  - A multipart upload now carries an **empty** `headers` object rather than
    `undefined`. Identical to the browser — the boundary is still the
    browser's to set — so assert "no content-type header", never
    "headers is undefined".


`npm run test:run` (vitest; jsdom per-file via a `@vitest-environment` docblock).

- `islands/TicketBoard.test.tsx` — list → detail → reply thread, the new-ticket
  form, and attachment upload (asserted to go out as multipart `FormData`, not
  JSON). A non-OK response must never populate the board: the error-path tests
  deliberately carry a `tickets` payload, because against an EMPTY error body
  both branches look identical and the assertion proves nothing.
- `islands/NotificationSettings.test.tsx` — the three toggles save immediately
  and round-trip the whole map. The 403 case likewise carries a payload: this
  endpoint is admin-only, so a denied response must not populate the UI.
- `src/index.test.ts` + `tests/packaging.test.ts` — the manifest as a product
  build sees it, and that every specifier resolves to a real file that is both
  exported and published.

**Deliberately not asserted:** the `chip chip--${status_color}` class. That
value is admin-typed data from `support_tickets_status`, and the fix (routing it
through `resolveChipVariant`) lives on the `design/unify-library` branch, which
needs an unpublished tds-shared. Pinning the pre-fix class here would break that
merge; the status NAME is asserted instead.

Verified by mutation: 20 deliberate breakages introduced, 19 caught. The
twentieth — deleting the `if (reply.trim() === "") return;` guard — is an
equivalent mutant: the Senden button is already `disabled` in exactly that
state, so the guard is unreachable from the UI. It is defence in depth, kept.

## Checkpoint status

- **CP1:** schema + repository + customer/admin CRUD + comments + triage update +
  RBAC + widget.
- **CP2:** status-registry CRUD (admin, single-default enforce + delete-guards:
  409 when in use or last status) + the portal board UI (list + detail + comment
  thread + new-ticket form).
- **CP3:** attachments — `ticket_attachment` table, `Support\AttachmentStorage`
  (on-disk under `TICKET_UPLOAD_DIR`, MIME + 25 MB whitelist), customer + admin
  upload/download routes (cookie-authenticated streaming download, not signed
  URLs — the session cookie is sent on `<a download>`), attachments surfaced in
  the board detail + an upload control.
- **CP4:** notification toggles — `ticket_setting` registry + `Domain\TicketSettings`,
  admin `GET`/`PUT /admin/ticket-settings` + a toggle island in the settings slot.
  Three gated events via `Notifier`: new ticket→admin, owner reply→customer, status
  change→customer (all through the core Mailer; no-op when off/unconfigured/no recipient).
- **CP5a:** contact-form ingest — `POST /tickets/contact` (server-to-server,
  `INGEST_TOKEN` via `?token=`/`X-Ingest-Token`, constant-time). tds-contact-api
  forwards each submission; creates a `type/source='contact'`, NULL-customer ticket
  with `from_*` details + a validated payload (name≥2, valid email, message≥20).
- **CP5b:** IMAP ingest — `Service\ImapTicketIngest` (webklex/php-imap over sockets,
  no ext-imap; needs **ext-zip** → enabled in the CI setup-php). `POST /tickets/ingest`
  (ingest token, external scheduler) + admin `POST /admin/tickets/ingest` ("Jetzt
  abrufen") + `GET /admin/tickets/imap-test`. Dedupe on Message-ID, thread replies onto
  an owned ticket (`#<id>` subject / In-Reply-To/References match a stored Message-ID
  whose ticket carries the sender's `from_email`). Pure parsing helpers are unit-tested
  (no mailbox); webklex loads only on connect().
- **CP6:** portal-customer notification recipient — a portal ticket now stores the
  creator's `UserContext::email()` (contract 1.2.0) in `from_email`, so owner-reply +
  status-change emails reach portal customers (previously only contact/email tickets had
  a recipient).
- **CP7 (2026-08-15):** the mailbox became **panel-configurable**, and the ingest can
  finally **open** tickets. Two halves, both in `Service\ImapConfig`:
  - **Configuration is DB-first with an env fallback** (`SettingsStore` namespace
    `support-tickets`, section *Einstellungen → Support-Tickets → E-Mail-Eingang (IMAP)*,
    island `islands/ImapSettings.tsx`), the same pattern as the base's SMTP settings.
    Before this the mailbox was `IMAP_*`-only — i.e. only settable by editing a file on
    a Plesk host without SSH, which is why a shipped ingest was never switched on.
    `GET /admin/tickets/imap` reports what the ingest ACTUALLY uses incl.
    `source: db|env|none`, because the settings namespace alone would show an empty form
    on a host whose mailbox comes from its `.env` — and the first "fix" would overwrite
    a working mailbox. The password + ingest token are secrets (masked, blank = keep).
  - **`ingest_mode` decides what an unthreaded mail becomes:** `off` (no polling) ·
    `reply` (thread only — **the default**, i.e. the pre-CP7 behaviour) · `allowlist`
    (addresses and/or whole domains, matched on the domain boundary) · `all`. Opening a
    ticket for anyone is not a safe default: an address that receives mail also receives
    spam. A created ticket is `source='email'`, `from_name`/`from_email` from the
    envelope, subject via `cleanSubject()`, attachments stored, `Notifier::onNewTicket()`.
  - `ingest_match_company` (default on) binds the sender to a company when
    `company.email` matches exactly, which also makes the ticket visible in that
    company's portal. That table belongs to **tds-ext-customers-pkg**, which is not
    composed into the customer product — a missing table is a normal state, so
    `findCompanyIdByEmail()` catches and returns null. Full address only: a domain match
    would hand a freemail mailbox to whoever registered the domain first.
  - `poll()` returns `mode` + **`polled`** alongside the counters. An all-zero report
    from a mailbox that was never contacted reads exactly like an empty inbox; `polled`
    is what tells "nothing new" from "not configured / switched off".
  - **`ImapConfig` is deliberately NOT a container entry.** PHP-DI autowires unknown
    classes, so a value object with a private constructor resolves to "class is not
    instantiable" in every container without an explicit definition — which is every
    isolated test. `SupportTicketsModule::imapConfig()` resolves it per request instead,
    which also means a panel save takes effect on the very next request.
  - **`IMAP_PASSWORD` is now read, with `IMAP_PASS` kept as an alias.** This module read
    `IMAP_PASS` while every `.env.example` and the installer documented `IMAP_PASSWORD` —
    a host following the docs configured a mailbox the module never authenticated to.
- **TODO (next):** the contact-tickets split.

Env (host-side, all now only a FALLBACK behind the panel settings):
`TICKET_ADMIN_EMAIL`, `TICKET_UPLOAD_DIR` (unset → uploads 503), `INGEST_TOKEN`
(unset and none stored → ingest 503), `IMAP_HOST`/`IMAP_PORT`/`IMAP_USER`/
`IMAP_PASSWORD` (alias `IMAP_PASS`)/`IMAP_SECURITY` (ssl|tls|none)/`IMAP_FOLDER`
(unset host/user → poll no-ops), `TICKET_INGEST_MODE`, `TICKET_INGEST_MATCH_COMPANY`.
The connection fields follow the **host**, all or nothing: a panel-configured mailbox
never takes single fields from the env, or a login failure has no explicable cause.

## After a change

Bump `version` in `package.json` + `composer.json` (lockstep), update docs,
commit together.

## API-Referenz (`php/docs/api.php`)

This module implements the contract's optional `ApiDocSource`: `php/docs/api.php`
returns one entry per route (summary, params, responses, required permission),
and the admin frontend's API reference joins it onto the introspected Slim routes
by `"<METHOD> <pattern>"`. Two things to know before editing a route:

- **`pattern` must be the Slim pattern verbatim**, inline regex included
  (`/admin/tickets/{id:[0-9]+}/attachments/{aid:[0-9]+}`). A prettified path silently produces an orphan doc *and* an
  undocumented route rather than an error.
- **`php/tests/SupportTicketsApiDocsTest.php` asserts both directions** — the documented
  set and the registered set must be the same set, every path placeholder must
  be described, and a named permission must exist in `permissions()`. Adding or
  renaming a route without touching `docs/api.php` fails there. That is the
  point: prose next to code rots, and a reference full of confident, wrong
  detail is worse than the bare route list it replaced.
