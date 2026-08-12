import { useEffect, useState } from "react";

import { Skeleton } from "@tracht-digital-solutions/tds-shared/components";
import { apiFetch } from "@tracht-digital-solutions/tds-shared/api";

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
    apiFetch("/tickets/summary")
      .then((r) => (r.ok ? r.json() : { open: 0 }))
      .then((d) => alive && setOpen(Number(d.open ?? 0)))
      .catch(() => alive && setOpen(0));
    return () => {
      alive = false;
    };
  }, []);
  // A literal "…" was the loading state on 12 of the 13 dashboard widgets:
  // static, indistinguishable from a real value, and invisible to assistive
  // tech. `aria-busy` announces the wait; the skeleton shows it.
  if (open === null) {
    return (
      <p className="tds-widget__metric" aria-busy="true">
        <Skeleton width="3ch" height="1.75rem" />
      </p>
    );
  }
  return <p className="tds-widget__metric">{open}</p>;
}
