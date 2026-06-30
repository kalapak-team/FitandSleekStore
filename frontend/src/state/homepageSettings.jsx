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

export function HomepageSettingsProvider({ children }) {
  const [settings, setSettings] = useState(() => readCachedSettings());
  const [loading, setLoading] = useState(true);

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
    (async () => {
      try {
        await reloadSettings();
      } finally {
        setLoading(false);
      }
    })();
  }, [reloadSettings]);

  return (
    <HomepageSettingsContext.Provider value={{ settings, loading, reloadSettings }}>
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
