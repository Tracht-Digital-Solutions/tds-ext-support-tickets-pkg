# AGENTS.md — tds-ext-support-tickets-pkg

Frontend extension for **support tickets**: a portal board for customers, an admin
workflow with configurable statuses, comments and attachments, email notifications, and
ticket intake from the contact form and an IMAP mailbox. Ported from `tds-customer-api`.
It contributes a PHP `Module` to `tds-core-frontend-api` and board, settings and widget
to both panel products. It is the reference full port for other extensions.

Read `tds-frontend-contract-pkg/AGENTS.md` and `tds-core-frontend-api/AGENTS.md` first.

## Commands

```bash
npm install --no-package-lock   # never npm ci
npm run type-check
npm run lint:primitives
npm run test:run                # vitest
npm run build                   # tsup
composer install && composer test   # phpunit; DB-backed tests need TDS_TEST_DB_DSN
```

## Hard rules

- **Bind container entries unconditionally.** Never guard with `!$c->has(X::class)`. `ImapConfig` is never a container entry.
- Resolve `UserContext` at request time, never at register time.
- `author_type` comes from the principal, never from the client. Internal comments never reach a customer.
- Every mutation checks its response and reports via toast; optimistic UI rolls back. Never mount a `ToastHost`.
- The default ingest mode is `reply`; never default to opening tickets for any sender.
- Call the API with `apiFetch`, never a relative `fetch`.
- Every route change updates `php/docs/api.php`. New migrations stay in the `20260725*` band.
- Stay in the `0.7.x` line (both products pin `^0.7.x`). Versions move via the release workflow only.

## Topic files

| File | Read before |
|---|---|
| [docs/agents/architecture.md](docs/agents/architecture.md) | Changing routes, auth, the data model, attachments or notifications |
| [docs/agents/email-intake.md](docs/agents/email-intake.md) | Touching contact ingest, IMAP, `ImapConfig` or mailbox settings |
| [docs/agents/backend.md](docs/agents/backend.md) | Touching PHP, DI bindings, migrations or `php/docs/api.php` |
| [docs/agents/frontend-conventions.md](docs/agents/frontend-conventions.md) | Touching any island, page or widget markup |
| [docs/agents/testing.md](docs/agents/testing.md) | Writing or changing tests |
| [docs/agents/release.md](docs/agents/release.md) | Releasing or changing CI |

Workspace rules: `../CLAUDE.md`. Cross-repo state: `../MIGRATION-STATUS.md`.
