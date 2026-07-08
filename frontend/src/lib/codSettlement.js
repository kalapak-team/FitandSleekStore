/** Labels for COD settlement methods recorded by admin/driver. */
export const COD_SETTLEMENT = {
  cod_cash_usd: {
    method: "cod_cash_usd",
    channel: "outdoor_cash",
    channelKm: "លុយក្រៅ",
    channelEn: "Outdoor cash",
    currency: "USD",
    labelKm: "លុយក្រៅ — ដុល្លារ (USD)",
    labelEn: "Outdoor cash — USD",
  },
  cod_cash_khr: {
    method: "cod_cash_khr",
    channel: "outdoor_cash",
    channelKm: "លុយក្រៅ",
    channelEn: "Outdoor cash",
    currency: "KHR",
    labelKm: "លុយក្រៅ — រៀល (KHR)",
    labelEn: "Outdoor cash — KHR",
  },
  cod_khqr: {
    method: "cod_khqr",
    channel: "khqr_in_app",
    channelKm: "ក្នុងកុង (Scan KHQR)",
    channelEn: "In-app (KHQR scan)",
    currency: "KHR",
    labelKm: "Scan KHQR — ក្នុងកុង (Bakong)",
    labelEn: "KHQR scan — in-app (Bakong)",
  },
};

export function settlementLabel(method, lang = "km") {
  const row = COD_SETTLEMENT[method];
  if (!row) return method || "—";
  return lang === "en" ? row.labelEn : row.labelKm;
}

function formatAmount(amount, currency) {
  if (amount == null || amount === "") return null;
  const n = Number(amount);
  if (Number.isNaN(n)) return null;
  if (currency === "KHR") return `${n.toLocaleString()} ៛`;
  return `$${n.toFixed(2)}`;
}

export function settlementFromPayment(payments = []) {
  const paid = (payments || []).find((p) => p.status === "paid" || p.status === "success");
  if (!paid?.method) return null;

  const base = COD_SETTLEMENT[paid.method] || {
    method: paid.method,
    labelKm: paid.method,
    labelEn: paid.method,
  };
  const raw = paid.raw_response && typeof paid.raw_response === "object" ? paid.raw_response : {};
  const currency = raw.currency || paid.currency || base.currency;
  const expected = raw.expected_amount ?? paid.amount;
  const actual = raw.actual_amount_received ?? paid.amount;

  return {
    ...base,
    currency,
    expectedAmount: expected,
    actualAmountReceived: actual,
    expectedLabel: formatAmount(expected, currency),
    actualLabel: formatAmount(actual, currency),
    courierName: raw.courier_name || null,
    notes: raw.notes || null,
  };
}
