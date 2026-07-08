import React, { useCallback, useEffect, useMemo, useState } from "react";
import { QRCodeSVG } from "qrcode.react";
import api from "../../lib/api";
import { COD_SETTLEMENT, settlementFromPayment } from "../../lib/codSettlement.js";
import { COURIER_PROVIDERS } from "../../lib/courierTracking.js";
import { toast } from "react-hot-toast";

function defaultCourierFromOrder(order) {
  const provider = order?.shipment?.provider;
  if (!provider || provider === "Internal") return "J&T Express";
  return COURIER_PROVIDERS.includes(provider) ? provider : provider;
}

/**
 * @param {object} props
 * @param {object} props.order
 * @param {boolean} props.canEdit
 * @param {(order: object) => void} [props.onCollected]
 * @param {'admin'|'driver'} [props.apiScope] admin → /admin/orders, driver → /driver/orders
 * @param {import('axios').AxiosInstance} [props.apiClient] defaults to shared admin api
 */
export default function OrderCodCollectionPanel({
  order,
  canEdit,
  onCollected,
  apiScope = "admin",
  apiClient,
}) {
  const http = apiClient || api;
  const orderBase = apiScope === "driver" ? `/driver/orders/${order?.id}` : `/admin/orders/${order?.id}`;
  const [quote, setQuote] = useState(null);
  const [loading, setLoading] = useState(false);
  const [khqr, setKhqr] = useState(null);
  const [polling, setPolling] = useState(false);
  /** @type {'outdoor' | 'khqr' | null} */
  const [channel, setChannel] = useState(null);
  /** @type {'cod_cash_usd' | 'cod_cash_khr' | null} */
  const [cashSettlement, setCashSettlement] = useState(null);
  const [actualAmount, setActualAmount] = useState("");
  const [courierName, setCourierName] = useState("");
  const [notes, setNotes] = useState("");

  const isCod = order?.payment_method === "cod";
  const isCodPending = isCod && order?.payment_status !== "paid";
  const paidSettlement = settlementFromPayment(order?.payments);

  const loadQuote = useCallback(async () => {
    if (!order?.id || !isCodPending) return;
    try {
      const { data } = await http.get(`${orderBase}/cod`);
      setQuote(data?.data || null);
    } catch {
      setQuote(null);
    }
  }, [http, orderBase, order?.id, isCodPending]);

  useEffect(() => {
    loadQuote();
  }, [loadQuote]);

  useEffect(() => {
    setCourierName(defaultCourierFromOrder(order));
  }, [order?.id, order?.shipment?.provider]);

  useEffect(() => {
    if (!polling || !order?.id) return undefined;
    const timer = setInterval(async () => {
      try {
        const { data } = await http.get(`${orderBase}/cod/khqr/status`);
        if (data?.payment?.status === "paid" || data?.order?.payment_status === "paid") {
          setPolling(false);
          toast.success("បានទទួលប្រាក់ KHQR / KHQR payment received.");
          onCollected?.(data?.order);
        }
      } catch {
        // keep polling
      }
    }, 3000);
    return () => clearInterval(timer);
  }, [polling, order?.id, orderBase, http, onCollected]);

  const khrLabel = quote?.khr_amount
    ? `${Number(quote.khr_amount).toLocaleString()} ៛`
    : "—";
  const usdLabel = quote?.usd_amount != null ? `$${Number(quote.usd_amount).toFixed(2)}` : "—";

  const dueForSettlement = useMemo(() => {
    if (cashSettlement === "cod_cash_khr") return quote?.khr_amount ?? "";
    if (cashSettlement === "cod_cash_usd") return quote?.usd_amount ?? "";
    return "";
  }, [cashSettlement, quote?.khr_amount, quote?.usd_amount]);

  useEffect(() => {
    if (dueForSettlement !== "" && dueForSettlement != null) {
      setActualAmount(String(dueForSettlement));
    }
  }, [dueForSettlement]);

  if (!isCod) return null;

  const resetChannel = () => {
    setChannel(null);
    setCashSettlement(null);
    setKhqr(null);
    setPolling(false);
    setNotes("");
    setCourierName(defaultCourierFromOrder(order));
  };

  const collectCash = async () => {
    if (!canEdit || !cashSettlement) return;
    const parsed = Number(actualAmount);
    if (!actualAmount || Number.isNaN(parsed) || parsed < 0) {
      toast.error("បញ្ចូលចំនួនទទួលពិត / Enter the amount received.");
      return;
    }
    setLoading(true);
    try {
      const { data } = await http.post(`${orderBase}/cod/cash`, {
        settlement_method: cashSettlement,
        actual_amount_received: parsed,
        courier_name: courierName.trim() || null,
        notes: notes.trim() || null,
      });
      const label = COD_SETTLEMENT[cashSettlement]?.labelKm || "Payment recorded";
      toast.success(`បានកត់ត្រា: ${label}`);
      onCollected?.(data?.data?.order || data?.order);
    } catch (e) {
      toast.error(e?.response?.data?.message || "Failed to record payment.");
    } finally {
      setLoading(false);
    }
  };

  const generateKhqr = async () => {
    if (!canEdit) return;
    setLoading(true);
    try {
      const { data } = await http.post(`${orderBase}/cod/khqr`, {
        courier_name: courierName.trim() || null,
        notes: notes.trim() || null,
      });
      setKhqr(data?.data?.payment || null);
      setPolling(true);
    } catch (e) {
      toast.error(e?.response?.data?.message || "Failed to generate KHQR.");
    } finally {
      setLoading(false);
    }
  };

  if (!isCodPending && paidSettlement) {
    const showAmountMismatch =
      paidSettlement.expectedAmount != null
      && paidSettlement.actualAmountReceived != null
      && Number(paidSettlement.expectedAmount) !== Number(paidSettlement.actualAmountReceived);

    return (
      <div className="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50/90 p-4 dark:border-emerald-800/50 dark:bg-emerald-950/25">
        <h4 className="text-sm font-bold text-emerald-900 dark:text-emerald-100">
          COD — បានបង់រួច / Paid on delivery
        </h4>
        <div className="mt-2 space-y-1 text-sm text-emerald-800 dark:text-emerald-200">
          <p>
            <span className="font-semibold">របៀបប្រមូល / Channel:</span>{" "}
            {paidSettlement.channelKm || paidSettlement.channelEn}
          </p>
          <p>
            <span className="font-semibold">លម្អិត / Settlement:</span>{" "}
            {paidSettlement.labelKm}
          </p>
          {paidSettlement.actualLabel ? (
            <p>
              <span className="font-semibold">ចំនួនទទួលពិត / Received:</span>{" "}
              {paidSettlement.actualLabel}
              {showAmountMismatch && paidSettlement.expectedLabel ? (
                <span className="ml-1 text-amber-700 dark:text-amber-300">
                  (ត្រូវបង់ {paidSettlement.expectedLabel})
                </span>
              ) : null}
            </p>
          ) : null}
          {paidSettlement.courierName ? (
            <p>
              <span className="font-semibold">អ្នកដឹក / Courier:</span>{" "}
              {paidSettlement.courierName}
            </p>
          ) : null}
          {paidSettlement.notes ? (
            <p>
              <span className="font-semibold">កំណត់ហតុ / Notes:</span>{" "}
              {paidSettlement.notes}
            </p>
          ) : null}
        </div>
      </div>
    );
  }

  if (!isCodPending) return null;

  return (
    <div className="mb-6 rounded-2xl border-2 border-amber-300/60 bg-amber-50/80 p-4 dark:border-amber-500/40 dark:bg-amber-950/20">
      <h4 className="text-sm font-bold text-amber-900 dark:text-amber-100">
        COD — ប្រមូលលុយ / Collect payment
      </h4>
      <p className="mt-1 text-xs text-amber-800/90 dark:text-amber-200/80">
        Customer ជ្រើសបង់ជាមួយអ្នកដឹក។ កត់ត្រាថាប្រមូលតាមលុយក្រៅ (ដុល្លា/រៀល) ឬ Scan KHQR ក្នុងកុង។
      </p>
      <div className="mt-3 flex flex-wrap gap-2 text-xs font-semibold text-slate-700 dark:text-slate-200">
        <span>ត្រូវបង់ / Due: {usdLabel}</span>
        <span>≈ {khrLabel}</span>
        {quote?.exchange_rate ? <span>({quote.exchange_rate} ៛/USD)</span> : null}
      </div>

      {canEdit ? (
        <>
          <p className="mt-4 text-xs font-bold uppercase tracking-wide text-slate-600 dark:text-slate-300">
            ជំហាន ១ — របៀបប្រមូល / Collection type
          </p>
          <div className="mt-2 grid gap-2 sm:grid-cols-2">
            <button
              type="button"
              disabled={loading}
              onClick={() => { setChannel("outdoor"); setKhqr(null); setPolling(false); setCashSettlement(null); }}
              className={`rounded-xl border-2 px-4 py-3 text-left transition-all ${channel === "outdoor"
                ? "border-[#567D74] bg-[#eef3f1] dark:bg-[#2f433e]/40"
                : "border-slate-200 bg-white hover:border-slate-300 dark:border-slate-600 dark:bg-slate-900"
                }`}
            >
              <span className="block text-sm font-bold text-slate-900 dark:text-white">លុយក្រៅ</span>
              <span className="block text-xs text-slate-500 dark:text-slate-400">Outdoor cash — physical money at delivery</span>
            </button>
            <button
              type="button"
              disabled={loading}
              onClick={() => { setChannel("khqr"); setCashSettlement(null); }}
              className={`rounded-xl border-2 px-4 py-3 text-left transition-all ${channel === "khqr"
                ? "border-[#C0272D] bg-red-50 dark:bg-red-950/30"
                : "border-slate-200 bg-white hover:border-slate-300 dark:border-slate-600 dark:bg-slate-900"
                }`}
            >
              <span className="block text-sm font-bold text-slate-900 dark:text-white">ក្នុងកុង (Scan KHQR)</span>
              <span className="block text-xs text-slate-500 dark:text-slate-400">In-app — Bakong / bank app scan</span>
            </button>
          </div>

          {channel === "outdoor" ? (
            <div className="mt-4">
              <p className="text-xs font-bold uppercase tracking-wide text-slate-600 dark:text-slate-300">
                ជំហាន ២ — រូបិយប័ណ្ណ / Currency
              </p>
              <div className="mt-2 grid gap-2 sm:grid-cols-2">
                <button
                  type="button"
                  disabled={loading}
                  onClick={() => setCashSettlement("cod_cash_usd")}
                  className={`rounded-xl border px-4 py-3 text-left transition-all ${cashSettlement === "cod_cash_usd"
                    ? "border-[#567D74] bg-[#eef3f1] dark:bg-[#2f433e]/40"
                    : "border-slate-300 bg-white hover:bg-slate-50 dark:border-slate-600 dark:bg-slate-900"
                    }`}
                >
                  <span className="block text-sm font-bold">ដុល្លារ (USD)</span>
                  <span className="block text-xs text-slate-500">{usdLabel}</span>
                </button>
                <button
                  type="button"
                  disabled={loading}
                  onClick={() => setCashSettlement("cod_cash_khr")}
                  className={`rounded-xl border px-4 py-3 text-left transition-all ${cashSettlement === "cod_cash_khr"
                    ? "border-[#567D74] bg-[#eef3f1] dark:bg-[#2f433e]/40"
                    : "border-slate-300 bg-white hover:bg-slate-50 dark:border-slate-600 dark:bg-slate-900"
                    }`}
                >
                  <span className="block text-sm font-bold">រៀល (KHR)</span>
                  <span className="block text-xs text-slate-500">{khrLabel}</span>
                </button>
              </div>

              {cashSettlement ? (
                <div className="mt-4 space-y-3 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-600 dark:bg-slate-900">
                  <p className="text-xs font-bold uppercase tracking-wide text-slate-600 dark:text-slate-300">
                    ជំហាន ៣ — លម្អិតការប្រមូល / Collection details
                  </p>
                  <label className="block">
                    <span className="text-xs font-semibold text-slate-700 dark:text-slate-200">
                      ចំនួនទទួលពិត / Amount received
                    </span>
                    <input
                      type="number"
                      min="0"
                      step={cashSettlement === "cod_cash_khr" ? "1" : "0.01"}
                      value={actualAmount}
                      onChange={(e) => setActualAmount(e.target.value)}
                      className="mt-1 w-full rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 text-sm tabular-nums dark:border-slate-600 dark:bg-slate-950"
                    />
                  </label>
                  <label className="block">
                    <span className="text-xs font-semibold text-slate-700 dark:text-slate-200">
                      អ្នកដឹក / Courier
                    </span>
                    <select
                      value={courierName}
                      onChange={(e) => setCourierName(e.target.value)}
                      className="mt-1 w-full rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-950"
                    >
                      {COURIER_PROVIDERS.map((name) => (
                        <option key={name} value={name}>{name}</option>
                      ))}
                      {courierName && !COURIER_PROVIDERS.includes(courierName) ? (
                        <option value={courierName}>{courierName}</option>
                      ) : null}
                    </select>
                  </label>
                  <label className="block">
                    <span className="text-xs font-semibold text-slate-700 dark:text-slate-200">
                      កំណត់ហតុ / Notes (optional)
                    </span>
                    <textarea
                      rows={2}
                      value={notes}
                      onChange={(e) => setNotes(e.target.value)}
                      placeholder="ឧ. អតិថិជនបង់គ្រប់, ប្តូរលុយ…"
                      className="mt-1 w-full rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-950"
                    />
                  </label>
                  <button
                    type="button"
                    disabled={loading}
                    onClick={collectCash}
                    className="w-full rounded-xl bg-[#567D74] px-4 py-3 text-sm font-semibold text-white hover:brightness-110 disabled:opacity-60"
                  >
                    កត់ត្រាបង់រួច / Record payment
                  </button>
                </div>
              ) : null}
            </div>
          ) : null}

          {channel === "khqr" ? (
            <div className="mt-4 space-y-3 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-600 dark:bg-slate-900">
              <p className="text-xs font-bold uppercase tracking-wide text-slate-600 dark:text-slate-300">
                ជំហាន ២ — លម្អិត &amp; QR
              </p>
              <label className="block">
                <span className="text-xs font-semibold text-slate-700 dark:text-slate-200">
                  អ្នកដឹក / Courier
                </span>
                <select
                  value={courierName}
                  onChange={(e) => setCourierName(e.target.value)}
                  className="mt-1 w-full rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-950"
                >
                  {COURIER_PROVIDERS.map((name) => (
                    <option key={name} value={name}>{name}</option>
                  ))}
                  {courierName && !COURIER_PROVIDERS.includes(courierName) ? (
                    <option value={courierName}>{courierName}</option>
                  ) : null}
                </select>
              </label>
              <label className="block">
                <span className="text-xs font-semibold text-slate-700 dark:text-slate-200">
                  កំណត់ហតុ / Notes (optional)
                </span>
                <textarea
                  rows={2}
                  value={notes}
                  onChange={(e) => setNotes(e.target.value)}
                  placeholder="ឧ. Customer scan តាម ABA…"
                  className="mt-1 w-full rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-950"
                />
              </label>
              {!khqr?.qr_string ? (
                <button
                  type="button"
                  disabled={loading}
                  onClick={generateKhqr}
                  className="w-full rounded-xl border border-[#C0272D]/40 bg-[#C0272D] px-4 py-3 text-sm font-semibold text-white hover:brightness-110 disabled:opacity-60"
                >
                  បង្កើត KHQR ({khrLabel})
                </button>
              ) : null}
            </div>
          ) : null}

          {channel ? (
            <button
              type="button"
              disabled={loading}
              onClick={resetChannel}
              className="mt-3 text-xs text-slate-500 underline hover:text-slate-700 dark:hover:text-slate-300"
            >
              ប្តូររបៀបប្រមូល / Change collection type
            </button>
          ) : null}
        </>
      ) : (
        <p className="mt-3 text-xs text-slate-500">You do not have permission to record COD payments.</p>
      )}

      {khqr?.qr_string ? (
        <div className="mt-4 flex flex-col items-center rounded-xl border border-[#C0272D]/30 bg-white p-4 dark:bg-slate-950">
          <p className="text-xs font-semibold text-[#C0272D]">ក្នុងកុង — Show QR for customer to scan</p>
          <div className="mt-3 rounded-xl bg-white p-3">
            <QRCodeSVG value={khqr.qr_string} size={200} level="M" includeMargin />
          </div>
          <p className="mt-2 text-sm font-bold tabular-nums">
            {khqr.amount ? Number(khqr.amount).toLocaleString() : ""} {khqr.currency || "KHR"}
          </p>
          {polling ? (
            <p className="mt-1 text-xs text-slate-500">កំពុងរង់ចាំការបង់… / Waiting for payment…</p>
          ) : null}
        </div>
      ) : null}
    </div>
  );
}
