<?php
declare(strict_types=1);

namespace Tds\Ext\SupportTickets\Service;

use Tds\Ext\SupportTickets\Domain\TicketRepository;
use Tds\Ext\SupportTickets\Notifier;
use Tds\Ext\SupportTickets\Support\AttachmentStorage;
use Webklex\PHPIMAP\ClientManager;

/**
 * Inbound IMAP mail → tickets (ported from tds-customer-api). One poll()
 * connects to the mailbox, fetches UNSEEN messages and, per message: dedupes on
 * Message-ID, **threads a reply onto an existing ticket the sender owns** (a
 * `#<id>` subject marker or an In-Reply-To/References hit on a stored
 * Message-ID whose ticket carries the sender's `from_email`), and otherwise
 * **opens a new ticket if the ingest policy allows this sender**.
 *
 * That last step is what {@see ImapConfig} governs. It used to be absent: every
 * mail that was not a reply was dropped with a log line, so an inbox could be
 * fully configured and still never produce a single ticket. Opening one for
 * *anybody* is not a safe default either — an address that receives mail also
 * receives spam — so the policy defaults to `reply` (the old behaviour) and
 * widening it (`allowlist`, `all`) is an explicit choice in the panel.
 *
 * webklex/php-imap talks IMAP over stream sockets (no ext-imap / proc_open), so
 * it runs in-process. There is no worker on the prod host: poll() is driven by
 * an external scheduler hitting the secret ingest endpoint + the manual admin
 * button. All parsing lives in pure static helpers (unit-tested without a mailbox).
 */
final class ImapTicketIngest
{
    private const MAX_BODY = 10000;
    private const MAX_PER_POLL = 50;

    public function __construct(
        private readonly TicketRepository $tickets,
        private readonly AttachmentStorage $attachments,
        private readonly ImapConfig $config,
        private readonly ?Notifier $notifier = null,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->config->isConfigured();
    }

    /** @return array{ok:bool,error?:string} */
    public function testConnection(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'IMAP ist nicht konfiguriert.'];
        }
        try {
            $client = $this->connect();
            $client->getFolder($this->config->folder);
            $client->disconnect();
            return ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array{processed:int,created:int,appended:int,skipped:int,mode:string,polled:bool}
     */
    public function poll(): array
    {
        $stats = [
            'processed' => 0,
            'created' => 0,
            'appended' => 0,
            'skipped' => 0,
            'mode' => $this->config->mode,
            // Distinguishes "nothing new" from "never even connected" — without
            // it an unconfigured or switched-off mailbox reports an all-zero
            // success, which reads like a working ingest with an empty inbox.
            'polled' => false,
        ];
        if (!$this->config->isPollingEnabled()) {
            return $stats;
        }
        $stats['polled'] = true;
        $client = $this->connect();
        try {
            $folder = $client->getFolder($this->config->folder);
            $messages = $folder->messages()->unseen()->limit(self::MAX_PER_POLL)->get();
            foreach ($messages as $message) {
                $stats['processed']++;
                try {
                    $outcome = $this->handle($this->normalize($message));
                    $stats[$outcome]++;
                    $message->setFlag('Seen');
                } catch (\Throwable $e) {
                    $stats['skipped']++;
                    error_log('[ingest] message failed: ' . $e->getMessage());
                }
            }
        } finally {
            $client->disconnect();
        }
        return $stats;
    }

    /**
     * Persist one normalised message. Returns 'created' | 'appended' | 'skipped'.
     *
     * @param array{message_id:string,from:string,from_name?:string,subject:string,references:list<string>,body:string,attachments:list<array{filename:string,bytes:string,mime:string}>} $mail
     */
    public function handle(array $mail): string
    {
        $from = $mail['from'];
        if ($from === '') {
            return 'skipped';
        }
        $messageId = $mail['message_id'];
        if ($messageId !== '' && $this->tickets->emailMessageIdSeen($messageId)) {
            return 'skipped';
        }
        $body = $mail['body'] !== '' ? $mail['body'] : '(Kein Textinhalt in der E-Mail.)';

        $existing = $this->tickets->findEmailReplyTicket(
            self::parseTicketIdFromSubject($mail['subject']),
            $mail['references'],
            $from,
        );
        if ($existing !== null) {
            $commentId = $this->tickets->addEmailComment($existing, $body, $messageId);
            $this->tickets->clearCustomerAction($existing);
            $this->storeAttachments(0, $existing, $commentId, $mail['attachments']);
            return 'appended';
        }

        // No thread to append to: the ingest policy decides whether this sender
        // may open one. `reply` (the default) never does — that keeps a mailbox
        // switched on for support replies from becoming a spam funnel.
        if (!$this->config->opensTicketFor($from)) {
            error_log('[ingest] skip: policy "' . $this->config->mode . '" opens no ticket for ' . $from);
            return 'skipped';
        }

        $companyId = $this->config->matchCompany ? $this->tickets->findCompanyIdByEmail($from) : null;
        $subject = self::cleanSubject($mail['subject']);
        $newId = $this->tickets->createEmailTicket(
            $companyId,
            $subject,
            $body,
            $from,
            self::optional($mail['from_name'] ?? ''),
            $messageId,
        );
        // Attachments are bucketed by company on disk; an unbound sender uses
        // the shared 0 bucket, same as a contact-form ticket.
        $this->storeAttachments($companyId ?? 0, $newId, null, $mail['attachments']);
        $this->notifier?->onNewTicket($newId, $subject);
        return 'created';
    }

    private static function optional(string $value): ?string
    {
        $v = trim($value);
        return $v === '' ? null : $v;
    }

    /** @param list<array{filename:string,bytes:string,mime:string}> $atts */
    private function storeAttachments(int $scope, int $ticketId, ?int $commentId, array $atts): void
    {
        foreach ($atts as $a) {
            $stored = $this->attachments->storeBytes($scope, $a['filename'], $a['bytes'], $a['mime']);
            if ($stored === null) {
                continue;
            }
            $this->tickets->addAttachment([
                'ticket_id' => $ticketId,
                'comment_id' => $commentId,
                'filename' => $stored['filename'],
                'storage_path' => $stored['storage_path'],
                'mime_type' => $stored['mime_type'],
                'size_bytes' => $stored['size_bytes'],
                'uploaded_by_type' => 'customer',
            ]);
        }
    }

    // --- webklex plumbing (not unit-tested; a live send is a manual check) ----

    private function connect(): \Webklex\PHPIMAP\Client
    {
        $client = (new ClientManager())->make([
            'host' => $this->config->host,
            'port' => $this->config->port,
            'encryption' => $this->encryption(),
            'validate_cert' => true,
            'username' => $this->config->user,
            'password' => $this->config->password,
            'protocol' => 'imap',
        ]);
        $client->connect();
        return $client;
    }

    private function encryption(): string|false
    {
        return match ($this->config->security) {
            ImapConfig::SECURITY_SSL => 'ssl',
            ImapConfig::SECURITY_TLS => 'tls',
            default => false,
        };
    }

    /**
     * @return array{message_id:string,from:string,from_name:string,subject:string,references:list<string>,body:string,attachments:list<array{filename:string,bytes:string,mime:string}>}
     */
    private function normalize(\Webklex\PHPIMAP\Message $message): array
    {
        $from = '';
        $fromName = '';
        try {
            $addr = $message->getFrom()->first();
            $from = strtolower(trim((string) ($addr->mail ?? '')));
            // The display name is what makes an email ticket readable in the
            // list; without it every row is an address.
            $fromName = trim((string) ($addr->personal ?? ''));
        } catch (\Throwable) {
            // no parsable From → unknown sender downstream
        }
        $text = (string) $message->getTextBody();
        if ($text === '') {
            $html = (string) $message->getHTMLBody();
            $text = $html !== '' ? self::htmlToText($html) : '';
        }
        $attachments = [];
        try {
            foreach ($message->getAttachments() as $a) {
                $attachments[] = [
                    'filename' => (string) $a->getName(),
                    'bytes' => (string) $a->getContent(),
                    'mime' => (string) $a->getMimeType(),
                ];
            }
        } catch (\Throwable) {
            // none
        }
        return [
            'message_id' => self::normalizeMessageId((string) $message->getMessageId()),
            'from' => $from,
            'from_name' => $fromName,
            'subject' => (string) $message->getSubject(),
            'references' => self::extractMessageIds(
                trim((string) $message->getInReplyTo()) . ' ' . trim((string) $message->getReferences())
            ),
            'body' => self::clamp(self::stripQuotedReply($text)),
            'attachments' => $attachments,
        ];
    }

    // --- pure helpers (unit-tested) -------------------------------------------

    /** First "#<digits>" marker in a subject → ticket id, else null. */
    public static function parseTicketIdFromSubject(string $subject): ?int
    {
        return preg_match('/#(\d+)/', $subject, $m) ? (int) $m[1] : null;
    }

    /** Strip surrounding angle brackets from a Message-ID, if present. */
    public static function normalizeMessageId(string $raw): string
    {
        $raw = trim($raw);
        if (str_starts_with($raw, '<') && str_ends_with($raw, '>')) {
            $raw = substr($raw, 1, -1);
        }
        return trim($raw);
    }

    /**
     * All bracketed Message-IDs in an In-Reply-To / References header, bare +
     * de-duplicated; falls back to a single bare id when there are no brackets.
     *
     * @return list<string>
     */
    public static function extractMessageIds(string $header): array
    {
        if (preg_match_all('/<([^>]+)>/', $header, $m)) {
            return array_values(array_unique(array_map('trim', $m[1])));
        }
        $bare = self::normalizeMessageId($header);
        return $bare === '' ? [] : [$bare];
    }

    /** Drop quoted reply history so an appended comment is just the new text. */
    public static function stripQuotedReply(string $body): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $body) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $t = trim($line);
            if ($t !== '' && (
                preg_match('/^On .+ wrote:$/', $t)
                || preg_match('/^Am .+ schrieb .+:$/u', $t)
                || preg_match('/^-{2,}\s*Original Message\s*-{2,}$/i', $t)
                || preg_match('/^_{5,}$/', $t)
            )) {
                break;
            }
            if (str_starts_with($t, '>')) {
                continue;
            }
            $out[] = $line;
        }
        return trim(implode("\n", $out));
    }

    /** Crude HTML→text for HTML-only mails (no text/plain part). */
    public static function htmlToText(string $html): string
    {
        $text = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $html) ?? $html;
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text) ?? $text;
        $text = preg_replace('/<\/(p|div|tr|h[1-6]|li)>/i', "\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('/\n{3,}/', "\n\n", $text));
    }

    /** Strip leading reply prefixes, clamp to 200 chars, fall back when empty. */
    public static function cleanSubject(string $subject): string
    {
        $s = trim($subject);
        while (preg_match('/^(re|aw|fwd|fw|wg)\s*:\s*/i', $s)) {
            $s = trim((string) preg_replace('/^(re|aw|fwd|fw|wg)\s*:\s*/i', '', $s, 1));
        }
        $s = mb_substr($s, 0, 200);
        return $s !== '' ? $s : 'E-Mail-Anfrage';
    }

    private static function clamp(string $text): string
    {
        return mb_substr($text, 0, self::MAX_BODY);
    }
}
