import React, { useMemo } from "react";
import { resolveImageUrl } from "../lib/images";
import {
  BUNDLED_SITE_LOGO_SRC,
  isBundledSiteLogo,
  resolveBundledLogoUrl,
  siteStaticLogoPath,
} from "../lib/siteLogo.js";

export { siteStaticLogoPath, BUNDLED_SITE_LOGO_SRC };

export default function Logo({ className = "h-12 w-auto", src, alt = "Fitandsleek" }) {
  const displaySrc = useMemo(() => {
    if (!src || isBundledSiteLogo(src)) {
      return siteStaticLogoPath();
    }
    return resolveImageUrl(String(src).trim()) || siteStaticLogoPath();
  }, [src]);

  return (
    <span className={`relative inline-flex items-center justify-center ${className}`}>
      <img
        src={displaySrc}
        alt={alt}
        decoding="sync"
        fetchPriority="high"
        className="max-h-full w-auto object-contain"
      />
    </span>
  );
}

export { resolveBundledLogoUrl, isBundledSiteLogo };
