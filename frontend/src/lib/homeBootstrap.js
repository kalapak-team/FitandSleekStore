import api from "./api";

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

export async function fetchHomeBootstrap({ preferCache = true, allowStale = false } = {}) {
  const cached = readCachedBootstrap({ ignoreExpiry: allowStale });
  if (preferCache && cached && !allowStale) {
    return cached;
  }

  const { data } = await api.get("/homepage/bootstrap", { timeout: 90000 });
  writeCachedBootstrap(data);
  return data;
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
