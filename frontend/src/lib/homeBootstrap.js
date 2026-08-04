import api from "./api";
import { resolveBackendOrigin } from "./backendOrigin";
import { wakeBackend } from "./wakeBackend";

const CACHE_KEY = "fs_home_bootstrap_v1";
const CACHE_TTL_MS = 15 * 60 * 1000;

export function readCachedBootstrap({ ignoreExpiry = false } = {}) {
  if (typeof window === "undefined") return null;
  try {
    const raw = window.sessionStorage.getItem(CACHE_KEY);
    if (!raw) return null;
    const parsed = JSON.parse(raw);
    if (!parsed?.data || typeof parsed.fetchedAt !== "number") return null;
    if (!ignoreExpiry && Date.now() - parsed.fetchedAt > CACHE_TTL_MS) return null;
    return parsed.data;
  } catch {
    return null;
  }
}

function writeCachedBootstrap(data) {
  if (typeof window === "undefined" || !data) return;
  try {
    window.sessionStorage.setItem(
      CACHE_KEY,
      JSON.stringify({ fetchedAt: Date.now(), data })
    );
  } catch {
    /* ignore quota */
  }
}

function isGatewayTimeout(error) {
  const status = error?.response?.status;
  return status === 504 || status === 502 || status === 503;
}

/** Bypass Netlify ~26s proxy limit when same-origin /api times out. */
async function fetchBootstrapDirect() {
  const origin = resolveBackendOrigin()?.replace(/\/$/, "");
  if (!origin || typeof window === "undefined") {
    throw new Error("No backend origin for direct bootstrap");
  }
  // Same-origin already failed via axios; only useful when page is proxied host.
  if (origin === window.location.origin) {
    const direct = "https://fitandsleek-official-production.up.railway.app";
    const res = await fetch(`${direct}/api/homepage/bootstrap`, {
      method: "GET",
      mode: "cors",
      credentials: "omit",
      headers: { Accept: "application/json" },
      signal: typeof AbortSignal !== "undefined" ? AbortSignal.timeout(90000) : undefined,
    });
    if (!res.ok) {
      throw new Error(`Direct bootstrap HTTP ${res.status}`);
    }
    return res.json();
  }

  const res = await fetch(`${origin}/api/homepage/bootstrap`, {
    method: "GET",
    mode: "cors",
    credentials: "omit",
    headers: { Accept: "application/json" },
    signal: typeof AbortSignal !== "undefined" ? AbortSignal.timeout(90000) : undefined,
  });
  if (!res.ok) {
    throw new Error(`Direct bootstrap HTTP ${res.status}`);
  }
  return res.json();
}

export async function fetchHomeBootstrap({ preferCache = true, allowStale = false } = {}) {
  const cached = readCachedBootstrap({ ignoreExpiry: allowStale });
  if (preferCache && cached && !allowStale) {
    return cached;
  }

  try {
    const { data } = await api.get("/homepage/bootstrap", { timeout: 55000 });
    writeCachedBootstrap(data);
    return data;
  } catch (error) {
    if (!isGatewayTimeout(error)) {
      throw error;
    }
    await wakeBackend();
    try {
      const { data } = await api.get("/homepage/bootstrap", { timeout: 55000 });
      writeCachedBootstrap(data);
      return data;
    } catch (retryError) {
      if (!isGatewayTimeout(retryError)) {
        throw retryError;
      }
      const data = await fetchBootstrapDirect();
      writeCachedBootstrap(data);
      return data;
    }
  }
}

/** Return cached bootstrap immediately; refresh in background when stale or missing. */
export function hydrateHomeBootstrap({ onUpdate } = {}) {
  const stale = readCachedBootstrap({ ignoreExpiry: true });
  const freshEnough = readCachedBootstrap();

  if (freshEnough) {
    return freshEnough;
  }

  fetchHomeBootstrap({ preferCache: false })
    .then((data) => {
      if (typeof onUpdate === "function") onUpdate(data);
    })
    .catch(() => {});

  return stale;
}

export function clearHomeBootstrapCache() {
  if (typeof window === "undefined") return;
  try {
    window.sessionStorage.removeItem(CACHE_KEY);
  } catch {
    /* ignore */
  }
}
