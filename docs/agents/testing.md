# Testing

```bash
npm run test:run   # vitest; islands opt into jsdom per file via a @vitest-environment docblock
composer test      # phpunit
```

CI (`_build.yml`) runs both suites, plus type-check, `lint:primitives` and build, on
every run.

## Test environment against the current tds-shared

tds-shared is a **peer** dependency, so a fresh standalone install may resolve an old
version while every product build composes the current one. Keep these in mind:

- `apiFetch` first consults the host runtime config (`/tds-runtime.json`), so
  `fetch.mock.calls[0]` is that probe. Call **`primeRuntimeConfig(null)`** in
  `beforeEach`; panel products never ship that file, so "absent" matches production.
- `apiFetch` is **async**. `await waitFor(() => expect(fetch).toHaveBeenCalled())`
  before reading `mock.calls`.
- A multipart upload carries an **empty** `headers` object. Assert "no content-type
  header", never "headers is undefined".
- Error-path tests answer with a **populated** body and a non-OK status. Against an
  empty error body the ok-check is unobservable.
- At least one assertion per suite pins the **absolute API host** (see
  [frontend-conventions.md](frontend-conventions.md#api-calls-apifetch-never-a-relative-fetch)).

## Suites

| Suite | Sharpest assertions |
|---|---|
| `islands/TicketBoard.test.tsx` | List → detail → reply; new-ticket form; upload as multipart `FormData`; a non-OK response never populates the board (error bodies carry a `tickets` payload) |
| `islands/NotificationSettings.test.tsx` | Toggles save immediately and round-trip the map; a 403 (admin-only endpoint) never populates the UI |
| `islands/ImapSettings.test.tsx` | Mailbox settings, secrets masked, blank keeps |
| `src/index.test.ts` + `tests/packaging.test.ts` | Manifest as a product build sees it; specifiers exported and published; version stays in the **0.7** line |
| `php/tests/SupportTicketsModuleTest.php` | Routes and RBAC without a DB (auth short-circuits before repo access) |
| `php/tests/ImapConfigTest.php`, `ImapParsingTest.php` | Config resolution and pure parsing helpers |
| `php/tests/SupportTicketsApiDocsTest.php` | API-doc parity |

Mutation check: 20 breakages, 19 caught. The survivor (deleting
`if (reply.trim() === "") return;`) is equivalent: the send button is already `disabled` in
exactly that state. Kept as defence in depth.

### Version line

Both products caret-pin `^0.7.x`; under 0.x that means `<0.8.0`. A 0.8.0 here silently stops
reaching them. Never leave the minor line your consumers pin.
