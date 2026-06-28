/** Default bundled site logo for the React storefront (SVG). */
export const SITE_LOGO_WEB_PATH = "/logo.svg";

const SITE_LOGO_BASENAMES = new Set(["logo.png", "logo.svg"]);

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

/** Map legacy `/logo.png` to SVG; keep custom uploaded logos unchanged. */
export function normalizeSiteLogoForWeb(src) {
  if (!src || isBundledSiteLogo(src)) return SITE_LOGO_WEB_PATH;
  return String(src).trim();
}

export function siteStaticLogoPath() {
  if (typeof window !== "undefined" && window.location?.origin) {
    return `${window.location.origin}${SITE_LOGO_WEB_PATH}`;
  }
  return SITE_LOGO_WEB_PATH;
}
