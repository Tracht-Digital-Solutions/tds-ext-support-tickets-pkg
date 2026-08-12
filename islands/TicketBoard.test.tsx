// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import TicketBoard from "./TicketBoard";

/**
 * The portal ticket board: list → detail → reply thread, plus the new-ticket
 * form and attachment upload.
 *
 * Deliberately NOT asserted: the `chip chip--${status_color}` class. That value
 * comes from the `support_tickets_status` table (an admin types it), and the
 * fix — routing it through `resolveChipVariant` — lives on the design branch,
 * which needs an unpublished tds-shared. Asserting the class here would pin the
 * suite to the pre-fix shape and break that merge. The status NAME is asserted
 * instead, which is stable across both.
 */

type Hit = { status?: number; body?: unknown };
let handlers: Array<(url: string, init?: RequestInit) => Hit | undefined> = [];
let calls: Array<{ url: string; method: string; body: unknown; raw: BodyInit | null | undefined }> = [];


/**
 * Path + query of a request. The island calls an ABSOLUTE URL now (via
 * `apiFetch`); a relative one would hit the product's own static host and come
 * back as SPA-fallback HTML with a 200. Matching on the path keeps the route
 * matchers below anchored.
 */
const pathOf = (url: string) => String(url).replace(/^https?:\/\/[^/]+/i, "");

function respond(match: RegExp, body: unknown, status = 200, method?: string) {
  handlers.unshift((url, init) => {
    if (!match.test(pathOf(url))) return undefined;
    if (method && (init?.method ?? "GET") !== method) return undefined;
    return { status, body };
  });
}

beforeEach(() => {
  handlers = [];
  calls = [];
  vi.stubGlobal(
    "fetch",
    vi.fn(async (url: string, init?: RequestInit) => {
      calls.push({
        url,
        method: init?.method ?? "GET",
        body: typeof init?.body === "string" ? JSON.parse(init.body) : undefined,
        raw: init?.body,
      });
      for (const h of handlers) {
        const hit = h(url, init);
        if (hit) {
          const status = hit.status ?? 200;
          return { ok: status >= 200 && status < 300, status, json: async () => hit.body ?? {} } as Response;
        }
      }
      return { ok: true, status: 200, json: async () => ({}) } as Response;
    }),
  );
});

afterEach(() => cleanup());

const user = () => userEvent.setup({ delay: null });

const ROW = {
  id: 7,
  subject: "Drucker geht nicht",
  status_name: "Offen",
  status_color: "violet",
  priority: "normal",
  customer_action_required: 0,
};

const DETAIL = {
  ...ROW,
  description: "Seit gestern.",
  customer_action_note: null,
  comments: [],
  attachments: [],
};

async function renderBoard(tickets: unknown[] = [ROW]) {
  respond(/^\/tickets$/, { tickets });
  render(<TicketBoard />);
  await waitFor(() => expect(calls.some((c) => pathOf(c.url) === "/tickets")).toBe(true));
}

/** Open ticket 7's detail view. */
async function openDetail(detail: Record<string, unknown> = DETAIL) {
  await renderBoard();
  respond(/^\/tickets\/7$/, detail);
  const u = user();
  await u.click(await screen.findByRole("button", { name: "Drucker geht nicht" }));
  await screen.findByRole("heading", { name: "Drucker geht nicht" });
  return u;
}

const posts = () => calls.filter((c) => c.method === "POST");

describe("the ticket list", () => {
  it("loads tickets on mount with credentials", async () => {
    await renderBoard();
    expect(await screen.findByRole("button", { name: "Drucker geht nicht" })).toBeTruthy();
    const fetchMock = fetch as unknown as ReturnType<typeof vi.fn>;
    expect(fetchMock.mock.calls[0]![1]).toMatchObject({ credentials: "include" });
  });

  it("shows the status name of each ticket", async () => {
    await renderBoard();
    expect(await screen.findByText("Offen")).toBeTruthy();
  });

  it("shows the empty state when there are no tickets", async () => {
    await renderBoard([]);
    expect(await screen.findByText("Keine Tickets vorhanden.")).toBeTruthy();
  });

  it("degrades to the empty state on an error rather than hanging", async () => {
    respond(/^\/tickets$/, {}, 500);
    render(<TicketBoard />);
    expect(await screen.findByText("Keine Tickets vorhanden.")).toBeTruthy();
  });

  it("degrades to the empty state when fetch rejects", async () => {
    vi.stubGlobal("fetch", vi.fn(async () => { throw new TypeError("offline"); }));
    render(<TicketBoard />);
    expect(await screen.findByText("Keine Tickets vorhanden.")).toBeTruthy();
  });

  it("tolerates a response without a tickets field", async () => {
    respond(/^\/tickets$/, {});
    render(<TicketBoard />);
    expect(await screen.findByText("Keine Tickets vorhanden.")).toBeTruthy();
  });

  it("does not render tickets carried by a NON-OK response", async () => {
    // `r.ok ? r.json() : { tickets: [] }` is what makes this safe. Dropping
    // the ok-check is invisible against an empty error body — both branches
    // end up empty — so the error body here deliberately carries a payload.
    respond(/^\/tickets$/, { tickets: [ROW] }, 500);
    render(<TicketBoard />);
    expect(await screen.findByText("Keine Tickets vorhanden.")).toBeTruthy();
    expect(screen.queryByRole("button", { name: "Drucker geht nicht" })).toBeNull();
  });

  it("flags a ticket that needs the customer to act", async () => {
    await renderBoard([{ ...ROW, customer_action_required: 1 }]);
    expect(await screen.findByText("Aktion erforderlich")).toBeTruthy();
  });

  it("does not flag a ticket that does not", async () => {
    await renderBoard();
    await screen.findByText("Offen");
    expect(screen.queryByText("Aktion erforderlich")).toBeNull();
  });

  it("accepts a boolean action flag as well as the DB's 0/1", async () => {
    await renderBoard([{ ...ROW, customer_action_required: true }]);
    expect(await screen.findByText("Aktion erforderlich")).toBeTruthy();
  });
});

describe("opening a ticket", () => {
  it("fetches that ticket's detail by id", async () => {
    await openDetail();
    expect(calls.some((c) => pathOf(c.url) === "/tickets/7")).toBe(true);
  });

  it("renders the description and status", async () => {
    await openDetail();
    expect(screen.getByText("Seit gestern.")).toBeTruthy();
    expect(screen.getByText("Offen")).toBeTruthy();
  });

  it("shows the action note when the customer must act", async () => {
    await openDetail({ ...DETAIL, customer_action_required: 1, customer_action_note: "Bitte Foto senden." });
    expect(screen.getByText("Bitte Foto senden.")).toBeTruthy();
  });

  it("falls back to a default prompt when the note is null", async () => {
    await openDetail({ ...DETAIL, customer_action_required: 1, customer_action_note: null });
    expect(screen.getByText(/Bitte antworten Sie\./)).toBeTruthy();
  });

  it("returns to the list and refreshes it", async () => {
    // The status may have changed while the detail was open.
    const u = await openDetail();
    await u.click(screen.getByRole("button", { name: "← Zurück" }));
    await waitFor(() => expect(calls.filter((c) => pathOf(c.url) === "/tickets" && c.method === "GET")).toHaveLength(2));
  });

  it("stays on the list when the detail request fails", async () => {
    await renderBoard();
    respond(/^\/tickets\/7$/, {}, 500);
    await user().click(await screen.findByRole("button", { name: "Drucker geht nicht" }));
    await waitFor(() => expect(calls.some((c) => pathOf(c.url) === "/tickets/7")).toBe(true));
    expect(screen.queryByRole("heading", { name: "Drucker geht nicht" })).toBeNull();
  });
});

describe("the comment thread", () => {
  const withComments = {
    ...DETAIL,
    comments: [
      { id: 1, author_type: "customer", body: "Bitte helfen", created_at: "2026-01-01" },
      { id: 2, author_type: "owner", body: "Wir schauen", created_at: "2026-01-02" },
    ],
  };

  it("labels each comment with the right side of the conversation", async () => {
    // Asserting only that both labels EXIST passes even when they are
    // swapped, which would show the customer their own words as "Support".
    await openDetail(withComments);
    const items = within(document.querySelector("ol.tds-thread")!).getAllByRole("listitem");
    const mine = items.find((li) => li.textContent!.includes("Bitte helfen"))!;
    const theirs = items.find((li) => li.textContent!.includes("Wir schauen"))!;
    expect(within(mine).getByText("Sie")).toBeTruthy();
    expect(within(theirs).getByText("Support")).toBeTruthy();
  });

  it("renders comments in the order the API returned them", async () => {
    await openDetail(withComments);
    const items = within(document.querySelector("ol.tds-thread")!).getAllByRole("listitem");
    expect(items[0]!.textContent).toContain("Bitte helfen");
    expect(items[1]!.textContent).toContain("Wir schauen");
  });

  it("renders comment bodies as text, never as markup", async () => {
    await openDetail({
      ...DETAIL,
      comments: [{ id: 1, author_type: "customer", body: "<img src=x onerror=alert(1)>", created_at: "x" }],
    });
    expect(document.querySelector("ol.tds-thread img")).toBeNull();
    expect(screen.getByText("<img src=x onerror=alert(1)>")).toBeTruthy();
  });
});

describe("replying", () => {
  it("disables Senden until something is typed", async () => {
    await openDetail();
    expect((screen.getByRole("button", { name: "Senden" }) as HTMLButtonElement).disabled).toBe(true);
  });

  it("keeps Senden disabled for whitespace only", async () => {
    const u = await openDetail();
    await u.type(screen.getByPlaceholderText("Antwort schreiben …"), "   ");
    expect((screen.getByRole("button", { name: "Senden" }) as HTMLButtonElement).disabled).toBe(true);
  });

  it("posts a trimmed reply to the ticket's comment endpoint", async () => {
    const u = await openDetail();
    await u.type(screen.getByPlaceholderText("Antwort schreiben …"), "  Danke!  ");
    await u.click(screen.getByRole("button", { name: "Senden" }));
    await waitFor(() => expect(posts()).toHaveLength(1));
    expect(pathOf(posts()[0]!.url)).toBe("/tickets/7/comments");
    expect(posts()[0]!.body).toEqual({ body: "Danke!" });
  });

  it("clears the box and reloads the ticket after sending", async () => {
    const u = await openDetail();
    await u.type(screen.getByPlaceholderText("Antwort schreiben …"), "Danke");
    await u.click(screen.getByRole("button", { name: "Senden" }));
    await waitFor(() =>
      expect((screen.getByPlaceholderText("Antwort schreiben …") as HTMLTextAreaElement).value).toBe(""),
    );
    expect(calls.filter((c) => pathOf(c.url) === "/tickets/7" && c.method === "GET").length).toBeGreaterThan(1);
  });

  it("sends JSON with the content type the API expects", async () => {
    const u = await openDetail();
    await u.type(screen.getByPlaceholderText("Antwort schreiben …"), "x");
    await u.click(screen.getByRole("button", { name: "Senden" }));
    await waitFor(() => expect(posts()).toHaveLength(1));
    const fetchMock = fetch as unknown as ReturnType<typeof vi.fn>;
    const call = fetchMock.mock.calls.find((c) => String(c[0]).endsWith("/comments"))!;
    expect((call[1] as RequestInit).headers).toMatchObject({ "Content-Type": "application/json" });
  });
});

describe("attachments", () => {
  it("lists an existing attachment with a download link to its endpoint", async () => {
    await openDetail({ ...DETAIL, attachments: [{ id: 3, filename: "log.txt", size_bytes: 12 }] });
    const link = screen.getByRole("link", { name: "log.txt" }) as HTMLAnchorElement;
    expect(link.getAttribute("href")).toBe("/tickets/7/attachments/3");
    expect(link.hasAttribute("download")).toBe(true);
  });

  it("renders no attachment list when there are none", async () => {
    await openDetail();
    expect(document.querySelector("ul.ticket-attachments")).toBeNull();
  });

  it("uploads a chosen file as multipart FormData, not JSON", async () => {
    // The endpoint expects a file part; a JSON body would 400.
    const u = await openDetail();
    const file = new File(["hallo"], "notiz.txt", { type: "text/plain" });
    await u.upload(document.querySelector('input[type="file"]') as HTMLInputElement, file);

    await waitFor(() => expect(posts().some((c) => pathOf(c.url) === "/tickets/7/attachments")).toBe(true));
    const upload = posts().find((c) => pathOf(c.url) === "/tickets/7/attachments")!;
    expect(upload.raw).toBeInstanceOf(FormData);
    expect((upload.raw as FormData).get("file")).toBe(file);
  });

  it("reloads the ticket after an upload so the new file appears", async () => {
    const u = await openDetail();
    await u.upload(
      document.querySelector('input[type="file"]') as HTMLInputElement,
      new File(["x"], "a.txt", { type: "text/plain" }),
    );
    await waitFor(() =>
      expect(calls.filter((c) => pathOf(c.url) === "/tickets/7" && c.method === "GET").length).toBeGreaterThan(1),
    );
  });

  it("clears the file input so the same file can be picked again", async () => {
    const u = await openDetail();
    const input = document.querySelector('input[type="file"]') as HTMLInputElement;
    await u.upload(input, new File(["x"], "a.txt", { type: "text/plain" }));
    await waitFor(() => expect(input.value).toBe(""));
  });
});

describe("creating a ticket", () => {
  async function openForm() {
    await renderBoard([]);
    const u = user();
    await u.click(await screen.findByRole("button", { name: "Neues Ticket" }));
    return u;
  }

  it("toggles the form open and closed", async () => {
    const u = await openForm();
    expect(screen.getByPlaceholderText("Betreff")).toBeTruthy();
    await u.click(screen.getByRole("button", { name: "Abbrechen" }));
    expect(screen.queryByPlaceholderText("Betreff")).toBeNull();
  });

  it("refuses to submit without a subject", async () => {
    const u = await openForm();
    await u.type(screen.getByPlaceholderText("Beschreibung"), "Text");
    await u.click(screen.getByRole("button", { name: "Ticket erstellen" }));
    expect(posts()).toHaveLength(0);
  });

  it("refuses to submit without a description", async () => {
    const u = await openForm();
    await u.type(screen.getByPlaceholderText("Betreff"), "Betreff");
    await u.click(screen.getByRole("button", { name: "Ticket erstellen" }));
    expect(posts()).toHaveLength(0);
  });

  it("treats a whitespace-only subject as missing", async () => {
    const u = await openForm();
    await u.type(screen.getByPlaceholderText("Betreff"), "   ");
    await u.type(screen.getByPlaceholderText("Beschreibung"), "Text");
    await u.click(screen.getByRole("button", { name: "Ticket erstellen" }));
    expect(posts()).toHaveLength(0);
  });

  it("posts the ticket with its defaults", async () => {
    const u = await openForm();
    await u.type(screen.getByPlaceholderText("Betreff"), "Neu");
    await u.type(screen.getByPlaceholderText("Beschreibung"), "Details");
    await u.click(screen.getByRole("button", { name: "Ticket erstellen" }));
    await waitFor(() => expect(posts()).toHaveLength(1));
    expect(pathOf(posts()[0]!.url)).toBe("/tickets");
    expect(posts()[0]!.body).toEqual({
      subject: "Neu",
      description: "Details",
      type: "question",
      priority: "normal",
    });
  });

  it("sends the chosen type and priority", async () => {
    const u = await openForm();
    await u.type(screen.getByPlaceholderText("Betreff"), "Neu");
    await u.type(screen.getByPlaceholderText("Beschreibung"), "Details");
    const [type, priority] = screen.getAllByRole("combobox");
    await u.selectOptions(type!, "bug");
    await u.selectOptions(priority!, "urgent");
    await u.click(screen.getByRole("button", { name: "Ticket erstellen" }));
    await waitFor(() => expect(posts()).toHaveLength(1));
    expect(posts()[0]!.body).toMatchObject({ type: "bug", priority: "urgent" });
  });

  it("closes the form and reloads the list after a successful create", async () => {
    const u = await openForm();
    await u.type(screen.getByPlaceholderText("Betreff"), "Neu");
    await u.type(screen.getByPlaceholderText("Beschreibung"), "Details");
    await u.click(screen.getByRole("button", { name: "Ticket erstellen" }));
    await waitFor(() => expect(screen.queryByPlaceholderText("Betreff")).toBeNull());
    expect(calls.filter((c) => pathOf(c.url) === "/tickets" && c.method === "GET")).toHaveLength(2);
  });

  it("keeps the form open when the create fails, so the text is not lost", async () => {
    const u = await openForm();
    respond(/^\/tickets$/, {}, 500, "POST");
    await u.type(screen.getByPlaceholderText("Betreff"), "Neu");
    await u.type(screen.getByPlaceholderText("Beschreibung"), "Details");
    await u.click(screen.getByRole("button", { name: "Ticket erstellen" }));
    await waitFor(() => expect(posts()).toHaveLength(1));
    expect((screen.getByPlaceholderText("Betreff") as HTMLInputElement).value).toBe("Neu");
  });
});
