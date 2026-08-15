<?php
declare(strict_types=1);

namespace Tds\Ext\SupportTickets\Domain;

use PDO;

/**
 * Ticket data access (ported from tds-customer-api's TicketRepository, trimmed
 * to the checkpoint-1 surface: statuses, customer + admin list/get/create,
 * comments, open counts). Uses the core's shared PDO. Customer scoping is by
 * `customer_id` (= the JWT active company); a null customer_id ticket is
 * admin-only. Status visibility to customers is resolved here so a
 * non-`visible_to_customer` status shows a neutral fallback in the portal.
 */
final class TicketRepository
{
    /** Allowed chip tones for a status colour (design-system semantic palette). */
    public const STATUS_COLORS = ['neutral', 'info', 'success', 'warning', 'danger'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> */
    public function statuses(): array
    {
        return $this->pdo->query(
            'SELECT id, name, color, sort_order, visible_to_customer, is_terminal, is_default
             FROM ticket_status ORDER BY sort_order, id'
        )->fetchAll();
    }

    public function defaultStatusId(): int
    {
        $id = $this->pdo->query(
            'SELECT id FROM ticket_status ORDER BY is_default DESC, sort_order, id LIMIT 1'
        )->fetchColumn();
        if ($id === false) {
            throw new \RuntimeException('No ticket_status configured');
        }
        return (int) $id;
    }

    /**
     * Tickets for a customer (portal view). status label is masked to a neutral
     * fallback when the status is not visible_to_customer.
     *
     * @return list<array<string,mixed>>
     */
    public function listForCustomer(int $customerId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.id, t.subject, t.priority, t.type, t.updated_at, t.customer_action_required,
                    s.is_terminal,
                    CASE WHEN s.visible_to_customer = 1 THEN s.name ELSE :fallback END AS status_name,
                    CASE WHEN s.visible_to_customer = 1 THEN s.color ELSE :fallbackColor END AS status_color
             FROM ticket t JOIN ticket_status s ON s.id = t.status_id
             WHERE t.customer_id = :cid
             ORDER BY t.updated_at DESC'
        );
        $stmt->execute([':cid' => $customerId, ':fallback' => 'In Bearbeitung', ':fallbackColor' => 'info']);
        return $stmt->fetchAll();
    }

    public function openCountForCustomer(int $customerId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ticket t JOIN ticket_status s ON s.id = t.status_id
             WHERE t.customer_id = :cid AND s.is_terminal = 0'
        );
        $stmt->execute([':cid' => $customerId]);
        return (int) $stmt->fetchColumn();
    }

    public function openCountAll(): int
    {
        return (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ticket t JOIN ticket_status s ON s.id = t.status_id WHERE s.is_terminal = 0'
        )->fetchColumn();
    }

    /** @return list<array<string,mixed>> */
    public function adminList(): array
    {
        return $this->pdo->query(
            'SELECT t.id, t.customer_id, t.subject, t.priority, t.type, t.source, t.assignee_user_id,
                    t.customer_action_required, t.from_name, t.from_email, t.updated_at,
                    s.name AS status_name, s.color AS status_color, s.is_terminal
             FROM ticket t JOIN ticket_status s ON s.id = t.status_id
             ORDER BY t.updated_at DESC'
        )->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.*, s.name AS status_name, s.color AS status_color,
                    s.visible_to_customer, s.is_terminal
             FROM ticket t JOIN ticket_status s ON s.id = t.status_id WHERE t.id = :id'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function comments(int $ticketId, bool $includeInternal): array
    {
        $sql = 'SELECT id, author_type, author_user_id, body, is_internal, created_at, edited_at
                FROM ticket_comment WHERE ticket_id = :tid';
        if (!$includeInternal) {
            $sql .= ' AND is_internal = 0';
        }
        $sql .= ' ORDER BY created_at, id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':tid' => $ticketId]);
        return $stmt->fetchAll();
    }

    /**
     * Open a portal ticket for a customer. Starts in the default status,
     * created_by_type=customer.
     */
    public function createForCustomer(
        int $customerId,
        ?int $userId,
        string $subject,
        string $description,
        string $type,
        string $priority,
        ?string $email = null,
    ): int {
        // from_email carries the creator's address so owner-reply / status-change
        // notifications can reach a portal customer (there's no customer directory).
        $stmt = $this->pdo->prepare(
            'INSERT INTO ticket (customer_id, status_id, subject, description, priority, type,
                                 created_by_type, created_by_user_id, source, from_email)
             VALUES (:cid, :sid, :subject, :description, :priority, :type, \'customer\', :uid, \'portal\', :email)'
        );
        $stmt->execute([
            ':cid' => $customerId,
            ':sid' => $this->defaultStatusId(),
            ':subject' => $subject,
            ':description' => $description,
            ':priority' => $priority,
            ':type' => $type,
            ':uid' => $userId,
            ':email' => $email,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Open a ticket from a contact-form submission. customer_id stays NULL (the
     * submitter is usually not a customer; a customer directory could bind it
     * later), details live in from_*, categorised type/source=contact.
     */
    public function createContactTicket(string $name, string $email, ?string $company, string $message): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ticket (customer_id, status_id, subject, description, priority, type,
                                 created_by_type, source, from_name, from_email, from_company)
             VALUES (NULL, :sid, :subject, :description, \'normal\', \'contact\', \'customer\', \'contact\',
                     :name, :email, :company)'
        );
        $stmt->execute([
            ':sid' => $this->defaultStatusId(),
            ':subject' => mb_substr('Kontaktanfrage von ' . $name, 0, 200),
            ':description' => mb_substr($message, 0, 10000),
            ':name' => $name,
            ':email' => $email,
            ':company' => $company,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Open a ticket from an inbound email. The mirror of
     * {@see createContactTicket()} for the IMAP channel: the sender is usually
     * not a portal user, so `created_by_user_id` stays NULL and the identity
     * lives in `from_*`. `customer_id` is set only when the sender could be
     * bound to a company ({@see findCompanyIdByEmail()}) — with it the ticket
     * also shows up in that company's portal, without it it is admin-only.
     *
     * `email_message_id` is what makes a re-delivered mail a duplicate rather
     * than a second ticket, and what lets the sender's reply thread back onto
     * this one via References/In-Reply-To.
     */
    public function createEmailTicket(
        ?int $customerId,
        string $subject,
        string $body,
        string $fromEmail,
        ?string $fromName,
        string $messageId,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ticket (customer_id, status_id, subject, description, priority, type,
                                 created_by_type, created_by_user_id, source, email_message_id,
                                 from_name, from_email)
             VALUES (:cid, :sid, :subject, :description, \'normal\', \'question\', \'customer\', NULL,
                     \'email\', :mid, :name, :email)'
        );
        $stmt->execute([
            ':cid' => $customerId,
            ':sid' => $this->defaultStatusId(),
            ':subject' => mb_substr($subject, 0, 200),
            ':description' => mb_substr($body, 0, 10000),
            ':mid' => $messageId !== '' ? $messageId : null,
            ':name' => $fromName === null || $fromName === '' ? null : mb_substr($fromName, 0, 200),
            ':email' => mb_substr(strtolower($fromEmail), 0, 254),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Company id whose directory entry carries this address, or null.
     *
     * The `company` table belongs to **tds-ext-customers-pkg**, which shares this
     * database but is not composed into every product (the customer portal ships
     * support-tickets without it). A missing table is therefore a normal state,
     * not a fault — hence the catch. Matching is on the full address only: a
     * domain match would hand a shared mailbox at a freemail provider to whoever
     * registered that domain first.
     */
    public function findCompanyIdByEmail(string $email): ?int
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return null;
        }
        try {
            $stmt = $this->pdo->prepare('SELECT id FROM company WHERE LOWER(email) = :e LIMIT 1');
            $stmt->execute([':e' => $email]);
            $id = $stmt->fetchColumn();
            return $id === false ? null : (int) $id;
        } catch (\Throwable) {
            return null;
        }
    }

    // --- email ingest (threading) ---------------------------------------------

    /** True when a mail's Message-ID was already stored (dedupe re-delivery). */
    public function emailMessageIdSeen(string $messageId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT EXISTS(
                SELECT 1 FROM ticket WHERE email_message_id = :m
                UNION SELECT 1 FROM ticket_comment WHERE email_message_id = :m2
             )'
        );
        $stmt->execute([':m' => $messageId, ':m2' => $messageId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Find a ticket the email sender owns (by from_email) to thread a reply onto:
     * a `#<id>` subject marker on their ticket, else a References/In-Reply-To hit
     * on a stored Message-ID (ticket or comment) whose ticket is theirs. Without a
     * customer directory this only threads email/contact tickets that carry
     * `from_email`; portal tickets can't be email-matched yet.
     *
     * @param list<string> $references
     */
    public function findEmailReplyTicket(?int $subjectTicketId, array $references, string $fromEmail): ?int
    {
        if ($subjectTicketId !== null) {
            $stmt = $this->pdo->prepare('SELECT id FROM ticket WHERE id = :id AND from_email = :e LIMIT 1');
            $stmt->execute([':id' => $subjectTicketId, ':e' => $fromEmail]);
            $id = $stmt->fetchColumn();
            if ($id !== false) {
                return (int) $id;
            }
        }
        foreach ($references as $ref) {
            $stmt = $this->pdo->prepare(
                'SELECT t.id FROM ticket t WHERE t.email_message_id = :m AND t.from_email = :e LIMIT 1'
            );
            $stmt->execute([':m' => $ref, ':e' => $fromEmail]);
            $id = $stmt->fetchColumn();
            if ($id !== false) {
                return (int) $id;
            }
            $stmt = $this->pdo->prepare(
                'SELECT t.id FROM ticket_comment cm JOIN ticket t ON t.id = cm.ticket_id
                 WHERE cm.email_message_id = :m AND t.from_email = :e LIMIT 1'
            );
            $stmt->execute([':m' => $ref, ':e' => $fromEmail]);
            $id = $stmt->fetchColumn();
            if ($id !== false) {
                return (int) $id;
            }
        }
        return null;
    }

    /** Append an inbound-email reply as a customer comment carrying its Message-ID. */
    public function addEmailComment(int $ticketId, string $body, string $messageId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ticket_comment (ticket_id, author_type, author_user_id, body, is_internal, email_message_id)
             VALUES (:tid, \'customer\', NULL, :body, 0, :mid)'
        );
        $stmt->execute([':tid' => $ticketId, ':body' => $body, ':mid' => $messageId]);
        $this->touch($ticketId);
        return (int) $this->pdo->lastInsertId();
    }

    public function addComment(
        int $ticketId,
        string $authorType,
        ?int $userId,
        string $body,
        bool $isInternal,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ticket_comment (ticket_id, author_type, author_user_id, body, is_internal)
             VALUES (:tid, :atype, :uid, :body, :internal)'
        );
        $stmt->execute([
            ':tid' => $ticketId,
            ':atype' => $authorType,
            ':uid' => $userId,
            ':body' => $body,
            ':internal' => $isInternal ? 1 : 0,
        ]);
        $this->touch($ticketId);
        return (int) $this->pdo->lastInsertId();
    }

    /** Clear the "waiting for customer" prompt (a customer reply resolves it). */
    public function clearCustomerAction(int $ticketId): void
    {
        $this->pdo->prepare('UPDATE ticket SET customer_action_required = 0 WHERE id = :id')
            ->execute([':id' => $ticketId]);
    }

    /**
     * Admin triage update. Only the provided fields change.
     *
     * @param array<string,mixed> $fields status_id/assignee_user_id/priority/type/
     *                                     customer_action_required/customer_action_note
     */
    public function updateAdmin(int $id, array $fields): void
    {
        $allowed = ['status_id', 'assignee_user_id', 'priority', 'type', 'customer_action_required', 'customer_action_note'];
        $sets = [];
        $params = [':id' => $id];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $fields)) {
                $sets[] = "{$col} = :{$col}";
                $params[":{$col}"] = $fields[$col];
            }
        }
        if ($sets === []) {
            return;
        }
        $this->pdo->prepare('UPDATE ticket SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
    }

    private function touch(int $ticketId): void
    {
        $this->pdo->prepare('UPDATE ticket SET updated_at = NOW() WHERE id = :id')
            ->execute([':id' => $ticketId]);
    }

    // --- status registry CRUD (admin) -----------------------------------------

    public function statusCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM ticket_status')->fetchColumn();
    }

    public function statusInUse(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM ticket WHERE status_id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        return $stmt->fetchColumn() !== false;
    }

    public function statusExists(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM ticket_status WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        return $stmt->fetchColumn() !== false;
    }

    /** @param array{name:string,color:string,sort_order:int,visible_to_customer:bool,is_terminal:bool,is_default:bool} $d */
    public function createStatus(array $d): int
    {
        if ($d['is_default']) {
            $this->pdo->exec('UPDATE ticket_status SET is_default = 0');
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO ticket_status (name, color, sort_order, visible_to_customer, is_terminal, is_default)
             VALUES (:name, :color, :sort, :vis, :term, :def)'
        );
        $stmt->execute([
            ':name' => $d['name'], ':color' => $d['color'], ':sort' => $d['sort_order'],
            ':vis' => $d['visible_to_customer'] ? 1 : 0, ':term' => $d['is_terminal'] ? 1 : 0,
            ':def' => $d['is_default'] ? 1 : 0,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array{name:string,color:string,sort_order:int,visible_to_customer:bool,is_terminal:bool,is_default:bool} $d */
    public function updateStatus(int $id, array $d): void
    {
        if ($d['is_default']) {
            $this->pdo->exec('UPDATE ticket_status SET is_default = 0');
        }
        $stmt = $this->pdo->prepare(
            'UPDATE ticket_status SET name = :name, color = :color, sort_order = :sort,
                    visible_to_customer = :vis, is_terminal = :term, is_default = :def WHERE id = :id'
        );
        $stmt->execute([
            ':id' => $id, ':name' => $d['name'], ':color' => $d['color'], ':sort' => $d['sort_order'],
            ':vis' => $d['visible_to_customer'] ? 1 : 0, ':term' => $d['is_terminal'] ? 1 : 0,
            ':def' => $d['is_default'] ? 1 : 0,
        ]);
    }

    public function deleteStatus(int $id): void
    {
        $this->pdo->prepare('DELETE FROM ticket_status WHERE id = :id')->execute([':id' => $id]);
    }

    // --- attachments ----------------------------------------------------------

    /** @param array{ticket_id:int,comment_id:?int,filename:string,storage_path:string,mime_type:string,size_bytes:int,uploaded_by_type:string} $a */
    public function addAttachment(array $a): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ticket_attachment (ticket_id, comment_id, filename, storage_path, mime_type, size_bytes, uploaded_by_type)
             VALUES (:tid, :cid, :name, :path, :mime, :size, :by)'
        );
        $stmt->execute([
            ':tid' => $a['ticket_id'], ':cid' => $a['comment_id'], ':name' => $a['filename'],
            ':path' => $a['storage_path'], ':mime' => $a['mime_type'], ':size' => $a['size_bytes'],
            ':by' => $a['uploaded_by_type'],
        ]);
        $this->touch($a['ticket_id']);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<array<string,mixed>> */
    public function attachments(int $ticketId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, filename, mime_type, size_bytes, uploaded_by_type, created_at
             FROM ticket_attachment WHERE ticket_id = :tid ORDER BY created_at, id'
        );
        $stmt->execute([':tid' => $ticketId]);
        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function findAttachment(int $ticketId, int $attachmentId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, ticket_id, filename, storage_path, mime_type, size_bytes
             FROM ticket_attachment WHERE id = :aid AND ticket_id = :tid'
        );
        $stmt->execute([':aid' => $attachmentId, ':tid' => $ticketId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }
}
