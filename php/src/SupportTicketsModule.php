<?php
declare(strict_types=1);

namespace Tds\Ext\SupportTickets;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Slim\App;
use Slim\Psr7\Factory\StreamFactory;
use Tds\Ext\SupportTickets\Domain\TicketRepository;
use Tds\Ext\SupportTickets\Domain\TicketSettings;
use Tds\Ext\SupportTickets\Service\ImapConfig;
use Tds\Ext\SupportTickets\Service\ImapTicketIngest;
use Tds\Ext\SupportTickets\Support\AttachmentStorage;
use Tds\Frontend\Contract\AbstractModule;
use Tds\Frontend\Contract\ApiDocSource;
use Tds\Frontend\Contract\Mailer;
use Tds\Frontend\Contract\PermissionDef;
use Tds\Frontend\Contract\SettingsStore;
use Tds\Frontend\Contract\UserContext;

/**
 * Backend Module for the support-ticket system (checkpoint-1 surface: customer
 * list/get/create/comment + widget summary; admin list/get/comment/triage-update).
 *
 * Auth comes entirely from the core {@see UserContext} — customer routes require
 * `tickets:read`/`tickets:write` (admins bypass) and scope by the active company;
 * admin routes require `isAdmin`. Email goes through the core {@see Mailer} via
 * {@see Notifier}. Data via the core shared PDO.
 *
 * Still to port (later checkpoints): status registry CRUD, attachments, richer
 * notifications + customer directory, IMAP + contact-form ingest.
 */
final class SupportTicketsModule extends AbstractModule implements ApiDocSource
{
    public function id(): string
    {
        return 'support-tickets';
    }

    /** @return PermissionDef[] */
    public function permissions(): array
    {
        return [
            new PermissionDef('tickets:read', 'Tickets ansehen', 'support-tickets'),
            new PermissionDef('tickets:write', 'Tickets erstellen & beantworten', 'support-tickets'),
        ];
    }

    /** @return string[] */
    public function migrations(): array
    {
        return [__DIR__ . '/../db/migrations'];
    }

    public function register(App $app): void
    {
        $c = $app->getContainer();
        // NEVER guard these with `!$c->has(X)`. PHP-DI answers `has()` from its
        // definition sources, and autowiring is one of them: for any *concrete,
        // instantiable* class the answer is always true, whether or not anyone
        // ever bound it. So the guard skipped every binding below and the
        // container silently autowired instead — invisible for the repositories,
        // fatal for ImapTicketIngest, whose `$config` argument is built by the
        // factory here and is not autowirable: `POST /tickets/ingest` answered
        // 500 with `Entry ImapConfig cannot be resolved: the class is not
        // instantiable`. The module owns these classes; nothing else defines
        // them, so binding unconditionally is the correct shape.
        if ($c !== null) {
            $c->set(TicketRepository::class, static fn ($c) => new TicketRepository($c->get(PDO::class)));
            $c->set(TicketSettings::class, static fn ($c) => new TicketSettings($c->get(PDO::class)));
            $c->set(Notifier::class, static fn ($c) => new Notifier(
                $c->get(Mailer::class),
                $c->get(TicketSettings::class),
                (string) (getenv('TICKET_ADMIN_EMAIL') ?: ''),
            ));
            $c->set(AttachmentStorage::class, static fn () => new AttachmentStorage());
            $c->set(ImapTicketIngest::class, static fn ($c) => new ImapTicketIngest(
                $c->get(TicketRepository::class),
                $c->get(AttachmentStorage::class),
                self::imapConfig($c),
                $c->get(Notifier::class),
            ));
        }

        // --- Customer (portal) routes ------------------------------------------
        $app->get('/tickets/summary', function (Request $req, Response $res) use ($c): Response {
            $user = $c->get(UserContext::class);
            if (($deny = self::require($user, 'tickets:read', $res)) !== null) {
                return $deny;
            }
            $repo = $c->get(TicketRepository::class);
            $open = $user->isAdmin() && $user->activeCompanyId() === null
                ? $repo->openCountAll()
                : $repo->openCountForCustomer((int) $user->activeCompanyId());
            return self::json($res, ['open' => $open]);
        });

        $app->get('/tickets', function (Request $req, Response $res) use ($c): Response {
            $user = $c->get(UserContext::class);
            if (($deny = self::require($user, 'tickets:read', $res)) !== null) {
                return $deny;
            }
            $cid = $user->activeCompanyId();
            $tickets = $cid === null ? [] : $c->get(TicketRepository::class)->listForCustomer($cid);
            return self::json($res, ['tickets' => $tickets]);
        });

        $app->post('/tickets', function (Request $req, Response $res) use ($c): Response {
            $user = $c->get(UserContext::class);
            if (($deny = self::require($user, 'tickets:write', $res)) !== null) {
                return $deny;
            }
            $cid = $user->activeCompanyId();
            if ($cid === null) {
                return self::json($res, ['error' => 'No active company'], 400);
            }
            $body = (array) $req->getParsedBody();
            $subject = trim((string) ($body['subject'] ?? ''));
            $description = trim((string) ($body['description'] ?? ''));
            if ($subject === '' || $description === '') {
                return self::json($res, ['error' => 'subject and description are required'], 422);
            }
            $repo = $c->get(TicketRepository::class);
            $id = $repo->createForCustomer(
                $cid,
                $user->userId(),
                $subject,
                $description,
                self::enum($body['type'] ?? 'question', ['question', 'bug', 'feature', 'other'], 'question'),
                self::enum($body['priority'] ?? 'normal', ['low', 'normal', 'high', 'urgent'], 'normal'),
                $user->email(),
            );
            $c->get(Notifier::class)->onNewTicket($id, $subject);
            return self::json($res, ['id' => $id], 201);
        });

        $app->get('/tickets/{id:[0-9]+}', function (Request $req, Response $res, array $args) use ($c): Response {
            $user = $c->get(UserContext::class);
            if (($deny = self::require($user, 'tickets:read', $res)) !== null) {
                return $deny;
            }
            $repo = $c->get(TicketRepository::class);
            $ticket = $repo->find((int) $args['id']);
            if ($ticket === null || (int) $ticket['customer_id'] !== $user->activeCompanyId()) {
                return self::json($res, ['error' => 'Not found'], 404);
            }
            $ticket['comments'] = $repo->comments((int) $ticket['id'], includeInternal: false);
            $ticket['attachments'] = $repo->attachments((int) $ticket['id']);
            return self::json($res, $ticket);
        });

        $app->post('/tickets/{id:[0-9]+}/attachments', function (Request $req, Response $res, array $args) use ($c): Response {
            $user = $c->get(UserContext::class);
            if (($deny = self::require($user, 'tickets:write', $res)) !== null) {
                return $deny;
            }
            $repo = $c->get(TicketRepository::class);
            $id = (int) $args['id'];
            $ticket = $repo->find($id);
            if ($ticket === null || (int) $ticket['customer_id'] !== $user->activeCompanyId()) {
                return self::json($res, ['error' => 'Not found'], 404);
            }
            return self::upload($req, $res, $repo, $c->get(AttachmentStorage::class), $id, (int) $user->activeCompanyId(), $user->isAdmin());
        });

        $app->get('/tickets/{id:[0-9]+}/attachments/{aid:[0-9]+}', function (Request $req, Response $res, array $args) use ($c): Response {
            $user = $c->get(UserContext::class);
            if (($deny = self::require($user, 'tickets:read', $res)) !== null) {
                return $deny;
            }
            $repo = $c->get(TicketRepository::class);
            $ticket = $repo->find((int) $args['id']);
            if ($ticket === null || (int) $ticket['customer_id'] !== $user->activeCompanyId()) {
                return self::json($res, ['error' => 'Not found'], 404);
            }
            return self::download($res, $repo, $c->get(AttachmentStorage::class), (int) $args['id'], (int) $args['aid']);
        });

        $app->post('/tickets/{id:[0-9]+}/comments', function (Request $req, Response $res, array $args) use ($c): Response {
            $user = $c->get(UserContext::class);
            if (($deny = self::require($user, 'tickets:write', $res)) !== null) {
                return $deny;
            }
            $repo = $c->get(TicketRepository::class);
            $id = (int) $args['id'];
            $ticket = $repo->find($id);
            if ($ticket === null || (int) $ticket['customer_id'] !== $user->activeCompanyId()) {
                return self::json($res, ['error' => 'Not found'], 404);
            }
            $text = trim((string) (((array) $req->getParsedBody())['body'] ?? ''));
            if ($text === '') {
                return self::json($res, ['error' => 'body is required'], 422);
            }
            $repo->addComment($id, 'customer', $user->userId(), $text, isInternal: false);
            $repo->clearCustomerAction($id);
            return self::json($res, ['ok' => true], 201);
        });

        // --- Ingest (server-to-server, INGEST_TOKEN — not a browser session) ---
        // Contact-form submissions forwarded by tds-contact-api become tickets
        // categorised type/source='contact' with a NULL customer_id + from_*
        // details. (IMAP ingest — POST /tickets/ingest — lands in CP5b.)
        $app->post('/tickets/contact', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::checkIngestToken($req, $res, self::imapConfig($c))) !== null) {
                return $deny;
            }
            $body = (array) $req->getParsedBody();
            $name = trim((string) ($body['name'] ?? ''));
            $email = strtolower(trim((string) ($body['email'] ?? '')));
            $message = trim((string) ($body['message'] ?? ''));
            $companyRaw = trim((string) ($body['company'] ?? ''));
            $company = $companyRaw === '' ? null : mb_substr($companyRaw, 0, 200);
            if (mb_strlen($name) < 2
                || filter_var($email, FILTER_VALIDATE_EMAIL) === false
                || mb_strlen($message) < 20
            ) {
                return self::json($res, ['error' => 'Invalid contact payload'], 422);
            }
            $id = $c->get(TicketRepository::class)->createContactTicket($name, $email, $company, $message);
            $c->get(Notifier::class)->onNewTicket($id, 'Kontaktanfrage von ' . $name);
            return self::json($res, ['id' => $id], 201);
        });

        // IMAP poll, driven by an external scheduler (no cron/CLI on the prod
        // host). INGEST_TOKEN-gated; threads inbound replies onto owned tickets.
        $app->post('/tickets/ingest', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::checkIngestToken($req, $res, self::imapConfig($c))) !== null) {
                return $deny;
            }
            return self::json($res, $c->get(ImapTicketIngest::class)->poll());
        });

        // --- Admin routes ------------------------------------------------------
        $app->get('/admin/tickets', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::requireAdmin($c->get(UserContext::class), $res)) !== null) {
                return $deny;
            }
            return self::json($res, ['tickets' => $c->get(TicketRepository::class)->adminList()]);
        });

        $app->get('/admin/tickets/{id:[0-9]+}', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::requireAdmin($c->get(UserContext::class), $res)) !== null) {
                return $deny;
            }
            $repo = $c->get(TicketRepository::class);
            $ticket = $repo->find((int) $args['id']);
            if ($ticket === null) {
                return self::json($res, ['error' => 'Not found'], 404);
            }
            $ticket['comments'] = $repo->comments((int) $ticket['id'], includeInternal: true);
            $ticket['attachments'] = $repo->attachments((int) $ticket['id']);
            return self::json($res, $ticket);
        });

        $app->post('/admin/tickets/{id:[0-9]+}/attachments', function (Request $req, Response $res, array $args) use ($c): Response {
            $user = $c->get(UserContext::class);
            if (($deny = self::requireAdmin($user, $res)) !== null) {
                return $deny;
            }
            $repo = $c->get(TicketRepository::class);
            $id = (int) $args['id'];
            $ticket = $repo->find($id);
            if ($ticket === null) {
                return self::json($res, ['error' => 'Not found'], 404);
            }
            $scope = (int) ($ticket['customer_id'] ?? 0);
            return self::upload($req, $res, $repo, $c->get(AttachmentStorage::class), $id, $scope, true);
        });

        $app->get('/admin/tickets/{id:[0-9]+}/attachments/{aid:[0-9]+}', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::requireAdmin($c->get(UserContext::class), $res)) !== null) {
                return $deny;
            }
            return self::download($res, $c->get(TicketRepository::class), $c->get(AttachmentStorage::class), (int) $args['id'], (int) $args['aid']);
        });

        $app->post('/admin/tickets/{id:[0-9]+}/comments', function (Request $req, Response $res, array $args) use ($c): Response {
            $user = $c->get(UserContext::class);
            if (($deny = self::requireAdmin($user, $res)) !== null) {
                return $deny;
            }
            $repo = $c->get(TicketRepository::class);
            $id = (int) $args['id'];
            $ticket = $repo->find($id);
            if ($ticket === null) {
                return self::json($res, ['error' => 'Not found'], 404);
            }
            $body = (array) $req->getParsedBody();
            $text = trim((string) ($body['body'] ?? ''));
            if ($text === '') {
                return self::json($res, ['error' => 'body is required'], 422);
            }
            $internal = (bool) ($body['is_internal'] ?? false);
            $repo->addComment($id, 'owner', $user->userId(), $text, $internal);
            if (!$internal) {
                $c->get(Notifier::class)->onOwnerReply($id, (string) $ticket['subject'], $ticket['from_email'] ?? null);
            }
            return self::json($res, ['ok' => true], 201);
        });

        $app->patch('/admin/tickets/{id:[0-9]+}', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::requireAdmin($c->get(UserContext::class), $res)) !== null) {
                return $deny;
            }
            $repo = $c->get(TicketRepository::class);
            $id = (int) $args['id'];
            $ticket = $repo->find($id);
            if ($ticket === null) {
                return self::json($res, ['error' => 'Not found'], 404);
            }
            $body = (array) $req->getParsedBody();
            $fields = [];
            foreach (['status_id', 'assignee_user_id'] as $k) {
                if (array_key_exists($k, $body)) {
                    $fields[$k] = $body[$k] === null ? null : (int) $body[$k];
                }
            }
            if (isset($body['priority'])) {
                $fields['priority'] = self::enum($body['priority'], ['low', 'normal', 'high', 'urgent'], 'normal');
            }
            if (isset($body['type'])) {
                $fields['type'] = self::enum($body['type'], ['question', 'bug', 'feature', 'other', 'contact'], 'question');
            }
            if (array_key_exists('customer_action_required', $body)) {
                $fields['customer_action_required'] = (bool) $body['customer_action_required'] ? 1 : 0;
            }
            if (array_key_exists('customer_action_note', $body)) {
                $fields['customer_action_note'] = $body['customer_action_note'] === null ? null : (string) $body['customer_action_note'];
            }
            $repo->updateAdmin($id, $fields);
            if (isset($fields['status_id']) && $fields['status_id'] !== (int) $ticket['status_id']) {
                $c->get(Notifier::class)->onStatusChange($id, (string) $ticket['subject'], $ticket['from_email'] ?? null);
            }
            return self::json($res, ['ok' => true]);
        });

        // Manual "Jetzt abrufen" + IMAP connection test (admin).
        $app->post('/admin/tickets/ingest', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::requireAdmin($c->get(UserContext::class), $res)) !== null) {
                return $deny;
            }
            return self::json($res, $c->get(ImapTicketIngest::class)->poll());
        });

        $app->get('/admin/tickets/imap-test', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::requireAdmin($c->get(UserContext::class), $res)) !== null) {
                return $deny;
            }
            return self::json($res, $c->get(ImapTicketIngest::class)->testConnection());
        });

        // What the ingest ACTUALLY uses, which the settings namespace alone
        // cannot answer: the mailbox may still come from the host's `.env`, and
        // an empty form on a working host invites "fixing" a mailbox that was
        // never broken. Carries no secret — only whether one is stored.
        $app->get('/admin/tickets/imap', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::requireAdmin($c->get(UserContext::class), $res)) !== null) {
                return $deny;
            }
            return self::json($res, self::imapConfig($c)->status());
        });

        // Ticket settings (notification toggles) — admin.
        $app->get('/admin/ticket-settings', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::requireAdmin($c->get(UserContext::class), $res)) !== null) {
                return $deny;
            }
            return self::json($res, ['settings' => $c->get(TicketSettings::class)->all()]);
        });

        $app->put('/admin/ticket-settings', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::requireAdmin($c->get(UserContext::class), $res)) !== null) {
                return $deny;
            }
            $body = (array) $req->getParsedBody();
            $c->get(TicketSettings::class)->put($body);
            return self::json($res, ['settings' => $c->get(TicketSettings::class)->all()]);
        });

        // Status registry (admin CRUD).
        $app->get('/admin/ticket-statuses', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::requireAdmin($c->get(UserContext::class), $res)) !== null) {
                return $deny;
            }
            return self::json($res, ['statuses' => $c->get(TicketRepository::class)->statuses()]);
        });

        $app->post('/admin/ticket-statuses', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::requireAdmin($c->get(UserContext::class), $res)) !== null) {
                return $deny;
            }
            $data = self::statusPayload((array) $req->getParsedBody());
            if (is_string($data)) {
                return self::json($res, ['error' => $data], 422);
            }
            return self::json($res, ['id' => $c->get(TicketRepository::class)->createStatus($data)], 201);
        });

        $app->patch('/admin/ticket-statuses/{id:[0-9]+}', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::requireAdmin($c->get(UserContext::class), $res)) !== null) {
                return $deny;
            }
            $repo = $c->get(TicketRepository::class);
            $id = (int) $args['id'];
            if (!$repo->statusExists($id)) {
                return self::json($res, ['error' => 'Not found'], 404);
            }
            $data = self::statusPayload((array) $req->getParsedBody());
            if (is_string($data)) {
                return self::json($res, ['error' => $data], 422);
            }
            $repo->updateStatus($id, $data);
            return self::json($res, ['ok' => true]);
        });

        $app->delete('/admin/ticket-statuses/{id:[0-9]+}', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::requireAdmin($c->get(UserContext::class), $res)) !== null) {
                return $deny;
            }
            $repo = $c->get(TicketRepository::class);
            $id = (int) $args['id'];
            if (!$repo->statusExists($id)) {
                return self::json($res, ['error' => 'Not found'], 404);
            }
            if ($repo->statusInUse($id)) {
                return self::json($res, ['error' => 'Status wird von Tickets verwendet und kann nicht gelöscht werden.'], 409);
            }
            if ($repo->statusCount() <= 1) {
                return self::json($res, ['error' => 'Mindestens ein Status muss bestehen bleiben.'], 409);
            }
            $repo->deleteStatus($id);
            return self::json($res, ['ok' => true]);
        });
    }

    // --- helpers ---------------------------------------------------------------

    /** 401/403 response when the principal fails the permission check, else null. */
    private static function require(UserContext $user, string $permission, Response $res): ?Response
    {
        if (!$user->isAuthenticated()) {
            return self::json($res, ['error' => 'Unauthorized'], 401);
        }
        if (!$user->has($permission)) {
            return self::json($res, ['error' => 'Forbidden'], 403);
        }
        return null;
    }

    private static function requireAdmin(UserContext $user, Response $res): ?Response
    {
        if (!$user->isAuthenticated()) {
            return self::json($res, ['error' => 'Unauthorized'], 401);
        }
        if (!$user->isAdmin()) {
            return self::json($res, ['error' => 'Forbidden'], 403);
        }
        return null;
    }

    /**
     * Validate + normalise a ticket_status payload. Returns the clean data array
     * or an error message string.
     *
     * @param array<string,mixed> $body
     * @return array{name:string,color:string,sort_order:int,visible_to_customer:bool,is_terminal:bool,is_default:bool}|string
     */
    private static function statusPayload(array $body): array|string
    {
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return 'name is required';
        }
        $color = (string) ($body['color'] ?? 'neutral');
        if (!in_array($color, TicketRepository::STATUS_COLORS, true)) {
            return 'color must be one of: ' . implode(', ', TicketRepository::STATUS_COLORS);
        }
        return [
            'name' => $name,
            'color' => $color,
            'sort_order' => (int) ($body['sort_order'] ?? 0),
            'visible_to_customer' => (bool) ($body['visible_to_customer'] ?? true),
            'is_terminal' => (bool) ($body['is_terminal'] ?? false),
            'is_default' => (bool) ($body['is_default'] ?? false),
        ];
    }

    /** @param string[] $allowed */
    private static function enum(mixed $value, array $allowed, string $default): string
    {
        $v = is_string($value) ? $value : '';
        return in_array($v, $allowed, true) ? $v : $default;
    }

    private static function json(Response $res, mixed $data, int $status = 200): Response
    {
        $res->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));
        return $res->withStatus($status)->withHeader('Content-Type', 'application/json');
    }

    /**
     * The core's settings store if the base bound it (it resolves the contract
     * interface), else null — so an isolated unit test (no core) falls back to env.
     */
    private static function settingsStore(\Psr\Container\ContainerInterface $c): ?SettingsStore
    {
        return $c->has(SettingsStore::class) ? $c->get(SettingsStore::class) : null;
    }

    /**
     * The mailbox configuration for this request. Deliberately NOT a container
     * entry: PHP-DI autowires unknown classes, so a value object with a private
     * constructor resolves to "class is not instantiable" in any container that
     * has not been given an explicit definition — which is every isolated test.
     * Resolving it here costs two indexed reads and keeps a panel save effective
     * on the very next request.
     */
    private static function imapConfig(\Psr\Container\ContainerInterface $c): ImapConfig
    {
        return ImapConfig::resolve(self::settingsStore($c));
    }

    /**
     * Verify the shared ingest token (server-to-server auth for the ingest
     * endpoints). Returns an error response, or null when the token is valid.
     *
     * The token is panel-editable like the rest of the mailbox config and falls
     * back to `INGEST_TOKEN` from the environment; an unset token leaves the
     * route switched off rather than open.
     */
    private static function checkIngestToken(Request $req, Response $res, ImapConfig $config): ?Response
    {
        $expected = $config->ingestToken;
        if ($expected === '') {
            return self::json($res, ['error' => 'INGEST_TOKEN not configured'], 503);
        }
        $provided = (string) ($req->getQueryParams()['token'] ?? $req->getHeaderLine('X-Ingest-Token'));
        if ($provided === '' || !hash_equals($expected, $provided)) {
            return self::json($res, ['error' => 'Invalid ingest token'], 401);
        }
        return null;
    }

    /** Shared multipart upload: validates the "file" part, stores it, records metadata. */
    private static function upload(
        Request $req,
        Response $res,
        TicketRepository $repo,
        AttachmentStorage $storage,
        int $ticketId,
        int $scope,
        bool $isAdmin,
    ): Response {
        $file = $req->getUploadedFiles()['file'] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return self::json($res, ['error' => 'No valid file uploaded under "file"'], 400);
        }
        if ($file->getSize() === null || $file->getSize() > AttachmentStorage::MAX_BYTES) {
            return self::json($res, ['error' => 'File exceeds the 25 MB limit'], 413);
        }
        $mime = $file->getClientMediaType() ?? 'application/octet-stream';
        if (!in_array($mime, AttachmentStorage::ALLOWED_MIME, true)) {
            return self::json($res, ['error' => 'Mime type not allowed', 'mime' => $mime], 415);
        }
        if (!$storage->available()) {
            return self::json($res, ['error' => 'Attachment storage unavailable'], 503);
        }
        $meta = $storage->store($scope, $file);
        $aid = $repo->addAttachment([
            'ticket_id' => $ticketId,
            'comment_id' => null,
            'filename' => $meta['filename'],
            'storage_path' => $meta['storage_path'],
            'mime_type' => $meta['mime_type'],
            'size_bytes' => $meta['size_bytes'],
            'uploaded_by_type' => $isAdmin ? 'owner' : 'customer',
        ]);
        return self::json($res, ['id' => $aid, 'filename' => $meta['filename']], 201);
    }

    /** Shared authenticated download: streams the file (auth already checked by the caller). */
    private static function download(
        Response $res,
        TicketRepository $repo,
        AttachmentStorage $storage,
        int $ticketId,
        int $attachmentId,
    ): Response {
        $a = $repo->findAttachment($ticketId, $attachmentId);
        if ($a === null) {
            return self::json($res, ['error' => 'Not found'], 404);
        }
        $abs = $storage->absolutePath((string) $a['storage_path']);
        if ($abs === null) {
            return self::json($res, ['error' => 'File missing on disk'], 404);
        }
        return $res
            ->withBody((new StreamFactory())->createStreamFromFile($abs))
            ->withHeader('Content-Type', (string) $a['mime_type'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . $a['filename'] . '"')
            ->withHeader('Content-Length', (string) $a['size_bytes']);
    }

    /**
     * Route documentation for the admin frontend's API reference. Kept in its
     * own file so the prose does not sit in the middle of the wiring.
     *
     * @return list<array<string, mixed>>
     */
    public function apiDocs(): array
    {
        return require __DIR__ . '/../docs/api.php';
    }
}
