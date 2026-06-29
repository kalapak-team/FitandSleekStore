import { resolveBackendOrigin } from "@/lib/backendOrigin";

/** Default bundled site logo for the React storefront (raster banner — visible on sage chrome). */
export const SITE_LOGO_WEB_PATH = "/logo.png";

const SITE_LOGO_BASENAMES = new Set(["logo.png", "logo.svg"]);
const LOOPBACK_HOSTS = new Set(["localhost", "127.0.0.1", "[::1]", "0.0.0.0"]);

function pathnameOf(url) {
  try {
    const base = typeof window !== "undefined" ? window.location.origin : "https://localhost";
    return new URL(url, base).pathname.replace(/\/+$/, "") || "/";
  } catch {
    return "";
  }
}

export function isBundledSiteLogo(src) {
  if (!src) return true;
  const trimmed = String(src).trim();
  if (!trimmed || trimmed === SITE_LOGO_WEB_PATH) return true;
  const base = pathnameOf(trimmed).split("/").pop()?.toLowerCase() || "";
  return SITE_LOGO_BASENAMES.has(base);
}

/** Map legacy bundled logo paths to PNG; keep custom uploaded logos unchanged. */
export function normalizeSiteLogoForWeb(src) {
  if (!src || isBundledSiteLogo(src)) return SITE_LOGO_WEB_PATH;
  return String(src).trim();
}

export function siteStaticLogoPath() {
  const resolved = resolveBundledLogoUrl();
  if (resolved.startsWith("http")) return resolved;
  if (typeof window !== "undefined" && window.location?.origin) {
    return `${window.location.origin}${resolved}`;
  }
  return resolved;
}

/** When the SPA host is split from Laravel (Vercel/tunnel), serve brand files from the API origin. */
export function externalBrandAssetOrigin() {
  if (!import.meta.env.PROD || typeof window === "undefined") return null;

  const backend = resolveBackendOrigin();
  const page = window.location.origin;
  if (!backend || backend === page) return null;

  try {
    const host = new URL(backend).hostname.toLowerCase();
    if (LOOPBACK_HOSTS.has(host)) return null;
    return new URL(backend).origin;
  } catch {
    return null;
  }
}

export function resolveBundledLogoUrl(src) {
  const trimmed = src ? String(src).trim() : "";
  if (trimmed && !isBundledSiteLogo(trimmed)) return trimmed;

  const external = externalBrandAssetOrigin();
  return external ? `${external}/logo.png` : SITE_LOGO_WEB_PATH;
}

/** Repoint favicon / touch icon to Laravel when the static host cannot serve them (QUIC/tunnel flakes). */
export function ensureDocumentBrandAssets() {
  const external = externalBrandAssetOrigin();
  if (!external) return;

  const assets = [
    ['link[rel="icon"][type="image/svg+xml"]', "/favicon.svg"],
    ['link[rel="icon"][type="image/png"]', "/favicon.png"],
    ['link[rel="apple-touch-icon"]', "/apple-touch-icon.png"],
  ];

  for (const [selector, path] of assets) {
    const el = document.querySelector(selector);
    if (el) el.setAttribute("href", `${external}${path}`);
  }
}
