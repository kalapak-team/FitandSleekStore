import React, { useEffect, useMemo, useState } from "react";
import Hero from "../components/home/Hero.jsx";
import BrandRow from "../components/home/BrandRow.jsx";
import CollectionTiles from "../components/home/CollectionTiles.jsx";
import PromoBannerSlider from "../components/home/PromoBannerSlider.jsx";
import Section from "../components/shop/Section.jsx";
import { useHomepageSettings, writeCachedSettings } from "../state/homepageSettings.jsx";
import { fetchHomeBootstrap } from "../lib/homeBootstrap.js";
import api from "../lib/api";
import { useLanguage } from "../lib/i18n.jsx";
import { useAuth } from "../state/auth.jsx";

const PARENT_SECTION_KEYS = new Set(["women", "men", "boys", "girls"]);
const TAB_SECTION_MAP = { newIn: "new" };
const PARENT_LINK_MAP = { women: "Women", men: "Men", boys: "Boys", girls: "Girls" };

function normalizeToken(value) {
  return String(value || "")
    .toLowerCase()
    .replace(/[_-]+/g, " ")
    .replace(/\s+/g, " ")
    .trim();
}

function pickCategories(categories, names) {
  if (!Array.isArray(categories)) return [];
  const tokens = (names || []).map(normalizeToken).filter(Boolean);
  if (!tokens.length) return [];

  const exactMatches = categories.filter((category) => {
    const name = normalizeToken(category?.name);
    const slug = normalizeToken(category?.slug);
    const type = normalizeToken(category?.type);
    return tokens.some((token) => token === name || token === slug || token === type);
  });

  if (exactMatches.length > 0) return exactMatches;

  return categories.filter((category) => {
    const name = normalizeToken(category?.name);
    const slug = normalizeToken(category?.slug);
    const type = normalizeToken(category?.type);
    return tokens.some(
      (token) =>
        name.includes(token) ||
        slug.includes(token) ||
        type.includes(token)
    );
  });
}

function getSectionSearchTokens(section) {
  const key = normalizeToken(section?.key);
  const title = normalizeToken(section?.title);
  const tokens = [key, title].filter(Boolean);

  if (key.includes("belt") || title.includes("belt")) {
    tokens.push("belts", "belt");
  }

  if (key.includes("vanna") || title.includes("vanna") || title.includes("វ៉ាន់ណា")) {
    tokens.push("vanna", "វ៉ាន់ណា");
  }

  return Array.from(new Set(tokens));
}

function mapBootstrapSections(sectionsPayload) {
  const mapped = {};
  Object.entries(sectionsPayload || {}).forEach(([key, items]) => {
    mapped[key] = {
      loading: false,
      items: Array.isArray(items) ? items : [],
    };
  });
  return mapped;
}

export default function Home() {
  const { settings: homepageSettings } = useHomepageSettings();
  const { user } = useAuth();
  const { t } = useLanguage();
  const [bootstrap, setBootstrap] = useState(null);
  const [bootstrapFailed, setBootstrapFailed] = useState(false);
  const [categories, setCategories] = useState([]);
  const [sections, setSections] = useState({});
  const [recommended, setRecommended] = useState({ loading: true, items: [] });

  useEffect(() => {
    let mounted = true;

    (async () => {
      try {
        const data = await fetchHomeBootstrap();
        if (!mounted) return;

        setBootstrap(data);
        setBootstrapFailed(false);
        setCategories(Array.isArray(data?.categories) ? data.categories : []);
        setSections(mapBootstrapSections(data?.sections));
        setRecommended({
          loading: false,
          items: Array.isArray(data?.recommended) ? data.recommended : [],
        });

        if (data?.settings) {
          writeCachedSettings(data.settings);
        }
      } catch (error) {
        console.error("[Home] /homepage/bootstrap failed:", error?.response?.status, error?.message);
        if (!mounted) return;
        setBootstrapFailed(true);
        setRecommended({ loading: false, items: [] });
      }
    })();

    return () => {
      mounted = false;
    };
  }, []);

  useEffect(() => {
    if (!user || bootstrapFailed) return;
    let mounted = true;

    (async () => {
      try {
        const { data } = await api.get("/products/recommended", { params: { per_page: 8 } });
        if (!mounted) return;
        setRecommended({ loading: false, items: data?.data || [] });
      } catch (error) {
        console.error("Error loading personalized recommendations:", error);
        if (!mounted) return;
      }
    })();

    return () => {
      mounted = false;
    };
  }, [user, bootstrapFailed]);

  const enabledSections = useMemo(() => {
    const settings = bootstrap?.settings || homepageSettings;
    if (!settings?.sections) {
      return [
        { key: "discounts", title: t("discounts"), enabled: true, order: 1 },
        { key: "clothes", title: t("clothes"), enabled: true, order: 2 },
        { key: "shoes", title: t("shoes"), enabled: true, order: 3 },
        { key: "belts", title: t("belts"), enabled: true, order: 4 },
      ]
        .filter((s) => s.enabled)
        .sort((a, b) => a.order - b.order);
    }

    return Object.entries(settings.sections)
      .filter(([_, section]) => section.enabled)
      .map(([key, section]) => ({
        key,
        title: section.title,
        enabled: section.enabled,
        order: section.order,
      }))
      .sort((a, b) => a.order - b.order);
  }, [bootstrap?.settings, homepageSettings, t]);

  const sectionCategories = useMemo(() => {
    const mapped = {};
    enabledSections.forEach((section) => {
      if (section.key === "discounts") return;
      mapped[section.key] = pickCategories(categories, getSectionSearchTokens(section));
    });
    return mapped;
  }, [enabledSections, categories]);

  const getSectionLink = (section) => {
    const key = section?.key;
    const matchedCategories = sectionCategories[key] || [];
    const firstCategory = matchedCategories[0];

    if (key === "discounts") return "/discounts";
    if (key === "newIn") return "/search?tab=new";
    if (PARENT_SECTION_KEYS.has(key)) {
      const parent = PARENT_LINK_MAP[key] || key;
      return `/search?parent_category=${encodeURIComponent(parent)}`;
    }
    if (firstCategory?.slug) return `/category/${firstCategory.slug}`;
    return `/search?search=${encodeURIComponent(section?.title || key || "")}`;
  };

  const heroBanners = bootstrap?.hero_banners;
  const promoBanners = bootstrap?.promo_banners;
  const brandItems = bootstrap?.brands;
  const collectionItems = bootstrap?.collections;

  return (
    <div className="pb-14 w-full">
      <Hero initialBanners={heroBanners} skipFetch={Array.isArray(heroBanners) && !bootstrapFailed} />
      <div className="animate-fade-in">
        <BrandRow initialItems={brandItems} skipFetch={Array.isArray(brandItems) && !bootstrapFailed} />
      </div>
      <div className="animate-fade-in-up delay-100">
        <CollectionTiles
          initialCollections={collectionItems}
          skipFetch={Array.isArray(collectionItems) && !bootstrapFailed}
        />
      </div>

      <div className="animate-fade-in-up delay-200">
        <Section
          title="Recommended for you"
          to="/search?sort=recommend"
          items={recommended.items}
          loading={recommended.loading}
        />
      </div>

      {enabledSections.map((section, index) => (
        <div key={section.key} className={`animate-fade-in-up delay-${(index + 2) * 100}`}>
          <Section
            title={section.title}
            to={getSectionLink(section)}
            items={sections[section.key]?.items || []}
            loading={sections[section.key]?.loading ?? (!bootstrap && !bootstrapFailed)}
            showDiscount={section.key === "discounts"}
          />
          {section.key === "discounts" && (
            <PromoBannerSlider
              initialBanners={promoBanners}
              skipFetch={Array.isArray(promoBanners) && !bootstrapFailed}
            />
          )}
        </div>
      ))}
    </div>
  );
}
