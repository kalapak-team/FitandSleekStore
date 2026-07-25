import { resolveApiBaseUrl } from "./backendOrigin";

let wakePromise = null;

/** Fire-and-forget ping so Render cold starts finish before heavy bootstrap calls. */
export function wakeBackend() {
  if (typeof window === "undefined") return Promise.resolve();
  if (!wakePromise) {
    const url = `${resolveApiBaseUrl().replace(/\/$/, "")}/health`;
    wakePromise = (async () => {
      for (let attempt = 0; attempt < 3; attempt += 1) {
        try {
          const res = await fetch(url, {
            method: "GET",
            mode: "cors",
            credentials: "omit",
            headers: { Accept: "application/json" },
          });
          if (res.ok) return;
        } catch {
          /* cold start / network */
        }
        await new Promise((r) => setTimeout(r, 2000 * (attempt + 1)));
      }
    })().finally(() => {
      wakePromise = null;
    });
  }
  return wakePromise;
}
