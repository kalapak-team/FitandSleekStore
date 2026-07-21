import api from "./api";

let wakePromise = null;

/** Fire-and-forget ping so Render cold starts finish before heavy bootstrap calls. */
export function wakeBackend() {
  if (typeof window === "undefined") return Promise.resolve();
  if (!wakePromise) {
    wakePromise = api.get("/health").catch(() => {}).finally(() => {
      wakePromise = null;
    });
  }
  return wakePromise;
}
