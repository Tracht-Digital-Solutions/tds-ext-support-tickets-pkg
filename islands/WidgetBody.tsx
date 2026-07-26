import { useEffect, useState } from "react";

/**
 * "Offene Tickets" widget body. Fetches the count from the manifest's
 * dataEndpoint (`/tickets/summary`) via the base API wrapper. Checkpoint-1 uses
 * a relative fetch with credentials; the shared api client is wired in the next
 * frontend checkpoint.
 */
export default function OpenTicketsCount() {
  const [open, setOpen] = useState<number | null>(null);
  useEffect(() => {
    let alive = true;
    fetch("/tickets/summary", { credentials: "include" })
      .then((r) => (r.ok ? r.json() : { open: 0 }))
      .then((d) => alive && setOpen(Number(d.open ?? 0)))
      .catch(() => alive && setOpen(0));
    return () => {
      alive = false;
    };
  }, []);
  return <p className="tds-widget__metric">{open === null ? "…" : open}</p>;
}
