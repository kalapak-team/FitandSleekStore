import React, { createContext, useCallback, useContext, useEffect, useState } from "react";
import api from "../lib/api";

const HomepageSettingsContext = createContext();
const CACHE_KEY = "fs_homepage_settings_v1";

function readCachedSettings() {
  if (typeof window === "undefined") return null;
  try {
    const raw = window.localStorage.getItem(CACHE_KEY);
    if (!raw) return null;
    const parsed = JSON.parse(raw);
    return parsed && typeof parsed === "object" ? parsed : null;
  } catch {
    return null;
  }
}

function writeCachedSettings(data) {
  if (typeof window === "undefined" || !data) return;
  try {
    window.localStorage.setItem(CACHE_KEY, JSON.stringify(data));
  } catch {
    /* ignore quota / private mode */
  }
}

export { writeCachedSettings };

/** Sync homepage settings from bootstrap without a separate round-trip. */
export function HomepageSettingsSync({ settings }) {
  const { setSettings } = useHomepageSettings();
  useEffect(() => {
    if (!settings) return;
    setSettings(settings);
    writeCachedSettings(settings);
  }, [settings, setSettings]);
  return null;
}

export function HomepageSettingsProvider({ children }) {
  const [settings, setSettings] = useState(() => readCachedSettings());
  const [loading, setLoading] = useState(() => !readCachedSettings());

  const reloadSettings = useCallback(async () => {
    try {
      const { data } = await api.get("/homepage-settings");
      setSettings(data);
      writeCachedSettings(data);
    } catch (err) {
      console.log("Could not load homepage settings");
    }
  }, []);

  useEffect(() => {
    if (readCachedSettings()) {
      reloadSettings().finally(() => setLoading(false));
      return;
    }
    const timer = window.setTimeout(() => {
      reloadSettings().finally(() => setLoading(false));
    }, 1500);
    return () => window.clearTimeout(timer);
  }, [reloadSettings]);

  return (
    <HomepageSettingsContext.Provider value={{ settings, loading, reloadSettings, setSettings }}>
      {children}
    </HomepageSettingsContext.Provider>
  );
}

export function useHomepageSettings() {
  const context = useContext(HomepageSettingsContext);
  if (!context) {
    throw new Error("useHomepageSettings must be used within HomepageSettingsProvider");
  }
  return context;
}
