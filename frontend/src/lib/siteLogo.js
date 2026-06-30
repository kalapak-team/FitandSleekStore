import bundledLogoCircle from "@/assets/logo-circle.png?inline";

/** Inlined in the JS bundle — no network request; shows instantly on slow loads. */
export const BUNDLED_SITE_LOGO_SRC = bundledLogoCircle;

/** Same-origin fallback when inline import is unavailable (e.g. tests). */
export const SITE_LOGO_WEB_PATH = "/logo-circle.png";

const SITE_LOGO_BASENAMES = new Set([
  "logo.png",
  "logo.svg",
  "logo-circle.png",
  "logo-circle.svg",
]);

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

/** Map legacy bundled logo paths to the circular PNG; keep custom uploads unchanged. */
export function normalizeSiteLogoForWeb(src) {
  if (!src || isBundledSiteLogo(src)) return SITE_LOGO_WEB_PATH;
  return String(src).trim();
}

/** Instant logo from the JS bundle; no backend / slow static host round-trip. */
export function siteStaticLogoPath() {
  return BUNDLED_SITE_LOGO_SRC;
}

/** Default bundled logo — inlined when bundled, same-origin path otherwise. */
export function resolveBundledLogoUrl(src) {
  const trimmed = src ? String(src).trim() : "";
  if (trimmed && !isBundledSiteLogo(trimmed)) return trimmed;
  return BUNDLED_SITE_LOGO_SRC;
}

/** Favicons are served from the SPA host — do not repoint to Laravel (slow cold start). */
export function ensureDocumentBrandAssets() {
  /* no-op: index.html + public/ favicons on the storefront CDN */
}
