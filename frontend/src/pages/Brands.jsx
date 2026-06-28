import React, { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import api from "../lib/api";
import { useLanguage } from "../lib/i18n.jsx";
import { resolveImageUrl } from "../lib/images";

function BrandTile({ brand }) {
  const [logoFailed, setLogoFailed] = useState(false);
  const to = brand.slug
    ? `/brands/${brand.slug}`
    : `/search?q=${encodeURIComponent(brand.name)}`;
  const showLogo = Boolean(brand.logo_url) && !logoFailed;

  return (
    <Link
      to={to}
      className="group flex flex-col items-center gap-3 rounded-2xl border border-zinc-200 bg-white p-6 text-center transition hover:border-zinc-300 hover:shadow-md"
    >
      <div className="flex h-20 w-full items-center justify-center">
        {showLogo ? (
          <img
            src={resolveImageUrl(brand.logo_url)}
            alt={brand.name}
            onError={() => setLogoFailed(true)}
            className="max-h-16 max-w-[10rem] object-contain transition-transform duration-300 group-hover:scale-105"
            loading="lazy"
          />
        ) : (
          <span className="text-lg font-black tracking-tight text-zinc-900">
            {brand.name}
          </span>
        )}
      </div>
      <span className="text-sm font-semibold text-zinc-700 group-hover:text-zinc-900">
        {brand.name}
      </span>
    </Link>
  );
}

export default function Brands() {
  const { t } = useLanguage();
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let active = true;
    (async () => {
      try {
        const { data } = await api.get("/brands");
        if (active) setItems(data?.data || []);
      } catch {
        if (active) setItems([]);
      } finally {
        if (active) setLoading(false);
      }
    })();
    return () => {
      active = false;
    };
  }, []);

  return (
    <div className="container-safe py-10">
      <div className="mb-8">
        <h1 className="text-3xl font-black tracking-tight text-zinc-900">
          {t("brandsLabel")}
        </h1>
      </div>

      {loading ? (
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
          {Array.from({ length: 10 }).map((_, i) => (
            <div
              key={i}
              className="h-36 animate-pulse rounded-2xl bg-zinc-100"
            />
          ))}
        </div>
      ) : items.length === 0 ? (
        <p className="text-sm text-zinc-500">{t("megaMenuNothingYet")}</p>
      ) : (
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
          {items.map((brand) => (
            <BrandTile key={brand.id || brand.slug || brand.name} brand={brand} />
          ))}
        </div>
      )}
    </div>
  );
}
