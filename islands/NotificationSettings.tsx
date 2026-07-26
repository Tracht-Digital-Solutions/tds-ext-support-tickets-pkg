import { useEffect, useState } from "react";
import { Spinner } from "@tracht-digital-solutions/tds-shared/components";

type Toggles = Record<string, boolean>;

const LABELS: Record<string, string> = {
  notify_admin_on_new: "Admin bei neuem Ticket benachrichtigen",
  notify_customer_on_status: "Kunde bei Statusänderung benachrichtigen",
  notify_customer_on_reply: "Kunde bei Antwort benachrichtigen",
};

const api = (path: string, init?: RequestInit) =>
  fetch(path, { credentials: "include", ...init });

/**
 * Admin notification toggles (checkpoint-4). Reads/writes
 * /admin/ticket-settings. Emails also require the core Mailer (MAIL_DSN) + a
 * recipient, so a toggle on with no SMTP simply no-ops.
 */
export default function NotificationSettings() {
  const [toggles, setToggles] = useState<Toggles | null>(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    api("/admin/ticket-settings")
      .then((r) => (r.ok ? r.json() : { settings: {} }))
      .then((d) => setToggles(d.settings ?? {}))
      .catch(() => setToggles({}));
  }, []);

  const save = async (next: Toggles) => {
    setToggles(next);
    setSaving(true);
    await api("/admin/ticket-settings", {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(next),
    });
    setSaving(false);
  };

  if (toggles === null) return <p role="status"><Spinner /></p>;

  return (
    <fieldset className="ticket-settings" disabled={saving}>
      {Object.keys(LABELS).map((key) => (
        <label key={key} className="ticket-settings__row">
          <input
            type="checkbox"
            checked={Boolean(toggles[key])}
            onChange={(e) => save({ ...toggles, [key]: e.target.checked })}
          />
          {LABELS[key]}
        </label>
      ))}
    </fieldset>
  );
}
