import React, { useEffect, useMemo, useState } from "react";
import { resolveImageUrl } from "../lib/images";

/** Same-origin bundled logo — ships with the SPA in `public/logo.png`. */
export function siteStaticLogoPath() {
  if (typeof window !== "undefined" && window.location?.origin) {
    return `${window.location.origin}/logo.png`;
  }
  return "/logo.png";
}

function pathnameOf(url) {
  try {
    const base = typeof window !== "undefined" ? window.location.origin : "https://localhost";
    return new URL(url, base).pathname.replace(/\/+$/, "") || "/";
  } catch {
    return "";
  }
}

function isBundledLogoSrc(src) {
  if (!src) return true;
  const trimmed = String(src).trim();
  if (!trimmed || trimmed === "/logo.png") return true;
  return pathnameOf(trimmed) === "/logo.png" || pathnameOf(resolveImageUrl(trimmed)) === "/logo.png";
}

export default function Logo({ className = "h-12 w-auto", src = "/logo.png", alt = "Fitandsleek" }) {
  const bundledSrc = siteStaticLogoPath();
  const [networkOk, setNetworkOk] = useState(false);
  const [networkFailed, setNetworkFailed] = useState(false);

  const networkSrc = useMemo(() => {
    if (!src || isBundledLogoSrc(src)) return null;
    const resolved = resolveImageUrl(src);
    if (!resolved || isBundledLogoSrc(resolved)) return null;
    return resolved;
  }, [src]);

  useEffect(() => {
    setNetworkOk(false);
    setNetworkFailed(false);
  }, [networkSrc]);

  const showNetwork = Boolean(networkSrc && !networkFailed && networkOk);

  return (
    <span className={`relative inline-flex items-center justify-center ${className}`}>
      <img
        src={bundledSrc}
        alt={showNetwork ? "" : alt}
        aria-hidden={showNetwork || undefined}
        className="max-h-full w-auto object-contain"
      />
      {networkSrc && !networkFailed ? (
        <img
          src={networkSrc}
          alt={alt}
          className={`absolute left-1/2 top-1/2 max-h-full w-auto -translate-x-1/2 -translate-y-1/2 object-contain transition-opacity duration-200 ${
            networkOk ? "opacity-100" : "opacity-0"
          }`}
          onLoad={() => setNetworkOk(true)}
          onError={() => setNetworkFailed(true)}
        />
      ) : null}
    </span>
  );
}
