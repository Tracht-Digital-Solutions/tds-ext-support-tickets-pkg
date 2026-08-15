<?php
declare(strict_types=1);

namespace Tds\Ext\SupportTickets\Tests;

use PHPUnit\Framework\TestCase;
use Tds\Ext\SupportTickets\Service\ImapConfig;
use Tds\Frontend\Contract\SettingsStore;

/** An in-memory SettingsStore double — the store the base binds needs a DB. */
final class FakeSettingsStore implements SettingsStore
{
    /** @param array<string,string> $values */
    public function __construct(private array $values = [], private readonly bool $throws = false)
    {
    }

    public function get(string $namespace, string $key, ?string $default = null): ?string
    {
        if ($this->throws) {
            throw new \RuntimeException('no database');
        }
        return $this->values[$key] ?? $default;
    }

    public function getSecret(string $namespace, string $key): ?string
    {
        return $this->get($namespace, $key);
    }

    public function set(string $namespace, string $key, string $value, bool $secret): void
    {
        $this->values[$key] = $value;
    }

    public function delete(string $namespace, string $key): void
    {
        unset($this->values[$key]);
    }

    /** @return list<array<string,mixed>> */
    public function allMasked(string $namespace): array
    {
        return [];
    }
}

/**
 * The mailbox config: precedence between the panel and the host's `.env`, and
 * the ingest policy that decides whether a stranger's mail becomes a ticket.
 * Both are pure — no mailbox, no database.
 */
final class ImapConfigTest extends TestCase
{
    /** @param array<string,string> $env */
    private function env(array $env): callable
    {
        return static fn (string $key): string => $env[$key] ?? '';
    }

    public function testFallsBackToEnvWhenNothingIsStored(): void
    {
        $config = ImapConfig::resolve(null, $this->env([
            'IMAP_HOST' => 'imap.example.net',
            'IMAP_USER' => 'support@example.net',
            'IMAP_PASSWORD' => 'geheim',
            'IMAP_PORT' => '143',
            'IMAP_SECURITY' => 'tls',
            'IMAP_FOLDER' => 'Support',
        ]));

        self::assertSame('env', $config->source);
        self::assertSame('imap.example.net', $config->host);
        self::assertSame(143, $config->port);
        self::assertSame(ImapConfig::SECURITY_TLS, $config->security);
        self::assertSame('Support', $config->folder);
        self::assertSame('geheim', $config->password);
        self::assertTrue($config->isConfigured());
    }

    public function testLegacyImapPassSpellingStillWorks(): void
    {
        // This module read IMAP_PASS while every .env.example documented
        // IMAP_PASSWORD; dropping the old spelling would log an existing host
        // out of its own mailbox.
        $config = ImapConfig::resolve(null, $this->env([
            'IMAP_HOST' => 'imap.example.net',
            'IMAP_USER' => 'support@example.net',
            'IMAP_PASS' => 'alt',
        ]));
        self::assertSame('alt', $config->password);
    }

    public function testStoredMailboxOutranksEnvAndDoesNotMixLayers(): void
    {
        $config = ImapConfig::resolve(
            new FakeSettingsStore([
                'imap_host' => 'imap.panel.net',
                'imap_user' => 'panel@panel.net',
                'imap_password' => 'panel-secret',
            ]),
            $this->env([
                'IMAP_HOST' => 'imap.env.net',
                'IMAP_USER' => 'env@env.net',
                'IMAP_PASSWORD' => 'env-secret',
                'IMAP_FOLDER' => 'Env-Ordner',
            ]),
        );

        self::assertSame('db', $config->source);
        self::assertSame('imap.panel.net', $config->host);
        self::assertSame('panel@panel.net', $config->user);
        self::assertSame('panel-secret', $config->password);
        // The env belongs to a DIFFERENT mailbox: its folder must not bleed into
        // the panel-configured one, or a login failure has no explicable cause.
        self::assertSame('INBOX', $config->folder);
    }

    public function testResolvesWithoutADatabase(): void
    {
        // The frontend service runs without a DB until services/frontend/.env
        // exists; the settings page has to render, not 500.
        $config = ImapConfig::resolve(new FakeSettingsStore(throws: true), $this->env([]));
        self::assertSame('none', $config->source);
        self::assertFalse($config->isConfigured());
        self::assertFalse($config->isPollingEnabled());
    }

    public function testDefaultModeIsReplyOnly(): void
    {
        $config = ImapConfig::resolve(null, $this->env([
            'IMAP_HOST' => 'imap.example.net',
            'IMAP_USER' => 'support@example.net',
        ]));
        // The behaviour every deployment had before the policy existed.
        self::assertSame(ImapConfig::MODE_REPLY, $config->mode);
        self::assertTrue($config->isPollingEnabled());
        self::assertFalse($config->opensTicketFor('fremd@example.org'));
    }

    public function testModeOffStopsPollingEvenWhenConfigured(): void
    {
        $config = ImapConfig::resolve(new FakeSettingsStore([
            'imap_host' => 'imap.example.net',
            'imap_user' => 'support@example.net',
            'ingest_mode' => 'off',
        ]), $this->env([]));
        self::assertTrue($config->isConfigured());
        self::assertFalse($config->isPollingEnabled());
    }

    public function testModeAllOpensTicketsForAnySender(): void
    {
        $config = ImapConfig::resolve(new FakeSettingsStore([
            'imap_host' => 'imap.example.net',
            'imap_user' => 'support@example.net',
            'ingest_mode' => 'all',
        ]), $this->env([]));
        self::assertTrue($config->opensTicketFor('wer.auch.immer@example.org'));
        self::assertFalse($config->opensTicketFor('  '));
    }

    public function testAllowlistModeAcceptsAddressesAndDomains(): void
    {
        $config = ImapConfig::resolve(new FakeSettingsStore([
            'imap_host' => 'imap.example.net',
            'imap_user' => 'support@example.net',
            'ingest_mode' => 'allowlist',
            'ingest_allowlist' => "chef@kunde.de\n@partner.de, sub.example.com",
        ]), $this->env([]));

        self::assertTrue($config->opensTicketFor('chef@kunde.de'));
        self::assertTrue($config->opensTicketFor('CHEF@KUNDE.DE'));
        self::assertTrue($config->opensTicketFor('irgendwer@partner.de'));
        self::assertTrue($config->opensTicketFor('a@mail.sub.example.com'));
        self::assertFalse($config->opensTicketFor('kollege@kunde.de'));
        self::assertFalse($config->opensTicketFor('spam@example.org'));
    }

    public function testAllowlistDoesNotMatchOnASuffixThatIsNotADomainBoundary(): void
    {
        $list = ImapConfig::parseAllowlist('example.de');
        self::assertTrue(ImapConfig::matchesAllowlist('a@example.de', $list));
        // "notexample.de" merely ENDS with the entry — a substring match here
        // would hand any lookalike domain a ticket.
        self::assertFalse(ImapConfig::matchesAllowlist('a@notexample.de', $list));
    }

    public function testParseAllowlistNormalisesAndDeduplicates(): void
    {
        self::assertSame(
            ['a@b.de', 'partner.de'],
            ImapConfig::parseAllowlist("A@B.de , @partner.de;partner.de\n a@b.de "),
        );
        self::assertSame([], ImapConfig::parseAllowlist('   '));
    }

    public function testUnknownModeFallsBackToTheSafeOne(): void
    {
        self::assertSame(ImapConfig::MODE_REPLY, ImapConfig::normalizeMode('vielleicht'));
        self::assertSame(ImapConfig::MODE_ALL, ImapConfig::normalizeMode(' ALL '));
    }

    public function testGarbledPortFallsBackToTheDefault(): void
    {
        $config = ImapConfig::resolve(new FakeSettingsStore([
            'imap_host' => 'imap.example.net',
            'imap_user' => 'support@example.net',
            'imap_port' => 'neunhundert',
        ]), $this->env([]));
        self::assertSame(993, $config->port);
    }

    public function testIngestTokenPrefersTheStoredValueOverEnv(): void
    {
        $config = ImapConfig::resolve(
            new FakeSettingsStore(['ingest_token' => 'aus-dem-panel']),
            $this->env(['INGEST_TOKEN' => 'aus-der-env']),
        );
        self::assertSame('aus-dem-panel', $config->ingestToken);

        $envOnly = ImapConfig::resolve(null, $this->env(['INGEST_TOKEN' => 'aus-der-env']));
        self::assertSame('aus-der-env', $envOnly->ingestToken);
    }

    public function testStatusCarriesNoSecrets(): void
    {
        $status = ImapConfig::resolve(new FakeSettingsStore([
            'imap_host' => 'imap.example.net',
            'imap_user' => 'support@example.net',
            'imap_password' => 'geheim',
            'ingest_token' => 'auch-geheim',
        ]), $this->env([]))->status();

        $flat = json_encode($status, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('geheim', $flat);
        self::assertTrue($status['password_configured']);
        self::assertTrue($status['token_configured']);
        self::assertSame('db', $status['source']);
    }
}
