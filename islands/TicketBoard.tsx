import { useEffect, useState } from "react";
import { Spinner, toast } from "@tracht-digital-solutions/tds-shared/components";
// `status_color` comes out of the `support_tickets_status` table — i.e. it is
// whatever an admin typed. Interpolating it straight into a class name was
// broken twice over: Tailwind cannot statically extract an interpolated class
// name, and an unrecognised value produced a `chip--<nonsense>` matching no
// rule at all, so the pill rendered with no colour. resolveChipVariant maps the
// known aliases (violet -> cat-violet, red -> danger, …) and falls back to
// `neutral`, so the class it returns always exists in primitives.css.
import { resolveChipVariant } from "@tracht-digital-solutions/tds-shared/design";
import { apiFetch } from "@tracht-digital-solutions/tds-shared/api";

interface TicketRow {
  id: number;
  subject: string;
  status_name: string;
  status_color: string;
  priority: string;
  customer_action_required: number | boolean;
}

interface Comment {
  id: number;
  author_type: "customer" | "owner";
  body: string;
  created_at: string;
}

interface Attachment {
  id: number;
  filename: string;
  size_bytes: number;
}

interface TicketDetail extends TicketRow {
  description: string;
  customer_action_note: string | null;
  comments: Comment[];
  attachments: Attachment[];
}

const api = apiFetch;

/**
 * Portal ticket board (checkpoint-2): the customer's list + detail + comment
 * thread + new-ticket form. Admin triage lives behind /admin/tickets (admin
 * product). Uses relative fetches with credentials; the shared api client +
 * skeletons are wired when the host chrome lands.
 */
export default function TicketBoard() {
  const [tickets, setTickets] = useState<TicketRow[] | null>(null);
  const [detail, setDetail] = useState<TicketDetail | null>(null);
  const [creating, setCreating] = useState(false);

  const loadList = () =>
    api("/tickets")
      .then((r) => (r.ok ? r.json() : { tickets: [] }))
      .then((d) => setTickets(d.tickets ?? []))
      .catch(() => setTickets([]));

  useEffect(() => {
    loadList();
  }, []);

  const openTicket = (id: number) =>
    api(`/tickets/${id}`)
      .then((r) => (r.ok ? r.json() : null))
      .then((d) => setDetail(d));

  if (detail) {
    return (
      <TicketDetailView
        ticket={detail}
        onBack={() => {
          setDetail(null);
          loadList();
        }}
        onReload={() => openTicket(detail.id)}
      />
    );
  }

  return (
    <div className="tds-stack">
      <div className="tds-toolbar">
        <button
          type="button"
          className={creating ? "btn btn-ghost" : "btn btn-primary"}
          onClick={() => setCreating((v) => !v)}
        >
          {creating ? "Abbrechen" : "Neues Ticket"}
        </button>
      </div>

      {creating ? (
        <NewTicketForm
          onCreated={() => {
            setCreating(false);
            loadList();
          }}
        />
      ) : null}

      {tickets === null ? (
        <p><Spinner /></p>
      ) : tickets.length === 0 ? (
        <p className="tds-empty">Keine Tickets vorhanden.</p>
      ) : (
        <ul className="tds-list">
          {tickets.map((t) => (
            <li key={t.id} className="tds-list__row">
              <button type="button" className="btn btn-ghost" onClick={() => openTicket(t.id)}>
                {t.subject}
              </button>
              <span className={`chip ${resolveChipVariant(t.status_color)}`}>{t.status_name}</span>
              {t.customer_action_required ? (
                <span className="chip chip--warning">Aktion erforderlich</span>
              ) : null}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function TicketDetailView({
  ticket,
  onBack,
  onReload,
}: {
  ticket: TicketDetail;
  onBack: () => void;
  onReload: () => void;
}) {
  const [reply, setReply] = useState("");
  const [sending, setSending] = useState(false);

  // Both mutations used to discard the response and clear the box regardless,
  // so a rejected reply looked exactly like a sent one — with the text gone.
  // The draft is now only cleared once the POST actually succeeded.
  const send = async () => {
    if (reply.trim() === "") return;
    setSending(true);
    try {
      const res = await api(`/tickets/${ticket.id}/comments`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ body: reply.trim() }),
      });
      if (res.ok) {
        setReply("");
        onReload();
      } else {
        toast.danger(`Antwort konnte nicht gesendet werden (HTTP ${res.status}).`);
      }
    } catch {
      toast.danger("Antwort konnte nicht gesendet werden — die API ist nicht erreichbar.");
    } finally {
      setSending(false);
    }
  };

  const upload = async (file: File) => {
    const form = new FormData();
    form.append("file", file);
    try {
      const res = await api(`/tickets/${ticket.id}/attachments`, { method: "POST", body: form });
      if (res.ok) {
        toast.success(`„${file.name}" hochgeladen.`);
        onReload();
      } else {
        toast.danger(`Upload fehlgeschlagen (HTTP ${res.status}).`);
      }
    } catch {
      toast.danger("Upload fehlgeschlagen — die API ist nicht erreichbar.");
    }
  };

  return (
    <article className="tds-stack">
      <button type="button" className="btn btn-ghost" onClick={onBack}>
        ← Zurück
      </button>
      <h2>{ticket.subject}</h2>
      <span className={`chip ${resolveChipVariant(ticket.status_color)}`}>{ticket.status_name}</span>
      {ticket.customer_action_required ? (
        <p className="ticket-detail__action">
          <strong>Aktion erforderlich:</strong> {ticket.customer_action_note ?? "Bitte antworten Sie."}
        </p>
      ) : null}
      <p className="ticket-detail__description">{ticket.description}</p>

      {ticket.attachments.length > 0 ? (
        <ul className="ticket-attachments">
          {ticket.attachments.map((a) => (
            <li key={a.id}>
              <a href={`/tickets/${ticket.id}/attachments/${a.id}`} download>
                {a.filename}
              </a>
            </li>
          ))}
        </ul>
      ) : null}

      <ol className="tds-thread">
        {ticket.comments.map((c) => (
          // `--own` right-aligns the bubble, `--other` left-aligns it. The side
          // is picked from the same viewpoint the author label already assumes
          // below: the customer is the reader ("Sie"), support is the
          // counterpart. Mapped explicitly rather than interpolating
          // `--${author_type}`, which would produce a class that matches no rule
          // (the same trap the DB-driven status colour fell into).
          <li
            key={c.id}
            className={`tds-thread__item ${
              c.author_type === "owner" ? "tds-thread__item--other" : "tds-thread__item--own"
            }`}
          >
            <span className="tds-thread__author">
              {c.author_type === "owner" ? "Support" : "Sie"}
            </span>
            <p>{c.body}</p>
          </li>
        ))}
      </ol>

      <div className="tds-compose">
        <textarea
          className="field-boxed"
          value={reply}
          onChange={(e) => setReply(e.target.value)}
          placeholder="Antwort schreiben …"
          rows={3}
        />
        <button
          type="button"
          className="btn btn-primary"
          onClick={send}
          disabled={sending || reply.trim() === ""}
          aria-busy={sending}
        >
          {sending ? <Spinner size="sm" /> : null}
          Senden
        </button>
        <label className="ticket-reply__attach">
          Datei anhängen
          <input
            type="file"
            onChange={(e) => {
              const f = e.target.files?.[0];
              if (f) void upload(f);
              e.target.value = "";
            }}
          />
        </label>
      </div>
    </article>
  );
}

function NewTicketForm({ onCreated }: { onCreated: () => void }) {
  const [subject, setSubject] = useState("");
  const [description, setDescription] = useState("");
  const [type, setType] = useState("question");
  const [priority, setPriority] = useState("normal");
  const [saving, setSaving] = useState(false);

  const submit = async () => {
    if (subject.trim() === "" || description.trim() === "") return;
    setSaving(true);
    try {
      const res = await api("/tickets", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ subject, description, type, priority }),
      });
      if (res.ok) {
        toast.success("Ticket erstellt.");
        onCreated();
      } else {
        // Was `if (res.ok) onCreated()` with no else: a rejected ticket left
        // the form sitting there as if the button had never been pressed.
        toast.danger(`Ticket konnte nicht erstellt werden (HTTP ${res.status}).`);
      }
    } catch {
      toast.danger("Ticket konnte nicht erstellt werden — die API ist nicht erreichbar.");
    } finally {
      setSaving(false);
    }
  };

  return (
    <form
      className="tds-card tds-stack"
      onSubmit={(e) => {
        e.preventDefault();
        submit();
      }}
    >
      <input
        className="field-boxed"
        value={subject}
        onChange={(e) => setSubject(e.target.value)}
        placeholder="Betreff"
        required
      />
      <textarea
        className="field-boxed"
        value={description}
        onChange={(e) => setDescription(e.target.value)}
        placeholder="Beschreibung"
        rows={4}
        required
      />
      <div className="tds-row">
        <select
          className="field-boxed"
          value={type}
          onChange={(e) => setType(e.target.value)}
          aria-label="Typ"
        >
          <option value="question">Frage</option>
          <option value="bug">Fehler</option>
          <option value="feature">Wunsch</option>
          <option value="other">Sonstiges</option>
        </select>
        <select
          className="field-boxed"
          value={priority}
          onChange={(e) => setPriority(e.target.value)}
          aria-label="Priorität"
        >
          <option value="low">Niedrig</option>
          <option value="normal">Normal</option>
          <option value="high">Hoch</option>
          <option value="urgent">Dringend</option>
        </select>
      </div>
      <button type="submit" className="btn btn-primary" disabled={saving} aria-busy={saving}>
        {saving ? <Spinner size="sm" /> : null}
        Ticket erstellen
      </button>
    </form>
  );
}
