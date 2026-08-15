// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import ImapSettings from "./ImapSettings";
import { TOAST_EVENT } from "@tracht-digital-solutions/tds-shared/toast";

/**
 * Path + query of a request. The island calls an ABSOLUTE URL (via `apiFetch`);
 * a relative one would hit the product's own static host and come back as
 * SPA-fallback HTML with a 200.
 */
const pathOf = (url: string) => String(url).replace(/^https?:\/\/[^/]+/i, "");

/**
 * The inbound-mailbox settings section.
 *
 * What is worth pinning here is the part that has bitten this platform before:
 * the form edits the settings NAMESPACE, but the state it reports comes from
 * the effective-config route — on a host configured through `IMAP_*` those two
 * disagree, and only the second one knows the ingest works. Plus the two
 * actions, whose failures are diagnostic text that has to stay on screen.
 */

let calls: Array<{ url: string; method: string; body: unknown }> = [];
let settingsResponse: { status: number; body: unknown };
let statusResponse: { status: number; body: unknown };
let actionResponse: { status: number; body: unknown };

const STORED = {
  settings: [
    { key: "imap_host", secret: false, value: "imap.panel.net" },
    { key: "imap_user", secret: false, value: "support@panel.net" },
    { key: "imap_password", secret: true, configured: true, last4: "3210" },
    { key: "ingest_mode", secret: false, value: "allowlist" },
    { key: "ingest_allowlist", secret: false, value: "chef@kunde.de" },
  ],
};

const LIVE = {
  configured: true,
  polling: true,
  source: "db",
  host: "imap.panel.net",
  port: 993,
  security: "ssl",
  user: "support@panel.net",
  folder: "INBOX",
  password_configured: true,
  mode: "allowlist",
  allowlist: ["chef@kunde.de"],
  match_company: true,
  token_configured: false,
};

const toasts: Array<{ variant: string; message: string }> = [];
const onToast = (e: Event) => toasts.push((e as CustomEvent).detail);

beforeEach(() => {
  calls = [];
  toasts.length = 0;
  settingsResponse = { status: 200, body: STORED };
  statusResponse = { status: 200, body: LIVE };
  actionResponse = { status: 200, body: { ok: true } };
  window.addEventListener(TOAST_EVENT, onToast);
  vi.stubGlobal(
    "fetch",
    vi.fn(async (url: string, init?: RequestInit) => {
      const method = init?.method ?? "GET";
      const path = pathOf(url);
      calls.push({ url: String(url), method, body: typeof init?.body === "string" ? JSON.parse(init.body) : undefined });
      const pick = path.startsWith("/admin/settings/") && method === "GET"
        ? settingsResponse
        : path === "/admin/tickets/imap"
          ? statusResponse
          : actionResponse;
      return {
        ok: pick.status < 300,
        status: pick.status,
        json: async () => pick.body,
      } as Response;
    }),
  );
});

afterEach(() => {
  window.removeEventListener(TOAST_EVENT, onToast);
  cleanup();
  vi.unstubAllGlobals();
});

describe("ImapSettings", () => {
  it("calls the API on an absolute origin, not the product's own host", async () => {
    render(<ImapSettings />);
    await waitFor(() => expect(calls.length).toBeGreaterThan(0));
    // A path-based assertion cannot catch a relative fetch — assert the host.
    for (const call of calls) {
      expect(call.url).toMatch(/^https?:\/\//i);
    }
  });

  it("shows the stored mailbox and the effective state", async () => {
    render(<ImapSettings />);
    expect(await screen.findByDisplayValue("imap.panel.net")).toBeTruthy();
    expect(screen.getByText("Aktiv über diese Einstellungen")).toBeTruthy();
    // The masked secret is reported as configured without ever carrying a value.
    expect(screen.getByText(/hinterlegt \(…3210\)/)).toBeTruthy();
  });

  it("says when the mailbox comes from the host's .env, not from the form", async () => {
    // The failure this prevents: an empty-looking form over a working ingest,
    // "fixed" by overwriting a mailbox that was never broken.
    settingsResponse = { status: 200, body: { settings: [] } };
    statusResponse = { status: 200, body: { ...LIVE, source: "env" } };
    render(<ImapSettings />);
    expect(await screen.findByText(/IMAP_\* aus der \.env/)).toBeTruthy();
  });

  it("reports an unconfigured mailbox and disables both actions", async () => {
    settingsResponse = { status: 200, body: { settings: [] } };
    statusResponse = { status: 200, body: { ...LIVE, configured: false, polling: false, source: "none" } };
    render(<ImapSettings />);
    expect(await screen.findByText("Kein Postfach eingerichtet")).toBeTruthy();
    expect(screen.getByRole("button", { name: "Verbindung testen" }).hasAttribute("disabled")).toBe(true);
    expect(screen.getByRole("button", { name: "Jetzt abrufen" }).hasAttribute("disabled")).toBe(true);
  });

  it("sends every declared key on save and clears the secret fields", async () => {
    render(<ImapSettings />);
    await screen.findByDisplayValue("imap.panel.net");
    await userEvent.click(screen.getByRole("button", { name: "Speichern" }));

    const put = await waitFor(() => {
      const found = calls.find((c) => c.method === "PUT");
      expect(found).toBeTruthy();
      return found!;
    });
    const keys = (put.body as { settings: Array<{ key: string }> }).settings.map((s) => s.key);
    expect(keys).toEqual([
      "imap_host",
      "imap_port",
      "imap_security",
      "imap_user",
      "imap_password",
      "imap_folder",
      "ingest_mode",
      "ingest_allowlist",
      "ingest_match_company",
      "ingest_token",
    ]);
    // A blank secret means "keep" on the backend, so it must go out blank
    // rather than echoing the mask back as a new password.
    const secrets = (put.body as { settings: Array<{ key: string; value: string }> }).settings
      .filter((s) => s.key === "imap_password" || s.key === "ingest_token");
    expect(secrets.every((s) => s.value === "")).toBe(true);
  });

  it("keeps a failed connection test on screen instead of toasting it away", async () => {
    actionResponse = { status: 200, body: { ok: false, error: "AUTHENTICATE failed" } };
    render(<ImapSettings />);
    await screen.findByDisplayValue("imap.panel.net");
    await userEvent.click(screen.getByRole("button", { name: "Verbindung testen" }));
    // The IMAP server's reply is what tells a wrong password from a wrong port.
    expect(await screen.findByText(/AUTHENTICATE failed/)).toBeTruthy();
  });

  it("distinguishes an empty inbox from a mailbox that was never contacted", async () => {
    actionResponse = {
      status: 200,
      body: { processed: 0, created: 0, appended: 0, skipped: 0, mode: "off", polled: false },
    };
    render(<ImapSettings />);
    await screen.findByDisplayValue("imap.panel.net");
    await userEvent.click(screen.getByRole("button", { name: "Jetzt abrufen" }));
    expect(await screen.findByText(/Die Annahme steht auf/)).toBeTruthy();
    expect(toasts).toHaveLength(0);
  });

  it("reports what a real poll did", async () => {
    actionResponse = {
      status: 200,
      body: { processed: 3, created: 1, appended: 1, skipped: 1, mode: "all", polled: true },
    };
    render(<ImapSettings />);
    await screen.findByDisplayValue("imap.panel.net");
    await userEvent.click(screen.getByRole("button", { name: "Jetzt abrufen" }));
    await waitFor(() => expect(toasts).toHaveLength(1));
    expect(toasts[0].message).toContain("3 Mail(s) gelesen");
    expect(toasts[0].variant).toBe("success");
  });

  it("carries the HTTP status when a poll fails outright", async () => {
    // "Session expired" and "service down" look identical without it.
    actionResponse = { status: 500, body: { error: "boom" } };
    render(<ImapSettings />);
    await screen.findByDisplayValue("imap.panel.net");
    await userEvent.click(screen.getByRole("button", { name: "Jetzt abrufen" }));
    expect(await screen.findByText(/HTTP 500/)).toBeTruthy();
  });

  it("shows the allowlist field only for the allowlist rule", async () => {
    render(<ImapSettings />);
    expect(await screen.findByDisplayValue("chef@kunde.de")).toBeTruthy();
    await userEvent.selectOptions(
      screen.getByRole("combobox", { name: /Regel/ }),
      "reply",
    );
    expect(screen.queryByDisplayValue("chef@kunde.de")).toBeNull();
  });

  it("reports a non-admin instead of an empty form", async () => {
    settingsResponse = { status: 403, body: {} };
    render(<ImapSettings />);
    expect(await screen.findByText("Nur für Administratoren.")).toBeTruthy();
  });
});
