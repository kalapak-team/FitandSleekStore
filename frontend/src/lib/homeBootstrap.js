import api from "./api";

const CACHE_KEY = "fs_home_bootstrap_v1";
const CACHE_TTL_MS = 3 * 60 * 1000;

function readCachedBootstrap() {
  if (typeof window === "undefined") return null;
  try {
    const raw = window.sessionStorage.getItem(CACHE_KEY);
    if (!raw) return null;
    const parsed = JSON.parse(raw);
    if (!parsed?.data || typeof parsed.fetchedAt !== "number") return null;
    if (Date.now() - parsed.fetchedAt > CACHE_TTL_MS) return null;
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

export async function fetchHomeBootstrap({ preferCache = true } = {}) {
  if (preferCache) {
    const cached = readCachedBootstrap();
    if (cached) return cached;
  }

  const { data } = await api.get("/homepage/bootstrap");
  writeCachedBootstrap(data);
  return data;
}

export function clearHomeBootstrapCache() {
  if (typeof window === "undefined") return;
  try {
    window.sessionStorage.removeItem(CACHE_KEY);
  } catch {
    /* ignore */
  }
}
