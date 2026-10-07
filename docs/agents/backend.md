# Backend

The PHP half is a `Module` (`php/src/SupportTicketsModule.php`, extends `AbstractModule`) that the
composed `tds-core-frontend-api` mounts in one process with every other extension.

Classes: `Domain\TicketRepository`, `Domain\TicketSettings`, `Notifier`,
`Service\ImapConfig`, `Service\ImapTicketIngest`, `Support\AttachmentStorage`.

## Bind container entries unconditionally

Never guard a binding with `!$c->has(X::class)`. PHP-DI answers `has()` from its
definition sources, and **autowiring is one of them**: for any concrete class the answer
is always `true`, bound or not. A guard around the bindings never runs, and the container
autowires instead.

For the repositories that is invisible. For `ImapTicketIngest` it is fatal, because its
`$config` is built by the module's factory and can't be autowired:

```
Entry "…\Service\ImapTicketIngest" cannot be resolved:
Entry "…\Service\ImapConfig" cannot be resolved: the class is not instantiable
```

`POST /tickets/ingest` answered 500. PHP-DI builds entries lazily, so nothing fails at boot.
The module owns these classes, so it binds them unconditionally. `ExtensionBindingsTest` in
`tds-core-frontend-api` pins this.

## Migrations (Phinx)

All composed extensions share **one** `phinxlog`, so:

- The migration **class name** and the **numeric version prefix** must be unique across
  every extension. Put the module id in the class name.
- The **file name must map to the class** (`<version>_<snake_case_class>.php`). A
  mismatch throws `Could not find class …` during the scan and aborts **every**
  extension's migrations, not just this one.
- Migrations must survive MySQL 8 (production): no nullable primary-key columns, and
  unsigned ids where the other side is unsigned.

## API reference (`php/docs/api.php`)

The module implements the contract's optional `ApiDocSource`. `php/docs/api.php` returns
one entry per route (summary, params, responses, required permission). The admin
frontend's API reference joins it onto the introspected Slim routes by
`"<METHOD> <pattern>"`.

- **`pattern` must be the Slim pattern verbatim**, inline regex included. A prettified
  path silently produces an orphan doc and an undocumented route.
- **`php/tests/SupportTicketsApiDocsTest.php` asserts both directions:** documented and registered
  routes are the same set, every path placeholder is described, and a named permission
  exists in `permissions()`. Adding or renaming a route without touching `docs/api.php`
  fails there.

## Contract dependency

The published `tds-frontend-contract` is consumed VCS-only on the Composer side
(`type: vcs`) and from GitHub Packages on the npm side. Never use a `path` repository;
Composer fails on a missing path repo in CI.
