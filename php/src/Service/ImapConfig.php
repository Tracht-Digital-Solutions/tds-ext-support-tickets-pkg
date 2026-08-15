<?php
declare(strict_types=1);

namespace Tds\Ext\SupportTickets\Service;

use Tds\Frontend\Contract\SettingsStore;

/**
 * Resolved configuration for the inbound mailbox (email → ticket).
 *
 * Read **DB-first with an env fallback** — the platform's standard pattern for
 * third-party config ({@see SettingsStore} namespace `support-tickets`, edited
 * under Einstellungen → *Support-Tickets → E-Mail-Eingang (IMAP)*). Until this
 * existed the mailbox was `IMAP_*`-only: configurable exclusively by editing a
 * file on the production host, which on a Plesk host without SSH means not
 * configurable at all. The ingest existed and shipped, and nobody could switch
 * it on from the panel.
 *
 * Beyond the connection it also carries the **ingest policy** — the answer to
 * "what happens to a mail that belongs to no existing ticket". That is a
 * decision, not a detail: an inbox that opens a ticket for every sender is also
 * an inbox that opens a ticket for every spam run, so the default stays the
 * conservative one and widening it is an explicit act in the panel.
 *
 *   - `off`       — do not poll at all.
 *   - `reply`     — thread replies onto tickets the sender already owns, never
 *                   open a new one. **The default**, i.e. the behaviour of every
 *                   deployment before this class existed.
 *   - `allowlist` — additionally open new tickets, but only for senders on the
 *                   allowlist (single addresses and/or whole domains).
 *   - `all`       — open a new ticket for any sender.
 *
 * Never throws. Without a database — the state the frontend service is in until
 * `services/frontend/.env` + `tds_frontend` exist — it resolves to the env
 * values, so the settings section renders "nicht konfiguriert" instead of a 500.
 */
final class ImapConfig
{
    public const NAMESPACE = 'support-tickets';

    public const MODE_OFF = 'off';
    public const MODE_REPLY = 'reply';
    public const MODE_ALLOWLIST = 'allowlist';
    public const MODE_ALL = 'all';

    /** @var list<string> */
    public const MODES = [self::MODE_OFF, self::MODE_REPLY, self::MODE_ALLOWLIST, self::MODE_ALL];

    public const SECURITY_SSL = 'ssl';
    public const SECURITY_TLS = 'tls';
    public const SECURITY_NONE = 'none';

    /**
     * Declared settings keys, in form order: [key, secret]. The settings island
     * renders exactly these, so a new setting is one edit here plus one field in
     * `ImapSettings.tsx`.
     *
     * @var list<array{0:string,1:bool}>
     */
    public const KEYS = [
        ['imap_host', false],
        ['imap_port', false],
        ['imap_security', false],
        ['imap_user', false],
        ['imap_password', true],
        ['imap_folder', false],
        ['ingest_mode', false],
        ['ingest_allowlist', false],
        ['ingest_match_company', false],
        ['ingest_token', true],
    ];

    private function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $security,
        public readonly string $user,
        public readonly string $password,
        public readonly string $folder,
        public readonly string $mode,
        /** @var list<string> Lower-cased addresses (`a@b.de`) and domains (`b.de`). */
        public readonly array $allowlist,
        public readonly bool $matchCompany,
        public readonly string $ingestToken,
        /** `db` | `env` | `none` — which layer the mailbox came from. */
        public readonly string $source,
    ) {
    }

    /**
     * @param callable(string):string $env Reads one environment variable ('' when unset).
     */
    public static function resolve(?SettingsStore $store, ?callable $env = null): self
    {
        $env ??= static fn (string $key): string => (string) (getenv($key) ?: '');

        $read = static function (string $key, bool $secret) use ($store): string {
            try {
                $stored = $secret
                    ? $store?->getSecret(self::NAMESPACE, $key)
                    : $store?->get(self::NAMESPACE, $key);
            } catch (\Throwable) {
                // No DB yet (or an undecryptable value) — fall through to env.
                $stored = null;
            }
            return is_string($stored) ? trim($stored) : '';
        };

        $host = $read('imap_host', false);
        $source = 'db';
        if ($host === '') {
            $host = trim($env('IMAP_HOST'));
            $source = $host === '' ? 'none' : 'env';
        }

        // The connection fields follow the HOST, all or nothing. Mixing the two
        // layers field by field would point a panel-configured mailbox at the
        // host's `.env` credentials — a different mailbox, and a login failure
        // whose cause is invisible from either place.
        $fromDb = $source === 'db';
        $pick = static function (string $key, string $envKey, string $default) use ($read, $env, $fromDb): string {
            $value = $fromDb ? $read($key, false) : trim($env($envKey));
            return $value !== '' ? $value : $default;
        };

        $password = $fromDb ? $read('imap_password', true) : '';
        if (!$fromDb) {
            // IMAP_PASSWORD is the spelling the installer and every .env.example
            // use; IMAP_PASS is what this module read before and stays accepted
            // so an existing host keeps working.
            $password = trim($env('IMAP_PASSWORD')) ?: trim($env('IMAP_PASS'));
        }

        $token = $read('ingest_token', true);
        if ($token === '') {
            $token = trim($env('INGEST_TOKEN'));
        }

        $modeRaw = $read('ingest_mode', false);
        if ($modeRaw === '') {
            $modeRaw = trim($env('TICKET_INGEST_MODE'));
        }

        $matchRaw = $read('ingest_match_company', false);
        if ($matchRaw === '') {
            $matchRaw = trim($env('TICKET_INGEST_MATCH_COMPANY'));
        }

        // A garbled port must not become port 0 and a connection error nobody
        // can explain from the form, which shows what was typed.
        $port = (int) $pick('imap_port', 'IMAP_PORT', '993');
        if ($port < 1 || $port > 65535) {
            $port = 993;
        }

        return new self(
            host: $host,
            port: $port,
            security: self::normalizeSecurity($pick('imap_security', 'IMAP_SECURITY', self::SECURITY_SSL)),
            user: $pick('imap_user', 'IMAP_USER', ''),
            password: $password,
            folder: $pick('imap_folder', 'IMAP_FOLDER', 'INBOX'),
            mode: self::normalizeMode($modeRaw),
            allowlist: self::parseAllowlist($read('ingest_allowlist', false)),
            matchCompany: $matchRaw === '' ? true : self::truthy($matchRaw),
            ingestToken: $token,
            source: $source,
        );
    }

    /** A mailbox we could connect to (host + user present). */
    public function isConfigured(): bool
    {
        return $this->host !== '' && $this->user !== '';
    }

    /** Whether poll() should run at all. */
    public function isPollingEnabled(): bool
    {
        return $this->isConfigured() && $this->mode !== self::MODE_OFF;
    }

    /**
     * Whether a mail from this sender that belongs to no existing ticket may
     * open a new one. Replies thread regardless of the policy — the sender
     * already has a ticket, so they are already known.
     */
    public function opensTicketFor(string $email): bool
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return false;
        }
        return match ($this->mode) {
            self::MODE_ALL => true,
            self::MODE_ALLOWLIST => self::matchesAllowlist($email, $this->allowlist),
            default => false,
        };
    }

    /**
     * What the settings section shows. Carries no secret — only *whether* a
     * password and a token are stored.
     *
     * @return array{configured:bool,polling:bool,source:string,host:string,port:int,security:string,user:string,folder:string,password_configured:bool,mode:string,allowlist:list<string>,match_company:bool,token_configured:bool}
     */
    public function status(): array
    {
        return [
            'configured' => $this->isConfigured(),
            'polling' => $this->isPollingEnabled(),
            'source' => $this->source,
            'host' => $this->host,
            'port' => $this->port,
            'security' => $this->security,
            'user' => $this->user,
            'folder' => $this->folder,
            'password_configured' => $this->password !== '',
            'mode' => $this->mode,
            'allowlist' => $this->allowlist,
            'match_company' => $this->matchCompany,
            'token_configured' => $this->ingestToken !== '',
        ];
    }

    // --- pure helpers (unit-tested) -------------------------------------------

    public static function normalizeMode(string $value): string
    {
        $v = strtolower(trim($value));
        return in_array($v, self::MODES, true) ? $v : self::MODE_REPLY;
    }

    public static function normalizeSecurity(string $value): string
    {
        return match (strtolower(trim($value))) {
            self::SECURITY_TLS, 'starttls' => self::SECURITY_TLS,
            self::SECURITY_NONE, 'off', '0', 'false' => self::SECURITY_NONE,
            default => self::SECURITY_SSL,
        };
    }

    /**
     * Split a free-text allowlist into normalised entries. Accepts newlines,
     * commas, semicolons and spaces as separators, and both `name@example.de`
     * and `@example.de` / `example.de` for a whole domain — a leading `@` is
     * dropped so the two domain spellings cannot become two different rules.
     *
     * @return list<string>
     */
    public static function parseAllowlist(string $raw): array
    {
        $parts = preg_split('/[\s,;]+/', strtolower(trim($raw))) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $entry = ltrim(trim($part), '@');
            if ($entry !== '' && !in_array($entry, $out, true)) {
                $out[] = $entry;
            }
        }
        return array_values($out);
    }

    /**
     * An address matches when it is listed itself, or when its domain is —
     * compared on the domain boundary, so `example.de` never matches
     * `notexample.de`.
     *
     * @param list<string> $allowlist
     */
    public static function matchesAllowlist(string $email, array $allowlist): bool
    {
        $email = strtolower(trim($email));
        if ($email === '' || $allowlist === []) {
            return false;
        }
        $at = strrpos($email, '@');
        $domain = $at === false ? '' : substr($email, $at + 1);
        foreach ($allowlist as $entry) {
            if ($entry === $email) {
                return true;
            }
            if ($domain !== '' && ($entry === $domain || str_ends_with($domain, '.' . $entry))) {
                return true;
            }
        }
        return false;
    }

    private static function truthy(string $value): bool
    {
        return !in_array(strtolower(trim($value)), ['0', 'false', 'no', 'off', ''], true);
    }
}
