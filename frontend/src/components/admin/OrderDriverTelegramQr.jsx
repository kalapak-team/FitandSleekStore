import React, { useEffect, useState } from "react";
import { QrCode } from "lucide-react";
import api from "../../lib/api";

/**
 * Shows a Telegram deep-link QR so a linked driver can open this order job in the bot.
 */
export default function OrderDriverTelegramQr({ orderId, orderNumber }) {
  const [blobUrl, setBlobUrl] = useState(null);
  const [deepLink, setDeepLink] = useState("");
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let revoked = false;
    let objectUrl = null;

    (async () => {
      if (!orderId) return;
      setLoading(true);
      setError("");
      try {
        const [{ data: meta }, png] = await Promise.all([
          api.get(`/admin/orders/${orderId}/driver-telegram-qr`, { params: { format: "json" } }),
          api.get(`/admin/orders/${orderId}/driver-telegram-qr`, { responseType: "blob" }),
        ]);
        if (revoked) return;
        setDeepLink(meta?.data?.deep_link || "");
        objectUrl = URL.createObjectURL(png.data);
        setBlobUrl(objectUrl);
      } catch (err) {
        if (!revoked) {
          setError(err?.response?.data?.message || "Could not load driver Telegram QR");
          setBlobUrl(null);
        }
      } finally {
        if (!revoked) setLoading(false);
      }
    })();

    return () => {
      revoked = true;
      if (objectUrl) URL.revokeObjectURL(objectUrl);
    };
  }, [orderId]);

  return (
    <div className="mb-6 p-4 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/60">
      <h4 className="mb-1 text-sm font-semibold text-slate-700 dark:text-slate-200 flex items-center gap-2">
        <QrCode className="w-4 h-4" />
        Driver Telegram job QR
      </h4>
      <p className="text-xs text-slate-500 dark:text-slate-400 mb-3">
        Driver must be linked once, then scan this QR to open order {orderNumber || `#${orderId}`} in the bot
        (ship / in transit / delivered / KHQR / complete).
      </p>

      {loading ? <p className="text-sm text-slate-500">Loading QR…</p> : null}
      {error ? <p className="text-sm text-red-600 dark:text-red-400">{error}</p> : null}

      {blobUrl ? (
        <div className="flex flex-col sm:flex-row items-start gap-4">
          <img src={blobUrl} alt="Driver Telegram job QR" className="w-40 h-40 rounded-lg bg-white border border-slate-200" />
          {deepLink ? (
            <div className="min-w-0">
              <p className="text-xs font-medium text-slate-600 dark:text-slate-300 mb-1">Deep link</p>
              <a
                href={deepLink}
                target="_blank"
                rel="noreferrer"
                className="break-all text-xs text-[color:var(--admin-primary)] hover:underline"
              >
                {deepLink}
              </a>
            </div>
          ) : null}
        </div>
      ) : null}
    </div>
  );
}
