import React, { useEffect, useId, useLayoutEffect, useMemo, useRef, useState } from "react";
import { createPortal } from "react-dom";
import { ChevronDown } from "lucide-react";
import { resolveImageUrl } from "../../lib/images.js";

const MENU_MAX_HEIGHT = 208;
const MENU_GAP = 4;

function filterOptions(options, query) {
  const q = String(query || "").trim().toLowerCase();
  if (!q) return options;
  return options.filter((opt) => {
    const label = String(opt.label ?? "").toLowerCase();
    const value = String(opt.value ?? "").toLowerCase();
    return label.includes(q) || value.includes(q);
  });
}

function SwatchThumb({ imageUrl, label, className = "h-6 w-6" }) {
  const resolved = imageUrl ? resolveImageUrl(imageUrl) : "";
  if (resolved) {
    return (
      <img
        src={resolved}
        alt=""
        className={`${className} shrink-0 rounded border border-slate-200/80 object-cover dark:border-slate-600`}
      />
    );
  }
  return (
    <span
      className={`${className} shrink-0 rounded border border-dashed border-slate-300 bg-slate-100 dark:border-slate-600 dark:bg-slate-700`}
      aria-hidden
      title={label ? `No swatch for ${label}` : "No swatch"}
    />
  );
}

export default function PoLineSearchSelect({
  value = "",
  onChange,
  options = [],
  disabled = false,
  placeholder = "Search…",
  emptyLabel = "— Select —",
  ariaLabel,
  inputId,
  inputClassName = "",
  showOptionImage = false,
  pinnedOptions = [],
  listClassName = "",
}) {
  const listId = useId();
  const rootRef = useRef(null);
  const listRef = useRef(null);
  const inputRef = useRef(null);
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");
  const [activeIndex, setActiveIndex] = useState(0);
  const [menuStyle, setMenuStyle] = useState(null);

  const allOptions = useMemo(() => {
    const pinned = Array.isArray(pinnedOptions) ? pinnedOptions : [];
    const rest = Array.isArray(options) ? options : [];
    const pinnedValues = new Set(pinned.map((o) => String(o.value)));
    return [...pinned, ...rest.filter((o) => !pinnedValues.has(String(o.value)))];
  }, [options, pinnedOptions]);

  const selected = useMemo(
    () => allOptions.find((o) => String(o.value) === String(value)),
    [allOptions, value],
  );

  const filtered = useMemo(() => {
    const list = filterOptions(allOptions, query);
    if (!value || !selected || list.some((o) => String(o.value) === String(value))) {
      return list;
    }
    return [selected, ...list];
  }, [allOptions, query, value, selected]);

  const updateMenuPosition = () => {
    const input = inputRef.current;
    if (!input) return;

    const rect = input.getBoundingClientRect();
    const spaceBelow = window.innerHeight - rect.bottom;
    const spaceAbove = rect.top;
    const shouldOpenUp = spaceBelow < MENU_MAX_HEIGHT && spaceAbove > spaceBelow;

    const maxHeight = Math.max(
      120,
      Math.min(MENU_MAX_HEIGHT, (shouldOpenUp ? spaceAbove : spaceBelow) - MENU_GAP * 2),
    );

    const minWidth = listClassName.includes("min-w-[18rem]")
      ? 288
      : listClassName.includes("min-w-[9rem]")
        ? 144
        : 160;

    setMenuStyle({
      position: "fixed",
      left: rect.left,
      width: Math.max(rect.width, minWidth),
      zIndex: 9999,
      maxHeight,
      ...(shouldOpenUp
        ? { bottom: window.innerHeight - rect.top + MENU_GAP }
        : { top: rect.bottom + MENU_GAP }),
    });
  };

  useLayoutEffect(() => {
    if (!open) {
      setMenuStyle(null);
      return;
    }
    updateMenuPosition();
  }, [open, filtered.length, listClassName]);

  useEffect(() => {
    if (!open) return undefined;

    const onReposition = () => updateMenuPosition();
    window.addEventListener("resize", onReposition);
    window.addEventListener("scroll", onReposition, true);

    return () => {
      window.removeEventListener("resize", onReposition);
      window.removeEventListener("scroll", onReposition, true);
    };
  }, [open, filtered.length, listClassName]);

  useEffect(() => {
    const onPointerDown = (e) => {
      if (rootRef.current?.contains(e.target)) return;
      if (listRef.current?.contains(e.target)) return;
      setOpen(false);
      setQuery("");
    };
    document.addEventListener("mousedown", onPointerDown);
    return () => document.removeEventListener("mousedown", onPointerDown);
  }, []);

  useEffect(() => {
    setActiveIndex(0);
  }, [query, open]);

  const pick = (nextValue) => {
    onChange?.(nextValue == null ? "" : String(nextValue));
    setOpen(false);
    setQuery("");
  };

  const openList = () => {
    if (disabled) return;
    setOpen(true);
    setQuery(selected?.label || "");
    requestAnimationFrame(() => inputRef.current?.select());
  };

  const commitQueryOnBlur = () => {
    const q = String(query || "").trim();
    if (!q) {
      setQuery("");
      return;
    }
    const exact = allOptions.find(
      (o) =>
        String(o.label ?? "").toLowerCase() === q.toLowerCase() ||
        String(o.value ?? "").toLowerCase() === q.toLowerCase(),
    );
    if (exact) pick(exact.value);
    else setQuery("");
  };

  const onKeyDown = (e) => {
    if (!open && (e.key === "ArrowDown" || e.key === "Enter")) {
      e.preventDefault();
      openList();
      return;
    }
    if (!open) return;

    if (e.key === "Escape") {
      e.preventDefault();
      setOpen(false);
      setQuery("");
      return;
    }
    if (e.key === "ArrowDown") {
      e.preventDefault();
      setActiveIndex((i) => Math.min(i + 1, Math.max(0, filtered.length - 1)));
      return;
    }
    if (e.key === "ArrowUp") {
      e.preventDefault();
      setActiveIndex((i) => Math.max(i - 1, 0));
      return;
    }
    if (e.key === "Enter" && filtered[activeIndex]) {
      e.preventDefault();
      pick(filtered[activeIndex].value);
    }
  };

  const displayValue = open ? query : selected?.label || "";
  const showSwatch = showOptionImage && !open && selected?.value;

  const menu =
    open && menuStyle ? (
      <ul
        ref={listRef}
        id={`${listId}-listbox`}
        role="listbox"
        style={menuStyle}
        className={`overflow-y-auto rounded-lg border border-slate-200 bg-white py-1 shadow-lg ring-1 ring-slate-950/5 dark:border-slate-600 dark:bg-slate-900 dark:ring-white/10 ${listClassName}`}
      >
        {filtered.length === 0 ? (
          <li className="px-3 py-2 text-xs text-slate-500 dark:text-slate-400">No matches</li>
        ) : (
          filtered.map((opt, idx) => {
            const isActive = idx === activeIndex;
            const isSelected = String(value) === String(opt.value);
            const isSpecial = Boolean(opt.special);
            return (
              <li key={`${opt.value}-${idx}`} role="option" aria-selected={isSelected}>
                <button
                  type="button"
                  onMouseDown={(e) => e.preventDefault()}
                  onClick={() => pick(opt.value)}
                  className={`flex w-full items-center gap-2 px-2.5 py-2 text-left text-xs transition ${
                    isActive
                      ? "bg-sky-50 text-sky-950 dark:bg-sky-950/50 dark:text-sky-100"
                      : isSelected
                        ? "bg-slate-50 font-semibold text-slate-900 dark:bg-white/5 dark:text-slate-100"
                        : "text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-white/5"
                  } ${isSpecial ? "font-semibold text-[color:var(--admin-primary)]" : ""}`}
                >
                  {showOptionImage ? (
                    <SwatchThumb imageUrl={opt.imageUrl} label={opt.label} className="h-6 w-6" />
                  ) : null}
                  <span className="min-w-0 flex-1 truncate">{opt.label}</span>
                </button>
              </li>
            );
          })
        )}
      </ul>
    ) : null;

  return (
    <div ref={rootRef} className="relative min-w-0">
      <div className="relative flex items-center">
        {showSwatch ? (
          <span className="pointer-events-none absolute left-2 z-[1] flex items-center">
            <SwatchThumb imageUrl={selected?.imageUrl} label={selected?.label} />
          </span>
        ) : null}
        <input
          ref={inputRef}
          id={inputId}
          type="text"
          role="combobox"
          aria-expanded={open}
          aria-autocomplete="list"
          aria-controls={`${listId}-listbox`}
          aria-label={ariaLabel}
          disabled={disabled}
          value={displayValue}
          onChange={(e) => {
            setQuery(e.target.value);
            setOpen(true);
            if (!e.target.value.trim()) onChange?.("");
          }}
          onFocus={openList}
          onBlur={() => {
            window.setTimeout(() => {
              commitQueryOnBlur();
              setOpen(false);
            }, 120);
          }}
          onKeyDown={onKeyDown}
          placeholder={placeholder || emptyLabel}
          className={`${inputClassName} ${showSwatch ? "pl-9" : ""} pr-7`}
          autoComplete="off"
        />
        <button
          type="button"
          tabIndex={-1}
          disabled={disabled}
          onMouseDown={(e) => e.preventDefault()}
          onClick={() => (open ? setOpen(false) : openList())}
          className="absolute right-1 top-1/2 flex h-7 w-7 -translate-y-1/2 items-center justify-center rounded text-slate-400 hover:bg-slate-100 hover:text-slate-600 disabled:opacity-50 dark:hover:bg-slate-800 dark:hover:text-slate-200"
          aria-label="Toggle options"
        >
          <ChevronDown className={`h-3.5 w-3.5 transition ${open ? "rotate-180" : ""}`} aria-hidden />
        </button>
      </div>
      {typeof document !== "undefined" && menu ? createPortal(menu, document.body) : null}
    </div>
  );
}
