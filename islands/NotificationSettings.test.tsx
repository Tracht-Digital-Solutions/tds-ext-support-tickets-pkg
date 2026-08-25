
/**
 * Path + query of a request. The island calls an ABSOLUTE URL now (via
 * `apiFetch`); a relative one would hit the product's own static host and come
 * back as SPA-fallback HTML with a 200. Matching on the path keeps the route
 * matchers below anchored.
 */
const pathOf = (url: string) => String(url).replace(/^https?:\/\/[^/]+/i, "");
// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { primeRuntimeConfig } from "@tracht-digital-solutions/tds-shared/api";
import { cleanup, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import NotificationSettings from "./NotificationSettings";
import { TOAST_EVENT } from "@tracht-digital-solutions/tds-shared/toast";

/**
 * The three admin notification toggles, backed by `/admin/ticket-settings`.
 *
 * The behaviour worth pinning is that a save sends the WHOLE toggle map, not
 * just the one that changed — the endpoint replaces the stored object, so a
 * partial PUT would silently switch the other two off.
 */

let calls: Array<{ url: string; method: string; body: unknown }> = [];
let getResponse: { status: number; body: unknown } = { status: 200, body: { settings: {} } };

const KEYS = ["notify_admin_on_new", "notify_customer_on_status", "notify_customer_on_reply"] as const;

beforeEach(() => {
  calls = [];
  getResponse = { status: 200, body: { settings: {} } };
  vi.stubGlobal(
    "fetch",
    vi.fn(async (url: string, init?: RequestInit) => {
      const method = init?.method ?? "GET";
      calls.push({ url, method, body: typeof init?.body === "string" ? JSON.parse(init.body) : undefined });
      if (method === "PUT") return { ok: true, status: 200, json: async () => ({}) } as Response;
      return {
        ok: getResponse.status < 300,
        status: getResponse.status,
        json: async () => getResponse.body,
      } as Response;
    }),
  );
});

afterEach(() => cleanup());

const user = () => userEvent.setup({ delay: null });

async function renderSettings() {
  render(<NotificationSettings />);
  await waitFor(() => expect(calls.some((c) => c.method === "GET")).toBe(true));
  await screen.findByRole("checkbox", { name: /Admin bei neuem Ticket/ });
  return user();
}

const put = () => calls.find((c) => c.method === "PUT");

// apiFetch consults the host-side runtime config (/tds-runtime.json) before it
// resolves a URL, so without this the first entry in fetch.mock.calls is that
// probe rather than the endpoint under test. The panel products never ship the
// file — they render <meta name="tds-api-base"> instead — so "absent" is also
// what actually happens in production.
beforeEach(() => primeRuntimeConfig(null));

describe("loading", () => {
  it("reads the ticket settings with credentials", async () => {
    await renderSettings();
    const fetchMock = fetch as unknown as ReturnType<typeof vi.fn>;
    expect(pathOf(fetchMock.mock.calls[0]![0] as string)).toBe("/admin/ticket-settings");
    // Absolute, on the API host. Every other assertion here matches the PATH,
    // which a relative fetch satisfies too — so this is the one that fails if
    // the call ever goes back to the product's own origin (whose SPA fallback
    // answers 200 + HTML and turns into a silent empty state).
    expect(String(fetchMock.mock.calls[0]![0]).startsWith("https://api.tracht-digital.de/")).toBe(true);
    expect(fetchMock.mock.calls[0]![1]).toMatchObject({ credentials: "include" });
  });

  it("renders a labelled checkbox for each notification", async () => {
    await renderSettings();
    expect(screen.getByRole("checkbox", { name: /Admin bei neuem Ticket/ })).toBeTruthy();
    expect(screen.getByRole("checkbox", { name: /Kunde bei Statusänderung/ })).toBeTruthy();
    expect(screen.getByRole("checkbox", { name: /Kunde bei Antwort/ })).toBeTruthy();
  });

  it("reflects the stored state", async () => {
    getResponse = { status: 200, body: { settings: { notify_admin_on_new: true } } };
    await renderSettings();
    expect((screen.getByRole("checkbox", { name: /Admin bei neuem Ticket/ }) as HTMLInputElement).checked).toBe(true);
    expect((screen.getByRole("checkbox", { name: /Kunde bei Antwort/ }) as HTMLInputElement).checked).toBe(false);
  });

  it("defaults every toggle to off when nothing is stored", async () => {
    await renderSettings();
    for (const box of screen.getAllByRole("checkbox")) {
      expect((box as HTMLInputElement).checked).toBe(false);
    }
  });

  it("leaves the loading state even when the request fails", async () => {
    // Otherwise the settings section renders a permanent spinner.
    getResponse = { status: 500, body: {} };
    render(<NotificationSettings />);
    expect(await screen.findByRole("checkbox", { name: /Admin bei neuem Ticket/ })).toBeTruthy();
  });

  it("leaves the loading state when fetch rejects", async () => {
    vi.stubGlobal("fetch", vi.fn(async () => { throw new TypeError("offline"); }));
    render(<NotificationSettings />);
    expect(await screen.findByRole("checkbox", { name: /Admin bei neuem Ticket/ })).toBeTruthy();
  });

  it("tolerates a response with no settings field", async () => {
    getResponse = { status: 200, body: {} };
    await renderSettings();
    expect(screen.getAllByRole("checkbox")).toHaveLength(3);
  });

  it("ignores settings carried by a NON-OK response", async () => {
    // This endpoint is admin-only: a 403 body must never populate the UI, or
    // a non-admin would see the real notification state. Dropping the ok-check
    // is invisible against an empty error body, so this one carries a payload.
    getResponse = { status: 403, body: { settings: { notify_admin_on_new: true } } };
    await renderSettings();
    for (const box of screen.getAllByRole("checkbox")) {
      expect((box as HTMLInputElement).checked).toBe(false);
    }
  });
});

describe("toggling", () => {
  it("saves immediately on change — there is no save button", async () => {
    const u = await renderSettings();
    await u.click(screen.getByRole("checkbox", { name: /Admin bei neuem Ticket/ }));
    await waitFor(() => expect(put()).toBeDefined());
    expect(pathOf(put()!.url)).toBe("/admin/ticket-settings");
  });

  it("sends the WHOLE toggle map, not just the changed key", async () => {
    // `TicketSettings::all()` always returns all three keys, so the loaded map
    // is complete and a save round-trips it whole. (The PHP `put()` upserts
    // only the keys it is given, so a partial body would not clear the others
    // — but sending the full map is what keeps the two sides honest.)
    getResponse = { status: 200, body: { settings: Object.fromEntries(KEYS.map((k) => [k, false])) } };
    const u = await renderSettings();
    await u.click(screen.getByRole("checkbox", { name: /Kunde bei Antwort/ }));
    await waitFor(() => expect(put()).toBeDefined());
    expect(Object.keys(put()!.body as object).sort()).toEqual([...KEYS].sort());
  });

  it("sends only the known keys, never an unknown one from the API", async () => {
    // The PHP side ignores unknown keys; not forwarding them keeps the
    // request honest and the surface closed.
    getResponse = { status: 200, body: { settings: { ...Object.fromEntries(KEYS.map((k) => [k, false])) } } };
    const u = await renderSettings();
    await u.click(screen.getByRole("checkbox", { name: /Admin bei neuem Ticket/ }));
    await waitFor(() => expect(put()).toBeDefined());
    for (const key of Object.keys(put()!.body as object)) {
      expect(KEYS as readonly string[], `unexpected key ${key}`).toContain(key);
    }
  });

  it("preserves the other toggles' values when one changes", async () => {
    getResponse = {
      status: 200,
      body: { settings: { notify_admin_on_new: true, notify_customer_on_status: true } },
    };
    const u = await renderSettings();
    await u.click(screen.getByRole("checkbox", { name: /Kunde bei Antwort/ }));
    await waitFor(() => expect(put()).toBeDefined());
    expect(put()!.body).toMatchObject({
      notify_admin_on_new: true,
      notify_customer_on_status: true,
      notify_customer_on_reply: true,
    });
  });

  it("sends false when a toggle is switched off", async () => {
    getResponse = { status: 200, body: { settings: { notify_admin_on_new: true } } };
    const u = await renderSettings();
    await u.click(screen.getByRole("checkbox", { name: /Admin bei neuem Ticket/ }));
    await waitFor(() => expect(put()).toBeDefined());
    expect((put()!.body as Record<string, boolean>).notify_admin_on_new).toBe(false);
  });

  it("updates the checkbox optimistically, before the save resolves", async () => {
    const u = await renderSettings();
    const box = screen.getByRole("checkbox", { name: /Admin bei neuem Ticket/ }) as HTMLInputElement;
    await u.click(box);
    expect(box.checked).toBe(true);
  });

  it("sends JSON with the content type the API expects", async () => {
    const u = await renderSettings();
    await u.click(screen.getByRole("checkbox", { name: /Admin bei neuem Ticket/ }));
    await waitFor(() => expect(put()).toBeDefined());
    const fetchMock = fetch as unknown as ReturnType<typeof vi.fn>;
    const call = fetchMock.mock.calls.find((c) => (c[1] as RequestInit)?.method === "PUT")!;
    expect((call[1] as RequestInit).headers).toMatchObject({ "Content-Type": "application/json" });
  });

  it("does not re-read the settings after saving", async () => {
    // The optimistic update is the source of truth; a re-read would race it.
    const u = await renderSettings();
    await u.click(screen.getByRole("checkbox", { name: /Admin bei neuem Ticket/ }));
    await waitFor(() => expect(put()).toBeDefined());
    expect(calls.filter((c) => c.method === "GET")).toHaveLength(1);
  });

  it("allows a second toggle after the first save completes", async () => {
    const u = await renderSettings();
    await u.click(screen.getByRole("checkbox", { name: /Admin bei neuem Ticket/ }));
    await waitFor(() => expect(calls.filter((c) => c.method === "PUT")).toHaveLength(1));
    await u.click(screen.getByRole("checkbox", { name: /Kunde bei Antwort/ }));
    await waitFor(() => expect(calls.filter((c) => c.method === "PUT")).toHaveLength(2));
  });
});

describe("a rejected save", () => {
  /**
   * The toggle flips optimistically, so a save that fails must both undo the
   * flip and say why. Before this, the response was awaited and discarded: a
   * 403 or a 500 left the checkbox showing a setting the server never stored,
   * and the admin had no way to know their notifications were still off.
   */
  let toasts: Array<{ variant: string; message: string }>;
  const collect = (e: Event) => {
    toasts.push((e as CustomEvent<{ variant: string; message: string }>).detail);
  };

  beforeEach(() => {
    toasts = [];
    window.addEventListener(TOAST_EVENT, collect);
  });
  afterEach(() => window.removeEventListener(TOAST_EVENT, collect));

  /** Make the next PUT answer with `status`. */
  function failPut(status: number) {
    vi.stubGlobal(
      "fetch",
      vi.fn(async (url: string, init?: RequestInit) => {
        const method = init?.method ?? "GET";
        calls.push({ url, method, body: typeof init?.body === "string" ? JSON.parse(init.body) : undefined });
        if (method === "PUT") return { ok: false, status, json: async () => ({}) } as Response;
        return { ok: true, status: 200, json: async () => ({ settings: {} }) } as Response;
      }),
    );
  }

  it("rolls the checkbox back and reports the status", async () => {
    failPut(500);
    render(<NotificationSettings />);
    const box = (await screen.findByRole("checkbox", { name: /Admin bei neuem Ticket/ })) as HTMLInputElement;
    await user().click(box);

    await waitFor(() => expect(toasts.length).toBe(1));
    expect(toasts[0]!.variant).toBe("danger");
    expect(toasts[0]!.message).toContain("500");
    expect(box.checked).toBe(false);
  });

  it("confirms a save that worked", async () => {
    const u = await renderSettings();
    await u.click(screen.getByRole("checkbox", { name: /Admin bei neuem Ticket/ }));
    await waitFor(() => expect(toasts.length).toBe(1));
    expect(toasts[0]!.variant).toBe("success");
  });
});
