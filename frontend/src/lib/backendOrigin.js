function parseOrigin(input) {
    try {
        return new URL(input);
    } catch {
        return null;
    }
}

function getConfiguredValues() {
    return {
        backendOrigin: import.meta.env.VITE_BACKEND_ORIGIN,
        apiBaseUrl: import.meta.env.VITE_API_BASE_URL,
    };
}

/**
 * Split-host map: storefront SPA and Laravel API use different hostnames.
 * fitandsleek.kalapak-team.space → frontend only; fitandsleekapp.kalapak-team.space → backend.
 *
 * Production Netlify (fitandsleek.online) must NOT use same-origin `/api` for the SPA:
 * Netlify's edge proxy times out around ~26s (504) while Railway+Neon cold/bootstrap
 * can take longer. Browser calls Railway directly (CORS + Bearer auth).
 * netlify.toml `/api/*` redirects remain for non-browser / legacy clients.
 */
const RAILWAY_API_ORIGIN = "https://fitandsleek-official-production.up.railway.app";

const PUBLIC_STOREFRONT_TO_LARAVEL_ORIGIN = {
    "fitandsleek.kalapak-team.space": "https://fitandsleekapp.kalapak-team.space",
    "www.fitandsleek.kalapak-team.space": "https://fitandsleekapp.kalapak-team.space",
    "web-fitandsleek.onrender.com": "https://api-fitandsleek.onrender.com",
    "www.web-fitandsleek.onrender.com": "https://api-fitandsleek.onrender.com",
    "norng-fitandsleek-store.vercel.app": "https://norng-fitandsleek-backend.onrender.com",
    "www.norng-fitandsleek-store.vercel.app": "https://norng-fitandsleek-backend.onrender.com",
    "fitandsleek-official.vercel.app": RAILWAY_API_ORIGIN,
    "www.fitandsleek-official.vercel.app": RAILWAY_API_ORIGIN,
    "fitandsleek-official-frontend.netlify.app": RAILWAY_API_ORIGIN,
    "www.fitandsleek-official-frontend.netlify.app": RAILWAY_API_ORIGIN,
    "fitandsleek.online": RAILWAY_API_ORIGIN,
    "www.fitandsleek.online": RAILWAY_API_ORIGIN,
};

/**
 * Netlify-hosted SPAs: call Railway `/api` directly (avoid edge proxy 504).
 * Returns null when the current host should keep whatever normalizeConfiguredOrigin says.
 */
function railwayApiBaseForNetlifyStorefront() {
    if (typeof window === "undefined" || !window.location?.hostname) return null;
    const host = String(window.location.hostname).toLowerCase();
    const origin = PUBLIC_STOREFRONT_TO_LARAVEL_ORIGIN[host];
    if (!origin || origin === window.location.origin) return null;
    if (!origin.includes("railway.app") && !origin.includes("onrender.com") && !origin.includes("kalapak-team.space")) {
        return null;
    }
    // Only force-direct for Netlify production hostnames (the ones that used to proxy).
    if (
        host !== "fitandsleek.online" &&
        host !== "www.fitandsleek.online" &&
        !host.endsWith(".netlify.app")
    ) {
        return null;
    }
    return `${origin.replace(/\/$/, "")}/api`;
}

function sameOriginApiBaseIfProxied() {
    // Deprecated path: previously forced same-origin Netlify proxy (caused Admin/profile 504s).
    return railwayApiBaseForNetlifyStorefront();
}

function laravelOriginForPublicStorefrontHostname(hostname) {
    if (!hostname) return null;
    const origin = PUBLIC_STOREFRONT_TO_LARAVEL_ORIGIN[String(hostname).toLowerCase()];
    return origin || null;
}

function currentOriginFallback() {
    if (typeof window !== "undefined" && window.location?.origin) {
        return window.location.origin;
    }
    return "http://127.0.0.1:8001";
}

function isLoopbackHost(hostname) {
    if (!hostname) return false;
    const h = String(hostname).toLowerCase();
    return h === "localhost" || h === "127.0.0.1" || h === "[::1]" || h === "0.0.0.0";
}

function isBrowserOnLoopback() {
    if (typeof window === "undefined" || !window.location?.hostname) return true;
    return isLoopbackHost(window.location.hostname);
}

/** HTTPS on a real hostname — rewrite `http://localhost:…` asset URLs to this origin (fixes mixed content). */
export function shouldRewriteLoopbackAssetsToPageOrigin() {
    if (typeof window === "undefined") return false;
    if (window.location.protocol !== "https:") return false;
    return !isLoopbackHost(window.location.hostname);
}

function normalizeConfiguredOrigin() {
    const { backendOrigin, apiBaseUrl } = getConfiguredValues();
    const primary = typeof backendOrigin === "string" ? backendOrigin.trim() : "";
    const secondary = typeof apiBaseUrl === "string" ? apiBaseUrl.trim() : "";
    const configured = primary || secondary || currentOriginFallback();

    if (configured.startsWith("/")) {
        if (
            import.meta.env.PROD &&
            typeof window !== "undefined" &&
            window.location?.hostname
        ) {
            const split = laravelOriginForPublicStorefrontHostname(window.location.hostname);
            if (split) {
                const p = parseOrigin(split);
                if (p) {
                    return `${p.protocol}//${p.host}`;
                }
            }
        }
        return currentOriginFallback();
    }

    const parsed = parseOrigin(configured);
    if (!parsed) {
        return currentOriginFallback();
    }

    const originStr = `${parsed.protocol}//${parsed.host}`;

    // Docker/local builds often bake loopback into the bundle. On a real HTTPS storefront, rewrite
    // to the origin that actually serves Laravel — not the static SPA host.
    if (
        import.meta.env.PROD &&
        isLoopbackHost(parsed.hostname) &&
        typeof window !== "undefined" &&
        window.location?.hostname &&
        !isBrowserOnLoopback()
    ) {
        const splitApi = laravelOriginForPublicStorefrontHostname(window.location.hostname);
        if (splitApi) {
            const apiParsed = parseOrigin(splitApi);
            if (apiParsed) {
                return `${apiParsed.protocol}//${apiParsed.host}`;
            }
        }
        // Same-origin `/api` behind one hostname (tunnel / reverse proxy).
        if (window.location?.origin) {
            return window.location.origin;
        }
    }

    return originStr;
}

export function resolveBackendOrigin() {
    const proxied = sameOriginApiBaseIfProxied();
    if (proxied) {
        // proxied is now a full `…/api` URL for Netlify→Railway; origin is without /api.
        return proxied.replace(/\/api\/?$/, "");
    }
    const configuredOrigin = normalizeConfiguredOrigin();
    return configuredOrigin;
}

export function resolveApiBaseUrl() {
    const proxied = sameOriginApiBaseIfProxied();
    if (proxied) {
        return proxied;
    }

    const { apiBaseUrl } = getConfiguredValues();
    const trimmed = typeof apiBaseUrl === "string" ? apiBaseUrl.trim() : "";

    if (trimmed.startsWith("/")) {
        // Relative `/api` is correct for same-origin + reverse proxy. On a split storefront/API host,
        // resolve against the real Laravel origin instead of the static SPA host.
        if (
            import.meta.env.PROD &&
            typeof window !== "undefined" &&
            window.location?.hostname
        ) {
            const split = laravelOriginForPublicStorefrontHostname(window.location.hostname);
            if (split) {
                try {
                    const base = new URL(trimmed, split).href.replace(/\/$/, "");
                    return base;
                } catch {
                    /* fall through */
                }
            }
        }
        return trimmed;
    }

    const base = `${resolveBackendOrigin()}/api`;

    if (import.meta.env.DEV && typeof window !== "undefined") {
        try {
            const pageOrigin = window.location.origin;
            const apiOrigin = new URL(base).origin;
            if (pageOrigin !== apiOrigin) {
                const isLocalPage =
                    window.location.hostname === "localhost" ||
                    window.location.hostname === "127.0.0.1";
                if (isLocalPage) {
                    console.warn(
                        "[Fit&Sleek] API is cross-origin from this page; the browser may block requests (CORS). " +
                            "For local dev, use same-origin API: set VITE_API_BASE_URL=/api and VITE_PROXY_TARGET=<Laravel base URL> in frontend/.env, then restart `npm run dev`. " +
                            "See frontend/.env.example.",
                    );
                }
            }
        } catch {
            /* ignore */
        }
    }

    return base;
}
