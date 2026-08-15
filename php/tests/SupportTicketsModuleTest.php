<?php
declare(strict_types=1);

namespace Tds\Ext\SupportTickets\Tests;

use DI\Container;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tds\Ext\SupportTickets\SupportTicketsModule;
use Tds\Frontend\Contract\UserContext;

/** A configurable UserContext double (no live JWT needed). */
final class FakeUser implements UserContext
{
    /** @param string[] $perms */
    public function __construct(
        private bool $auth = true,
        private bool $admin = false,
        private array $perms = [],
        private ?int $company = null,
        private ?int $uid = 1,
    ) {
    }

    public function isAuthenticated(): bool
    {
        return $this->auth;
    }

    public function userId(): ?int
    {
        return $this->uid;
    }

    public function email(): ?string
    {
        return null;
    }

    public function isAdmin(): bool
    {
        return $this->admin;
    }

    /** @return string[] */
    public function permissions(): array
    {
        return $this->perms;
    }

    public function has(string $permission): bool
    {
        return $this->admin || in_array($permission, $this->perms, true);
    }

    public function activeCompanyId(): ?int
    {
        return $this->company;
    }
}

/**
 * Route + RBAC coverage that needs no DB: the auth checks short-circuit before
 * any repository access, and the "no active company" path returns an empty list
 * without touching the DB. (Full data paths are covered by the DB-gated
 * repository test.)
 */
final class SupportTicketsModuleTest extends TestCase
{
    private function appWith(UserContext $user): \Slim\App
    {
        $container = new Container();
        $container->set(UserContext::class, $user);
        AppFactory::setContainer($container);
        $app = AppFactory::create();
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        (new SupportTicketsModule())->register($app);
        return $app;
    }

    private function get(\Slim\App $app, string $path): \Psr\Http\Message\ResponseInterface
    {
        return $app->handle((new ServerRequestFactory())->createServerRequest('GET', $path));
    }

    public function testMetadata(): void
    {
        $module = new SupportTicketsModule();
        self::assertSame('support-tickets', $module->id());
        $ids = array_map(static fn ($p): string => $p->id, $module->permissions());
        self::assertSame(['tickets:read', 'tickets:write'], $ids);
        self::assertDirectoryExists($module->migrations()[0]);
    }

    public function testUnauthenticatedGetsUnauthorized(): void
    {
        $res = $this->get($this->appWith(new FakeUser(auth: false)), '/tickets');
        self::assertSame(401, $res->getStatusCode());
    }

    public function testAuthenticatedWithoutPermissionForbidden(): void
    {
        $res = $this->get($this->appWith(new FakeUser(perms: [])), '/tickets');
        self::assertSame(403, $res->getStatusCode());
    }

    public function testReaderWithoutActiveCompanyGetsEmptyList(): void
    {
        $res = $this->get($this->appWith(new FakeUser(perms: ['tickets:read'], company: null)), '/tickets');
        self::assertSame(200, $res->getStatusCode());
        self::assertSame(['tickets' => []], json_decode((string) $res->getBody(), true));
    }

    public function testAdminRouteRequiresAdmin(): void
    {
        $res = $this->get($this->appWith(new FakeUser(perms: ['tickets:read'])), '/admin/tickets');
        self::assertSame(403, $res->getStatusCode());
    }

    public function testStatusCreateRequiresAdmin(): void
    {
        $res = $this->post(
            $this->appWith(new FakeUser(perms: ['tickets:write'])),
            '/admin/ticket-statuses',
            ['name' => 'Neu', 'color' => 'info'],
        );
        self::assertSame(403, $res->getStatusCode());
    }

    public function testStatusCreateRejectsInvalidColour(): void
    {
        $res = $this->post(
            $this->appWith(new FakeUser(admin: true)),
            '/admin/ticket-statuses',
            ['name' => 'Neu', 'color' => 'chartreuse'],
        );
        self::assertSame(422, $res->getStatusCode());
        self::assertStringContainsString('color must be one of', (string) $res->getBody());
    }

    public function testStatusCreateRejectsMissingName(): void
    {
        $res = $this->post(
            $this->appWith(new FakeUser(admin: true)),
            '/admin/ticket-statuses',
            ['color' => 'info'],
        );
        self::assertSame(422, $res->getStatusCode());
    }

    public function testAttachmentUploadRequiresWritePermission(): void
    {
        $res = $this->post(
            $this->appWith(new FakeUser(perms: ['tickets:read'])),
            '/tickets/1/attachments',
            [],
        );
        self::assertSame(403, $res->getStatusCode());
    }

    public function testAdminAttachmentUploadRequiresAdmin(): void
    {
        $res = $this->post(
            $this->appWith(new FakeUser(perms: ['tickets:write'])),
            '/admin/tickets/1/attachments',
            [],
        );
        self::assertSame(403, $res->getStatusCode());
    }

    public function testTicketSettingsRequireAdmin(): void
    {
        $app = $this->appWith(new FakeUser(perms: ['tickets:write']));
        self::assertSame(403, $this->get($app, '/admin/ticket-settings')->getStatusCode());
    }

    public function testContactIngestRequiresConfiguredToken(): void
    {
        putenv('INGEST_TOKEN');
        $res = $this->post($this->appWith(new FakeUser(auth: false)), '/tickets/contact', []);
        self::assertSame(503, $res->getStatusCode());
    }

    public function testContactIngestRejectsBadToken(): void
    {
        putenv('INGEST_TOKEN=secret');
        $res = $this->post($this->appWith(new FakeUser(auth: false)), '/tickets/contact?token=wrong', []);
        self::assertSame(401, $res->getStatusCode());
        putenv('INGEST_TOKEN');
    }

    public function testContactIngestValidatesPayload(): void
    {
        putenv('INGEST_TOKEN=secret');
        $res = $this->post(
            $this->appWith(new FakeUser(auth: false)),
            '/tickets/contact?token=secret',
            ['name' => 'A', 'email' => 'not-an-email', 'message' => 'short'],
        );
        self::assertSame(422, $res->getStatusCode());
        putenv('INGEST_TOKEN');
    }

    public function testImapIngestRequiresToken(): void
    {
        putenv('INGEST_TOKEN');
        $res = $this->post($this->appWith(new FakeUser(auth: false)), '/tickets/ingest', []);
        self::assertSame(503, $res->getStatusCode());
    }

    public function testImapTestRequiresAdmin(): void
    {
        $app = $this->appWith(new FakeUser(perms: ['tickets:read']));
        self::assertSame(403, $this->get($app, '/admin/tickets/imap-test')->getStatusCode());
    }

    public function testImapStatusRequiresAdmin(): void
    {
        $app = $this->appWith(new FakeUser(perms: ['tickets:read']));
        self::assertSame(403, $this->get($app, '/admin/tickets/imap')->getStatusCode());
    }

    public function testImapStatusReportsAnUnconfiguredMailboxWithoutADatabase(): void
    {
        // The settings section has to render on a host that has neither a
        // mailbox nor a DB yet — that is the state before go-live, and a 500
        // there would look like a broken panel rather than a pending setup.
        putenv('IMAP_HOST');
        $res = $this->get($this->appWith(new FakeUser(admin: true)), '/admin/tickets/imap');
        self::assertSame(200, $res->getStatusCode());
        $body = json_decode((string) $res->getBody(), true);
        self::assertFalse($body['configured']);
        self::assertSame('none', $body['source']);
        self::assertSame('reply', $body['mode']);
    }

    /** @param array<string,mixed> $body */
    private function post(\Slim\App $app, string $path, array $body): \Psr\Http\Message\ResponseInterface
    {
        $req = (new ServerRequestFactory())->createServerRequest('POST', $path)
            ->withHeader('Content-Type', 'application/json')
            ->withParsedBody($body);
        return $app->handle($req);
    }
}
