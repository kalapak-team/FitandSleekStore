import React, { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { ChevronDown, Layers } from "lucide-react";

import api from "../../lib/api";
import { errorAlert, toastSuccess, warningConfirm } from "../../lib/swal";
import { ADMIN_LIST_FIELD_CLASS } from "../../lib/adminListQuery.js";
import AdminModal from "./AdminModal.jsx";
import InventorySellStrategyCard from "./InventorySellStrategyCard.jsx";

const BULK_ACTIONS = [
  { id: "set_on_hold", label: "Set on hold" },
  { id: "release_hold", label: "Release hold" },
  { id: "mark_clearance", label: "Mark as clearance", modal: "clearance" },
  { id: "revert_clearance", label: "Revert to active", confirm: true },
  { id: "apply_lot_discount", label: "Lot discount", modal: "discount" },
  { id: "remove_lot_discount", label: "Remove discount", confirm: true },
];

function previewLine(preview) {
  if (!preview) return "Loading lots…";
  const lots = preview.lot_count ?? 0;
  const products = preview.linked_product_count ?? 0;
  if (lots === 0) return "No inventory lots linked yet";
  return `${lots} lot${lots !== 1 ? "s" : ""} across ${products} product${products !== 1 ? "s" : ""}`;
}

export default function StockInventoryBulkActions({
  selectedIds,
  canEdit = false,
  onDone,
  onClearSelection,
}) {
  const labelIds = useMemo(() => [...selectedIds].map((id) => Number(id)).filter((id) => id > 0), [selectedIds]);
  const [preview, setPreview] = useState(null);
  const [previewLoading, setPreviewLoading] = useState(false);
  const [menuOpen, setMenuOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState("");
  const [discountOpen, setDiscountOpen] = useState(false);
  const [discountPercent, setDiscountPercent] = useState("");
  const [clearanceOpen, setClearanceOpen] = useState(false);
  const [clearancePrice, setClearancePrice] = useState("");
  const [clearancePercent, setClearancePercent] = useState("");
  const [strategyOpen, setStrategyOpen] = useState(false);
  const [sellOldFirst, setSellOldFirst] = useState(true);
  const menuRef = useRef(null);

  const loadPreview = useCallback(async () => {
    if (labelIds.length === 0) {
      setPreview(null);
      return;
    }
    setPreviewLoading(true);
    try {
      const { data } = await api.post("/admin/inventory-lots/bulk", {
        stock_label_ids: labelIds,
        dry_run: true,
      });
      setPreview(data?.data ?? null);
    } catch {
      setPreview(null);
    } finally {
      setPreviewLoading(false);
    }
  }, [labelIds]);

  useEffect(() => {
    void loadPreview();
  }, [loadPreview]);

  useEffect(() => {
    if (!strategyOpen) return;
    (async () => {
      try {
        const { data } = await api.get("/admin/inventory-settings");
        setSellOldFirst(data?.data?.sell_old_first !== false);
      } catch {
        setSellOldFirst(true);
      }
    })();
  }, [strategyOpen]);

  useEffect(() => {
    if (!menuOpen) return undefined;
    const onPointerDown = (e) => {
      if (menuRef.current?.contains(e.target)) return;
      setMenuOpen(false);
    };
    document.addEventListener("mousedown", onPointerDown);
    return () => document.removeEventListener("mousedown", onPointerDown);
  }, [menuOpen]);

  const runBulk = async (action, extra = {}) => {
    if (!canEdit || labelIds.length === 0) return;
    setBusy(true);
    setErr("");
    try {
      const { data } = await api.post("/admin/inventory-lots/bulk", {
        stock_label_ids: labelIds,
        action,
        ...extra,
      });
      const result = data?.data ?? {};
      const updated = result.updated ?? 0;
      const skipped = result.skipped ?? 0;
      const suffix = skipped > 0 ? ` (${skipped} skipped)` : "";
      await toastSuccess({
        enText: `${data?.message || "Bulk update completed."} Updated ${updated} lot${updated !== 1 ? "s" : ""}${suffix}.`,
      });
      setDiscountOpen(false);
      setClearanceOpen(false);
      setMenuOpen(false);
      await loadPreview();
      onDone?.();
    } catch (e) {
      const message = e?.response?.data?.message || "Bulk update failed.";
      setErr(message);
      await errorAlert({ enTitle: "Bulk update failed", enText: message });
    } finally {
      setBusy(false);
    }
  };

  const handleAction = async (action) => {
    setMenuOpen(false);
    if (action.modal === "discount") {
      setDiscountPercent("");
      setDiscountOpen(true);
      setErr("");
      return;
    }
    if (action.modal === "clearance") {
      setClearancePrice("");
      setClearancePercent("");
      setClearanceOpen(true);
      setErr("");
      return;
    }
    if (action.confirm) {
      const res = await warningConfirm({
        enTitle: action.label,
        enText: `Apply "${action.label}" to all inventory lots linked to the ${labelIds.length} selected stock label${labelIds.length !== 1 ? "s" : ""}?`,
        enConfirm: action.label,
      });
      if (!res.isConfirmed) return;
    }
    await runBulk(action.id);
  };

  const submitDiscount = async (e) => {
    e.preventDefault();
    const percent = Number(discountPercent);
    if (!Number.isFinite(percent) || percent <= 0 || percent > 100) {
      setErr("Enter a discount between 0.01 and 100.");
      return;
    }
    await runBulk("apply_lot_discount", { discount_percent: percent });
  };

  const submitClearance = async (e) => {
    e.preventDefault();
    const payload = {};
    if (clearancePrice !== "") {
      const price = Number(clearancePrice);
      if (!Number.isFinite(price) || price < 0) {
        setErr("Enter a valid clearance price or leave blank.");
        return;
      }
      payload.unit_price = price;
    }
    if (clearancePercent !== "") {
      const pct = Number(clearancePercent);
      if (!Number.isFinite(pct) || pct < 0) {
        setErr("Enter a valid clearance percent or leave blank.");
        return;
      }
      payload.price_percent = pct;
    }
    await runBulk("mark_clearance", payload);
  };

  if (labelIds.length === 0) return null;

  const quickActions = BULK_ACTIONS.filter((a) => ["set_on_hold", "release_hold", "mark_clearance"].includes(a.id));
  const moreActions = BULK_ACTIONS.filter((a) => !quickActions.includes(a));

  return (
    <>
      <div className="border-b border-emerald-200/80 bg-emerald-50/80 px-4 py-3 dark:border-emerald-900/50 dark:bg-emerald-950/30">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-2">
              <span className="inline-flex items-center gap-1.5 rounded-full bg-white px-3 py-1 text-xs font-semibold text-emerald-800 ring-1 ring-emerald-200 dark:bg-emerald-950 dark:text-emerald-200 dark:ring-emerald-800">
                <Layers className="h-3.5 w-3.5" aria-hidden />
                {labelIds.length} selected
              </span>
              <span className="text-xs text-emerald-900/80 dark:text-emerald-200/80">
                {previewLoading ? "Checking linked lots…" : previewLine(preview)}
              </span>
            </div>
            <p className="mt-1 text-[11px] text-emerald-900/70 dark:text-emerald-200/70">
              Bulk actions update all inventory lots for linked products. Per-lot editing still works as before.
            </p>
          </div>

          <div className="flex flex-wrap items-center gap-2">
            {canEdit ? (
              <>
                {quickActions.map((action) => (
                  <button
                    key={action.id}
                    type="button"
                    disabled={busy || previewLoading || (preview?.lot_count ?? 0) === 0}
                    onClick={() => void handleAction(action)}
                    className="inline-flex h-9 items-center rounded-lg border border-emerald-300/80 bg-white px-3 text-xs font-semibold text-emerald-900 transition hover:bg-emerald-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-100 dark:hover:bg-emerald-900"
                  >
                    {action.label}
                  </button>
                ))}
                <div className="relative" ref={menuRef}>
                  <button
                    type="button"
                    disabled={busy}
                    onClick={() => setMenuOpen((v) => !v)}
                    className="inline-flex h-9 items-center gap-1 rounded-lg border border-slate-200 bg-white px-3 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100"
                  >
                    More
                    <ChevronDown className="h-4 w-4" aria-hidden />
                  </button>
                  {menuOpen ? (
                    <div className="absolute right-0 z-20 mt-1 min-w-[11rem] rounded-lg border border-slate-200 bg-white py-1 shadow-lg dark:border-slate-700 dark:bg-slate-900">
                      {moreActions.map((action) => (
                        <button
                          key={action.id}
                          type="button"
                          disabled={busy || (preview?.lot_count ?? 0) === 0}
                          onClick={() => void handleAction(action)}
                          className="flex w-full px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50 disabled:opacity-50 dark:text-slate-200 dark:hover:bg-slate-800"
                        >
                          {action.label}
                        </button>
                      ))}
                      <div className="my-1 border-t border-slate-100 dark:border-slate-800" />
                      <button
                        type="button"
                        onClick={() => {
                          setMenuOpen(false);
                          setStrategyOpen(true);
                        }}
                        className="flex w-full px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-slate-800"
                      >
                        Sell old / newer first…
                      </button>
                    </div>
                  ) : null}
                </div>
              </>
            ) : (
              <p className="text-xs text-slate-500 dark:text-slate-400">View only — no bulk edit permission</p>
            )}
            <button
              type="button"
              onClick={onClearSelection}
              className="inline-flex h-9 items-center rounded-lg border border-slate-200 bg-white px-3 text-xs font-medium text-slate-600 hover:bg-slate-50 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-300"
            >
              Clear selection
            </button>
          </div>
        </div>
      </div>

      <AdminModal open={discountOpen} onClose={() => setDiscountOpen(false)} title="Bulk lot discount" maxWidthClass="max-w-md">
        <form onSubmit={(e) => void submitDiscount(e)} className="space-y-4 p-1">
          <p className="text-xs text-slate-500 dark:text-slate-400">
            Apply the same discount percent to {preview?.lot_count ?? "all"} linked inventory lot
            {(preview?.lot_count ?? 0) !== 1 ? "s" : ""}.
          </p>
          {err ? (
            <div className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-800 dark:bg-red-950/40 dark:text-red-200">
              {err}
            </div>
          ) : null}
          <label className="flex flex-col gap-1 text-xs font-medium text-slate-600 dark:text-slate-300">
            Discount off lot price (%)
            <input
              type="number"
              min="0.01"
              max="100"
              step="0.01"
              required
              className={ADMIN_LIST_FIELD_CLASS}
              value={discountPercent}
              onChange={(e) => setDiscountPercent(e.target.value)}
              placeholder="e.g. 20"
            />
          </label>
          <div className="flex justify-end gap-2 pt-2">
            <button type="button" onClick={() => setDiscountOpen(false)} className="inline-flex h-9 items-center rounded-lg border border-slate-200 px-4 text-sm font-medium text-slate-600 dark:border-slate-600 dark:text-slate-300">
              Cancel
            </button>
            <button type="submit" disabled={busy} className="inline-flex h-9 items-center rounded-lg bg-slate-900 px-4 text-sm font-semibold text-white disabled:opacity-60 dark:bg-slate-100 dark:text-slate-900">
              {busy ? "Applying…" : "Apply to all"}
            </button>
          </div>
        </form>
      </AdminModal>

      <AdminModal open={clearanceOpen} onClose={() => setClearanceOpen(false)} title="Bulk mark as clearance" maxWidthClass="max-w-md">
        <form onSubmit={(e) => void submitClearance(e)} className="space-y-4 p-1">
          <p className="text-xs text-slate-500 dark:text-slate-400">
            Mark linked lots as clearance. Leave pricing blank to keep each lot&apos;s current price.
          </p>
          {err ? (
            <div className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-800 dark:bg-red-950/40 dark:text-red-200">
              {err}
            </div>
          ) : null}
          <label className="flex flex-col gap-1 text-xs font-medium text-slate-600 dark:text-slate-300">
            Clearance unit price (optional, same for all)
            <input
              type="number"
              min="0"
              step="0.01"
              className={ADMIN_LIST_FIELD_CLASS}
              value={clearancePrice}
              onChange={(e) => setClearancePrice(e.target.value)}
              placeholder="Leave blank to keep lot prices"
            />
          </label>
          <label className="flex flex-col gap-1 text-xs font-medium text-slate-600 dark:text-slate-300">
            Or % off standard price (optional)
            <input
              type="number"
              min="0"
              max="1000"
              step="0.01"
              className={ADMIN_LIST_FIELD_CLASS}
              value={clearancePercent}
              onChange={(e) => setClearancePercent(e.target.value)}
              placeholder="e.g. 30"
            />
          </label>
          <div className="flex justify-end gap-2 pt-2">
            <button type="button" onClick={() => setClearanceOpen(false)} className="inline-flex h-9 items-center rounded-lg border border-slate-200 px-4 text-sm font-medium text-slate-600 dark:border-slate-600 dark:text-slate-300">
              Cancel
            </button>
            <button type="submit" disabled={busy} className="inline-flex h-9 items-center rounded-lg bg-slate-900 px-4 text-sm font-semibold text-white disabled:opacity-60 dark:bg-slate-100 dark:text-slate-900">
              {busy ? "Applying…" : "Mark all clearance"}
            </button>
          </div>
        </form>
      </AdminModal>

      <AdminModal open={strategyOpen} onClose={() => setStrategyOpen(false)} title="Website sell strategy" maxWidthClass="max-w-lg">
        <div className="space-y-3 p-1">
          <p className="text-xs text-slate-500 dark:text-slate-400">
            Store-wide setting: which lot sells first on the website and POS when multiple lots exist. This applies to all
            stock, including your current selection.
          </p>
          <InventorySellStrategyCard
            canEdit={canEdit}
            sellOldFirst={sellOldFirst}
            onSaved={async () => {
              await toastSuccess({ enText: "Sell strategy saved." });
              onDone?.();
            }}
          />
        </div>
      </AdminModal>
    </>
  );
}
