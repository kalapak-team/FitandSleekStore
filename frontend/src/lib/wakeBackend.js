import { resolveApiBaseUrl } from "./backendOrigin";

let wakePromise = null;

/** Fire-and-forget ping so Render cold starts finish before heavy bootstrap calls. */
export function wakeBackend() {
  if (typeof window === "undefined") return Promise.resolve();
  if (!wakePromise) {
    const url = `${resolveApiBaseUrl().replace(/\/$/, "")}/health`;
    wakePromise = fetch(url, {
      method: "GET",
      mode: "cors",
      credentials: "omit",
      headers: {
        Accept: "application/json",
      },
    })
      .catch(() => {})
      .finally(() => {
        wakePromise = null;
      });
  }
  return wakePromise;
}
