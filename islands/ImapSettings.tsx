import { useEffect, useState } from "react";
import { FormAlert, Spinner, toast } from "@tracht-digital-solutions/tds-shared/components";
import { apiFetch } from "@tracht-digital-solutions/tds-shared/api";

interface Masked {
  key: string;
  secret: boolean;
  configured?: boolean;
  last4?: string | null;
  value?: string;
}

interface ImapStatus {
  configured: boolean;
  polling: boolean;
  source: "db" | "env" | "none";
  host: string;
  port: number;
  security: string;
  user: string;
  folder: string;
  password_configured: boolean;
  mode: string;
  allowlist: string[];
  match_company: boolean;
  token_configured: boolean;
}

interface PollResult {
  processed: number;
  created: number;
  appended: number;
  skipped: number;
  mode: string;
  polled: boolean;
}

const api = apiFetch;
const NS = "/admin/settings/support-tickets";
const STATUS = "/admin/tickets/imap";
const TEST = "/admin/tickets/imap-test";
const POLL = "/admin/tickets/ingest";

/** Coded defaults, mirrored from the API's `ImapConfig`. */
const DEFAULTS = { port: "993", security: "ssl", folder: "INBOX", mode: "reply" } as const;

const MODES: Array<{ value: string; label: string; hint: string }> = [
  { value: "off", label: "Aus", hint: "Das Postfach wird nicht abgerufen." },
  {
    value: "reply",
    label: "Nur Antworten auf bestehende Tickets",
    hint: "Antworten landen am passenden Ticket. Mails ohne Bezug werden verworfen.",
  },
  {
    value: "allowlist",
    label: "Neue Tickets nur von erlaubten Absendern",
    hint: "Zusätzlich zu Antworten: Mails der unten gelisteten Adressen und Domains öffnen ein neues Ticket.",
  },
  {
    value: "all",
    label: "Neue Tickets von allen Absendern",
    hint: "Jede unbekannte Mail wird zu einem Ticket — auch Spam. Nur für ein Postfach sinnvoll, das ausschließlich Support-Mails empfängt.",
  },
];

/**
 * *E-Mail-Eingang (IMAP)* — the mailbox the support system reads, and the rule
 * that decides what an incoming mail becomes.
 *
 * Two reads, because they answer different questions: the settings namespace
 * holds what is *stored* (and is what this form edits), while
 * `GET /admin/tickets/imap` reports what the ingest actually *uses* — including
 * a mailbox that still comes from the host's `IMAP_*`. Showing only the former
 * would present an empty form on a host whose ingest works, and the first "fix"
 * would overwrite a working mailbox.
 *
 * The password and the ingest token are secrets: they come back masked and a
 * blank field on save keeps the stored value, so neither round-trips through
 * the browser.
 *
 * Two actions sit below the form because saving is neither connecting nor
 * fetching: IMAP fails on things no form can validate (wrong port, refused
 * login, a folder that does not exist), and the poll is the whole point — on a
 * host with no cron, "Jetzt abrufen" is how mail becomes tickets at all until
 * an external scheduler calls the token-gated route.
 */
export default function ImapSettings() {
  const [loaded, setLoaded] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [status, setStatus] = useState<ImapStatus | null>(null);
  const [host, setHost] = useState("");
  const [port, setPort] = useState<string>(DEFAULTS.port);
  const [security, setSecurity] = useState<string>(DEFAULTS.security);
  const [user, setUser] = useState("");
  const [password, setPassword] = useState("");
  const [passwordState, setPasswordState] = useState<Masked | null>(null);
  const [folder, setFolder] = useState<string>(DEFAULTS.folder);
  const [mode, setMode] = useState<string>(DEFAULTS.mode);
  const [allowlist, setAllowlist] = useState("");
  const [matchCompany, setMatchCompany] = useState(true);
  const [token, setToken] = useState("");
  const [tokenState, setTokenState] = useState<Masked | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [testing, setTesting] = useState(false);
  const [polling, setPolling] = useState(false);

  const load = async () => {
    try {
      const [settingsRes, statusRes] = await Promise.all([api(NS), api(STATUS)]);
      if (!settingsRes.ok) {
        setError(
          settingsRes.status === 401 || settingsRes.status === 403
            ? "Nur für Administratoren."
            : `Einstellungen konnten nicht geladen werden (HTTP ${settingsRes.status}).`,
        );
        setLoaded(true);
        return;
      }
      const data = (await settingsRes.json()) as { settings?: Masked[] };
      const map = new Map<string, Masked>((data.settings ?? []).map((s) => [s.key, s]));
      setHost(map.get("imap_host")?.value ?? "");
      setPort(map.get("imap_port")?.value || DEFAULTS.port);
      setSecurity(map.get("imap_security")?.value || DEFAULTS.security);
      setUser(map.get("imap_user")?.value ?? "");
      setPasswordState(map.get("imap_password") ?? null);
      setFolder(map.get("imap_folder")?.value || DEFAULTS.folder);
      setMode(map.get("ingest_mode")?.value || DEFAULTS.mode);
      setAllowlist(map.get("ingest_allowlist")?.value ?? "");
      setMatchCompany(map.get("ingest_match_company")?.value !== "0");
      setTokenState(map.get("ingest_token") ?? null);
      setStatus(statusRes.ok ? ((await statusRes.json()) as ImapStatus) : null);
      setError(null);
    } catch {
      setError("Einstellungen konnten nicht geladen werden — die API ist nicht erreichbar.");
    } finally {
      setLoaded(true);
    }
  };

  useEffect(() => {
    void load();
  }, []);

  const save = async () => {
    setBusy(true);
    try {
      const res = await api(NS, {
        method: "PUT",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          settings: [
            { key: "imap_host", secret: false, value: host.trim() },
            { key: "imap_port", secret: false, value: port.trim() },
            { key: "imap_security", secret: false, value: security },
            { key: "imap_user", secret: false, value: user.trim() },
            { key: "imap_password", secret: true, value: password },
            { key: "imap_folder", secret: false, value: folder.trim() },
            { key: "ingest_mode", secret: false, value: mode },
            { key: "ingest_allowlist", secret: false, value: allowlist.trim() },
            { key: "ingest_match_company", secret: false, value: matchCompany ? "1" : "0" },
            { key: "ingest_token", secret: true, value: token },
          ],
        }),
      });
      if (res.ok) {
        setPassword("");
        setToken("");
        toast.success("Gespeichert.");
        void load();
      } else {
        toast.danger(`Speichern fehlgeschlagen (HTTP ${res.status}).`);
      }
    } catch {
      toast.danger("Speichern fehlgeschlagen — die API ist nicht erreichbar.");
    } finally {
      setBusy(false);
    }
  };

  const test = async () => {
    setTesting(true);
    setActionError(null);
    try {
      const res = await api(TEST);
      const data = (await res.json().catch(() => null)) as { ok?: boolean; error?: string } | null;
      if (res.ok && data?.ok) {
        toast.success("Verbindung steht.");
      } else {
        // The IMAP server's own reply is diagnostic text to read, not a passing
        // notice — so it stays in flow instead of vanishing with a toast.
        setActionError(
          data?.error
            ? `Verbindung fehlgeschlagen (HTTP ${res.status}): ${data.error}`
            : `Verbindung fehlgeschlagen (HTTP ${res.status}).`,
        );
      }
    } catch {
      setActionError("Verbindung fehlgeschlagen — die API ist nicht erreichbar.");
    } finally {
      setTesting(false);
    }
  };

  const pollNow = async () => {
    setPolling(true);
    setActionError(null);
    try {
      const res = await api(POLL, { method: "POST" });
      const data = (await res.json().catch(() => null)) as (PollResult & { error?: string }) | null;
      if (!res.ok || !data) {
        setActionError(
          data?.error
            ? `Abruf fehlgeschlagen (HTTP ${res.status}): ${data.error}`
            : `Abruf fehlgeschlagen (HTTP ${res.status}).`,
        );
        return;
      }
      if (!data.polled) {
        // An all-zero report from a mailbox that was never contacted reads like
        // an empty inbox; say which of the two it was.
        setActionError(
          data.mode === "off"
            ? "Kein Abruf: Die Annahme steht auf „Aus“."
            : "Kein Abruf: Es ist kein Postfach hinterlegt.",
        );
        return;
      }
      toast.success(
        `${data.processed} Mail(s) gelesen — ${data.created} neu, ${data.appended} angehängt, ${data.skipped} übersprungen.`,
      );
      window.dispatchEvent(new CustomEvent("tds:notification"));
    } catch {
      setActionError("Abruf fehlgeschlagen — die API ist nicht erreichbar.");
    } finally {
      setPolling(false);
    }
  };

  const secretHint = (s: Masked | null, verb: string) =>
    s?.configured ? `hinterlegt (…${s.last4 ?? "????"})` : verb;

  const sourceLabel = (): { text: string; variant: "success" | "warning" | "danger" } => {
    if (!status) return { text: "Status unbekannt", variant: "warning" };
    if (!status.configured) return { text: "Kein Postfach eingerichtet", variant: "danger" };
    if (!status.polling) return { text: "Postfach eingerichtet, Annahme aus", variant: "warning" };
    return status.source === "env"
      ? { text: "Aktiv über IMAP_* aus der .env des Hosts", variant: "warning" }
      : { text: "Aktiv über diese Einstellungen", variant: "success" };
  };

  if (!loaded) return <p><Spinner /></p>;

  const state = sourceLabel();
  const activeMode = MODES.find((m) => m.value === mode);

  return (
    <div className="tds-stack">
      <FormAlert message={error} />

      <p className="tds-row">
        <span className={`status-pill status-pill--${state.variant}`}>{state.text}</span>
        {status?.configured ? (
          <span className="marginalia">
            {status.user} @ {status.host}:{status.port} ({status.folder})
          </span>
        ) : null}
      </p>

      {status?.source === "env" ? (
        <p className="marginalia">
          Der Abruf läuft derzeit über <code>IMAP_*</code> aus der <code>.env</code> des Hosts.
          Sobald hier ein Postfach eingetragen und gespeichert ist, gilt diese Einstellung — die{" "}
          <code>.env</code> bleibt nur noch Rückfallebene.
        </p>
      ) : null}

      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <label className="block">
          <span className="text-sm">IMAP-Server</span>
          <input
            className="field-boxed"
            type="text"
            value={host}
            onChange={(e) => setHost(e.target.value)}
            placeholder="imap.example.net"
            autoComplete="off"
          />
        </label>
        <label className="block">
          <span className="text-sm">Port</span>
          <input
            className="field-boxed"
            type="number"
            min="1"
            max="65535"
            value={port}
            onChange={(e) => setPort(e.target.value)}
            placeholder={DEFAULTS.port}
          />
        </label>
        <label className="block">
          <span className="text-sm">Verschlüsselung</span>
          <select
            className="field-boxed"
            value={security}
            onChange={(e) => setSecurity(e.target.value)}
          >
            <option value="ssl">SSL/TLS (Port 993)</option>
            <option value="tls">STARTTLS (Port 143)</option>
            <option value="none">Keine</option>
          </select>
        </label>
        <label className="block">
          <span className="text-sm">Ordner</span>
          <input
            className="field-boxed"
            type="text"
            value={folder}
            onChange={(e) => setFolder(e.target.value)}
            placeholder={DEFAULTS.folder}
            autoComplete="off"
          />
        </label>
        <label className="block">
          <span className="text-sm">Benutzername</span>
          <input
            className="field-boxed"
            type="text"
            value={user}
            onChange={(e) => setUser(e.target.value)}
            placeholder="support@example.net"
            autoComplete="off"
          />
        </label>
        <label className="block">
          <span className="text-sm">
            Passwort <em className="opacity-60">({secretHint(passwordState, "nicht hinterlegt")})</em>
          </span>
          <input
            className="field-boxed"
            type="password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            placeholder="leer = bestehendes Passwort behalten"
            autoComplete="new-password"
          />
        </label>
      </div>

      <hr />

      <h3 className="text-sm">Annahme eingehender Mails</h3>

      <label className="block">
        <span className="text-sm">Regel</span>
        <select className="field-boxed" value={mode} onChange={(e) => setMode(e.target.value)}>
          {MODES.map((m) => (
            <option key={m.value} value={m.value}>
              {m.label}
            </option>
          ))}
        </select>
      </label>
      {activeMode ? <p className="marginalia">{activeMode.hint}</p> : null}

      {mode === "allowlist" ? (
        <label className="block">
          <span className="text-sm">Erlaubte Absender</span>
          <textarea
            className="field-boxed"
            rows={4}
            value={allowlist}
            onChange={(e) => setAllowlist(e.target.value)}
            placeholder={"chef@kunde.de\n@partner.de"}
          />
        </label>
      ) : null}
      {mode === "allowlist" ? (
        <p className="marginalia">
          Eine Adresse oder eine ganze Domain je Zeile (Komma geht auch). <code>@partner.de</code>{" "}
          und <code>partner.de</code> bedeuten dasselbe und schließen Subdomains ein.
        </p>
      ) : null}

      <label className="tds-toggle-row">
        <span>Absender einer bekannten Firma zuordnen</span>
        <input
          type="checkbox"
          checked={matchCompany}
          onChange={(e) => setMatchCompany(e.target.checked)}
        />
      </label>
      <p className="marginalia">
        Stimmt die Absenderadresse mit der E-Mail einer Firma im Firmenverzeichnis überein, wird das
        Ticket dieser Firma zugeordnet und ist damit auch in deren Portal sichtbar. Sonst bleibt es
        ein reines Verwaltungs-Ticket.
      </p>

      <label className="block">
        <span className="text-sm">
          Ingest-Token <em className="opacity-60">({secretHint(tokenState, "nicht gesetzt")})</em>
        </span>
        <input
          className="field-boxed"
          type="password"
          value={token}
          onChange={(e) => setToken(e.target.value)}
          placeholder="leer = bestehendes Token behalten"
          autoComplete="off"
        />
      </label>
      <p className="marginalia">
        Nur nötig, wenn ein externer Zeitplan (z. B. ein Cron-Dienst) den Abruf regelmäßig anstoßen
        soll: <code>POST /tickets/ingest?token=…</code>. Ohne Token ist diese Route abgeschaltet;
        „Jetzt abrufen" unten funktioniert davon unabhängig. Dasselbe Token schützt den
        Kontaktformular-Eingang.
      </p>

      <div className="tds-toolbar">
        <button type="button" className="btn btn-primary" onClick={() => void save()} disabled={busy}>
          {busy ? <Spinner size="sm" /> : "Speichern"}
        </button>
      </div>

      <hr />

      <FormAlert message={actionError} />
      <div className="tds-toolbar">
        <button
          type="button"
          className="btn btn-ghost"
          onClick={() => void test()}
          disabled={testing || !status?.configured}
        >
          {testing ? <Spinner size="sm" /> : "Verbindung testen"}
        </button>
        <button
          type="button"
          className="btn btn-ghost"
          onClick={() => void pollNow()}
          disabled={polling || !status?.configured}
        >
          {polling ? <Spinner size="sm" /> : "Jetzt abrufen"}
        </button>
      </div>
      <p className="marginalia">
        Beide Aktionen verwenden die <strong>gespeicherte</strong> Konfiguration — vorher speichern.
        Abgerufen werden ungelesene Mails (max. 50 je Durchgang); verarbeitete Mails werden als
        gelesen markiert.
      </p>
    </div>
  );
}
