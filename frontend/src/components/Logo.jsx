import React, { useEffect, useMemo, useState } from "react";
import { resolveImageUrl } from "../lib/images";
import {
  BUNDLED_SITE_LOGO_SRC,
  isBundledSiteLogo,
  normalizeSiteLogoForWeb,
  siteStaticLogoPath,
} from "../lib/siteLogo.js";

export { siteStaticLogoPath, BUNDLED_SITE_LOGO_SRC };

export default function Logo({ className = "h-12 w-auto", src, alt = "Fitandsleek" }) {
  const resolvedSrc = normalizeSiteLogoForWeb(src);
  const bundledSrc = siteStaticLogoPath();
  const [networkOk, setNetworkOk] = useState(false);
  const [networkFailed, setNetworkFailed] = useState(false);

  const networkSrc = useMemo(() => {
    if (!resolvedSrc || isBundledSiteLogo(resolvedSrc)) return null;
    const resolved = resolveImageUrl(resolvedSrc);
    if (!resolved || isBundledSiteLogo(resolved)) return null;
    return resolved;
  }, [resolvedSrc]);

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
        decoding="sync"
        fetchPriority="high"
        className="max-h-full w-auto object-contain"
      />
      {networkSrc && !networkFailed ? (
        <img
          src={networkSrc}
          alt={alt}
          decoding="async"
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
