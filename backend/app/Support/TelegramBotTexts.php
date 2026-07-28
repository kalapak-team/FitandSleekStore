<?php

namespace App\Support;

use App\Models\TelegramUser;

class TelegramBotTexts
{
    public const LOCALE_KM = 'km';

    public const LOCALE_EN = 'en';

    /**
     * @var array<string, array<string, string>>
     */
    private const STRINGS = [
        self::LOCALE_EN => [
            'welcome' => "👋 Welcome to *Fitandsleek* order updates.\n\n"
                ."• Open a tracking link from your order page to subscribe\n"
                ."• `/track ORDER_NUMBER` — check order status\n"
                ."• `/orders` — recent orders (linked account)\n"
                ."• `/language` — choose Khmer or English\n"
                ."• `/help` — show commands",
            'help' => "*Commands*\n\n"
                ."/track ORDER\\_NUMBER — order status\n"
                ."/orders — your recent orders\n"
                ."/language — choose Khmer or English\n"
                ."/help — this message\n\n"
                ."Tip: tap *Invoice* or *Payment QR* on order updates — details appear right here in chat.",
            'language_prompt' => "🌐 *Choose your language*\n\nSelect Khmer or English for bot messages.",
            'language_set_km' => '✅ ភាសាខ្មែរត្រូវបានកំណត់។',
            'language_set_en' => '✅ English is now set.',
            'btn_language' => '🌐 Language / ភាសា',
            'btn_invoice' => '🧾 Invoice',
            'btn_payment_qr' => '💳 Payment QR',
            'btn_payment_qr_cod' => '💳 Scan KHQR (COD)',
            'btn_track_on' => 'Track on :provider',
            'track_usage' => "Usage: `/track FS-123456`\n\nOr open the tracking link from your order confirmation.",
            'order_not_found' => '❌ Order `:key` was not found.',
            'order_subscribed' => "✅ You are now subscribed to order `:label`.\n\nWe will notify you when it ships and delivers.",
            'invalid_account_link' => '❌ Invalid account link. Please open a fresh link from your profile or order page.',
            'account_link_invalid' => '❌ This account link has expired or is invalid.',
            'account_linked' => "✅ Your Telegram is now linked to your Fitandsleek account.\n\nYou will receive order updates here automatically.",
            'order_not_yours' => '❌ This order does not belong to your linked account.',
            'link_account_first' => 'Link your account first using the *Connect Telegram* button in Fitandsleek profile or order page.',
            'no_orders' => 'You have no orders yet.',
            'recent_orders_title' => '📋 *Your recent orders*',
            'track_hint' => 'Use `/track ORDER_NUMBER` for details.',
            'order_confirmed' => "✅ <b>Order confirmed</b>\n\n"
                ."Order: <code>:label</code>\n"
                ."Total: $:total\n"
                ."Status: Preparing for shipment\n\n"
                ."You will receive updates when your order ships.",
            'order_placed_cod' => "🧾 <b>COD order placed</b>\n\n"
                ."Order: <code>:label</code>\n"
                ."Total: $:total\n"
                ."Payment: Pay on delivery\n"
                ."Status: Processing\n\n"
                ."We are preparing your order. You will get updates until it arrives.",
            'order_processing' => "⏳ <b>Order is being prepared</b>\n\n"
                ."Order: <code>:label</code>\n"
                ."Status: Processing / Preparing\n\n"
                ."Our team is packing your items.",
            'order_shipped' => "📦 <b>Order shipped</b>\n\n"
                ."Order: <code>:label</code>\n"
                ."Courier: :provider\n"
                ."Tracking: <code>:tracking</code>",
            'order_in_transit' => "🚚 <b>Order in transit</b>\n\n"
                ."Order: <code>:label</code>\n"
                ."Courier: :provider\n"
                ."Tracking: <code>:tracking</code>\n\n"
                ."Your package is on the way.",
            'order_delivered' => "🎉 <b>Order delivered</b>\n\n"
                ."Order: <code>:label</code>\n"
                .":cod_note"
                ."Thank you for shopping with Fitandsleek!",
            'order_delivered_cod_note' => "Pay on delivery — tap <b>Payment QR</b> below or pay cash.\n\n",
            'order_cancelled' => "❌ <b>Order cancelled</b>\n\n"
                ."Order: <code>:label</code>\n"
                ."Status: Cancelled\n\n"
                ."Contact Fitandsleek if you need help.",
            'order_failed' => "⚠️ <b>Delivery issue</b>\n\n"
                ."Order: <code>:label</code>\n"
                ."Status: Failed\n\n"
                ."Please contact Fitandsleek for support.",
            'order_returned' => "↩️ <b>Order returned</b>\n\n"
                ."Order: <code>:label</code>\n"
                ."Status: Returned\n\n"
                ."Please contact Fitandsleek for support.",
            'admin_new_cod_order' => "🔔 *New COD order*\n\n"
                ."Order: `:label`\n"
                ."Customer: :customer\n"
                ."Phone: :phone\n"
                ."Items: :items\n"
                ."Total: \$:total\n"
                ."Address: :address",
            'order_status_title' => '📋 Order status',
            'order_label' => 'Order',
            'status_label' => 'Status',
            'shipment_label' => 'Shipment',
            'tracking_label' => 'Tracking',
            'payment_pending' => 'Payment: Pending',
            'already_paid' => '✅ This order is already paid.',
            'payment_qr_failed' => '❌ Could not generate payment QR. Please try again later.',
            'payment_qr_failed_gen' => '❌ Failed to generate payment QR. Please try again.',
            'payment_qr_title' => '💳 Payment QR',
            'payment_qr_cod_note' => "\n\nPay on delivery — Scan with ABA / Bakong",
            'payment_qr_scan_note' => "\n\nScan with your banking app to pay",
            'amount_label' => 'Amount',
            'generating_qr' => 'Generating payment QR…',
            'loading_invoice' => 'Loading invoice…',
            'invoice_failed' => '❌ Failed to load invoice. Please try again.',
            'invalid_order' => 'Invalid order.',
            'order_not_found_short' => 'Order not found.',
            'not_subscribed' => 'You are not subscribed to this order.',
            'invoice_title' => 'Invoice',
            'invoice_number' => 'Invoice',
            'invoice_date' => 'Date',
            'invoice_status' => 'Status',
            'customer' => 'Customer',
            'items' => 'Items',
            'qty_label' => 'Qty',
            'subtotal' => 'Subtotal',
            'discount' => 'Discount',
            'shipping' => 'Shipping',
            'grand_total' => 'Grand Total',
            'courier' => 'Courier',
            'payment_cod' => 'Payment: COD — collect on delivery',
            'more_items' => '…and :count more item:plural',
            'guest' => 'Guest',
            'courier_fallback' => 'Courier',
        ],
        self::LOCALE_KM => [
            'welcome' => "👋 *សូមស្វាគមន៍មកកាន់ Fitandsleek* សម្រាប់ធ្វើបច្ចុប្បន្នភាពការបញ្ជាទិញ។\n\n"
                ."• បើកតំណតាមដានពីទំព័រការបញ្ជាទិញរបស់អ្នក\n"
                ."• `/track ORDER_NUMBER` — ពិនិត្យស្ថានភាពការបញ្ជាទិញ\n"
                ."• `/orders` — ការបញ្ជាទិញថ្មីៗ (គណនីភ្ជាប់រួច)\n"
                ."• `/language` — ជ្រើសភាសាខ្មែរ ឬ English\n"
                ."• `/help` — បង្ហាញពាក្យបញ្ជា",
            'help' => "*ពាក្យបញ្ជា*\n\n"
                ."/track ORDER\\_NUMBER — ស្ថានភាពការបញ្ជាទិញ\n"
                ."/orders — ការបញ្ជាទិញថ្មីៗរបស់អ្នក\n"
                ."/language — ជ្រើសភាសាខ្មែរ ឬ English\n"
                ."/help — សារនេះ\n\n"
                ."ព័ត៌មាន: ចុច *វិក័យបត្រ* ឬ *Payment QR* លើការធ្វើបច្ចុប្បន្នភាព — ព័ត៌មានបង្ហាញភ្លាមនៅក្នុង chat។",
            'language_prompt' => "🌐 *ជ្រើសរើសភាសា*\n\nជ្រើសភាសាខ្មែរ ឬ English សម្រាប់សារពី bot។",
            'language_set_km' => '✅ ភាសាខ្មែរត្រូវបានកំណត់។',
            'language_set_en' => '✅ English is now set.',
            'btn_language' => '🌐 ភាសា / Language',
            'btn_invoice' => '🧾 វិក័យបត្រ',
            'btn_payment_qr' => '💳 បង់តាម KHQR',
            'btn_payment_qr_cod' => '💳 Scan KHQR (COD)',
            'btn_track_on' => 'តាមដាន :provider',
            'track_usage' => "របៀបប្រើ: `/track FS-123456`\n\nឬបើកតំណតាមដានពីការបញ្ជាក់ការបញ្ជាទិញរបស់អ្នក។",
            'order_not_found' => '❌ រកមិនឃើញការបញ្ជាទិញ `:key`។',
            'order_subscribed' => "✅ អ្នកបានជាវទទួលធ្វើបច្ចុប្បន្នភាពសម្រាប់ការបញ្ជាទិញ `:label`។\n\nយើងនឹងជូនដំណឹងពេលដឹកចេញ និងដឹកដល់។",
            'invalid_account_link' => '❌ តំណភ្ជាប់គណនីមិនត្រឹមត្រូវ។ សូមបើកតំណថ្មីពីទំព័រប្រវត្តិរូប ឬការបញ្ជាទិញ។',
            'account_link_invalid' => '❌ តំណភ្ជាប់គណនីនេះផុតកំណត់ ឬមិនត្រឹមត្រូវ។',
            'account_linked' => "✅ Telegram របស់អ្នកត្រូវបានភ្ជាប់ទៅគណនី Fitandsleek។\n\nអ្នកនឹងទទួលធ្វើបច្ចុប្បន្នភាពការបញ្ជាទិញនៅទីនេះដោយស្វ័យប្រវត្តិ។",
            'order_not_yours' => '❌ ការបញ្ជាទិញនេះមិនមែនរបស់គណនីភ្ជាប់របស់អ្នកទេ។',
            'link_account_first' => 'សូមភ្ជាប់គណនីជាមុនសិន ដោយចុច *Connect Telegram* នៅទំព័រប្រវត្តិរូប ឬការបញ្ជាទិញ Fitandsleek។',
            'no_orders' => 'អ្នកមិនទាន់មានការបញ្ជាទិញទេ។',
            'recent_orders_title' => '📋 *ការបញ្ជាទិញថ្មីៗរបស់អ្នក*',
            'track_hint' => 'ប្រើ `/track ORDER_NUMBER` សម្រាប់ព័ត៌មានលម្អិត។',
            'order_confirmed' => "✅ <b>ការបញ្ជាទិញបានបញ្ជាក់</b>\n\n"
                ."ការបញ្ជាទិញ: <code>:label</code>\n"
                ."សរុប: $:total\n"
                ."ស្ថានភាព: កំពុងរៀបចំដឹកជញ្ជូន\n\n"
                ."អ្នកនឹងទទួលធ្វើបច្ចុប្បន្នភាពពេលដឹកចេញ។",
            'order_placed_cod' => "🧾 <b>ការបញ្ជាទិញ COD</b>\n\n"
                ."ការបញ្ជាទិញ: <code>:label</code>\n"
                ."សរុប: $:total\n"
                ."ការបង់ប្រាក់: បង់ពេលដឹកដល់\n"
                ."ស្ថានភាព: កំពុងដំណើរការ\n\n"
                ."យើងកំពុងរៀបចំទំនិញ។ អ្នកនឹងទទួលធ្វើបច្ចុប្បន្នភាពរហូតដល់ដឹកដល់។",
            'order_processing' => "⏳ <b>កំពុងរៀបចំការបញ្ជាទិញ</b>\n\n"
                ."ការបញ្ជាទិញ: <code>:label</code>\n"
                ."ស្ថានភាព: កំពុងរៀបចំ\n\n"
                ."ក្រុមការងារកំពុងវេចខ្ចប់ទំនិញរបស់អ្នក។",
            'order_shipped' => "📦 <b>ការបញ្ជាទិញដឹកចេញហើយ</b>\n\n"
                ."ការបញ្ជាទិញ: <code>:label</code>\n"
                ."អ្នកដឹក: :provider\n"
                ."លេខតាមដាន: <code>:tracking</code>",
            'order_in_transit' => "🚚 <b>ទំនិញកំពុងដឹក</b>\n\n"
                ."ការបញ្ជាទិញ: <code>:label</code>\n"
                ."អ្នកដឹក: :provider\n"
                ."លេខតាមដាន: <code>:tracking</code>\n\n"
                ."កញ្ចប់របស់អ្នកកំពុងធ្វើដំណើរ។",
            'order_delivered' => "🎉 <b>ការបញ្ជាទិញដឹកដល់ហើយ</b>\n\n"
                ."ការបញ្ជាទិញ: <code>:label</code>\n"
                .":cod_note"
                ."សូមអរគុណដែលបានទិញពី Fitandsleek!",
            'order_delivered_cod_note' => "បង់ពេលដឹកដល់ — ចុច <b>Payment QR</b> ខាងក្រោម ឬបង់សាច់ប្រាក់។\n\n",
            'order_cancelled' => "❌ <b>ការបញ្ជាទិញត្រូវបានលុបចោល</b>\n\n"
                ."ការបញ្ជាទិញ: <code>:label</code>\n"
                ."ស្ថានភាព: លុបចោល\n\n"
                ."ទាក់ទង Fitandsleek បើត្រូវការជំនួយ។",
            'order_failed' => "⚠️ <b>មានបញ្ហាការដឹក</b>\n\n"
                ."ការបញ្ជាទិញ: <code>:label</code>\n"
                ."ស្ថានភាព: បរាជ័យ\n\n"
                ."សូមទាក់ទង Fitandsleek សម្រាប់ជំនួយ។",
            'order_returned' => "↩️ <b>ការបញ្ជាទិញត្រូវបានត្រឡប់</b>\n\n"
                ."ការបញ្ជាទិញ: <code>:label</code>\n"
                ."ស្ថានភាព: ត្រឡប់\n\n"
                ."សូមទាក់ទង Fitandsleek សម្រាប់ជំនួយ។",
            'admin_new_cod_order' => "🔔 *ការបញ្ជាទិញ COD ថ្មី*\n\n"
                ."ការបញ្ជាទិញ: `:label`\n"
                ."អតិថិជន: :customer\n"
                ."ទូរស័ព្ទ: :phone\n"
                ."មុខទំនិញ: :items\n"
                ."សរុប: \$:total\n"
                ."អាសយដ្ឋាន: :address",
            'order_status_title' => '📋 ស្ថានភាពការបញ្ជាទិញ',
            'order_label' => 'ការបញ្ជាទិញ',
            'status_label' => 'ស្ថានភាព',
            'shipment_label' => 'ការដឹកជញ្ជូន',
            'tracking_label' => 'លេខតាមដាន',
            'payment_pending' => 'ការបង់ប្រាក់: កំពុងរង់ចាំ',
            'already_paid' => '✅ ការបញ្ជាទិញនេះបានបង់រួចហើយ។',
            'payment_qr_failed' => '❌ មិនអាចបង្កើត Payment QR។ សូមព្យាយាមម្តងទៀត។',
            'payment_qr_failed_gen' => '❌ បរាជ័យក្នុងការបង្កើត Payment QR។ សូមព្យាយាមម្តងទៀត។',
            'payment_qr_title' => '💳 Payment QR',
            'payment_qr_cod_note' => "\n\nបង់ពេលដឹកដល់ — Scan ជាមួយ ABA / Bakong",
            'payment_qr_scan_note' => "\n\nScan ជាមួយកម្មវិធីធនាគាររបស់អ្នកដើម្បីបង់",
            'amount_label' => 'ចំនួនទឹកប្រាក់',
            'generating_qr' => 'កំពុងបង្កើត Payment QR…',
            'loading_invoice' => 'កំពុងផ្ទុកវិក័យបត្រ…',
            'invoice_failed' => '❌ មិនអាចផ្ទុកវិក័យបត្រ។ សូមព្យាយាមម្តងទៀត។',
            'invalid_order' => 'ការបញ្ជាទិញមិនត្រឹមត្រូវ។',
            'order_not_found_short' => 'រកមិនឃើញការបញ្ជាទិញ។',
            'not_subscribed' => 'អ្នកមិនទាន់ជាវទទួលធ្វើបច្ចុប្បន្នភាពការបញ្ជាទិញនេះទេ។',
            'invoice_title' => 'វិក័យបត្រ',
            'invoice_number' => 'លេខវិក័យបត្រ',
            'invoice_date' => 'កាលបរិច្ឆេទ',
            'invoice_status' => 'ស្ថានភាព',
            'customer' => 'អតិថិជន',
            'items' => 'ផលិតផល',
            'qty_label' => 'ចំនួន',
            'subtotal' => 'សរុបរង',
            'discount' => 'បញ្ចុះតម្លៃ',
            'shipping' => 'ថ្លៃដឹក',
            'grand_total' => 'សរុបទាំងអស់',
            'courier' => 'អ្នកដឹក',
            'payment_cod' => 'ការបង់ប្រាក់: COD — ប្រមូលពេលដឹកដល់',
            'more_items' => '…និងផលិតផល :count ទៀត',
            'guest' => 'ភ្ញៀវ',
            'courier_fallback' => 'អ្នកដឹក',
        ],
    ];

    public static function resolveLocale(?TelegramUser $user): string
    {
        $preferred = strtolower(trim((string) ($user?->preferred_locale ?? '')));
        if (in_array($preferred, [self::LOCALE_KM, self::LOCALE_EN], true)) {
            return $preferred;
        }

        $telegramLang = strtolower(trim((string) ($user?->language_code ?? '')));
        if ($telegramLang !== '' && (str_starts_with($telegramLang, 'en'))) {
            return self::LOCALE_EN;
        }

        // Fitandsleek default: Khmer (Cambodia storefront).
        return self::LOCALE_KM;
    }

    /**
     * @param  array<string, string|int|float>  $replace
     */
    public static function get(string $locale, string $key, array $replace = []): string
    {
        $locale = in_array($locale, [self::LOCALE_KM, self::LOCALE_EN], true)
            ? $locale
            : self::LOCALE_EN;

        $text = self::STRINGS[$locale][$key] ?? self::STRINGS[self::LOCALE_EN][$key] ?? $key;

        foreach ($replace as $name => $value) {
            if ($name === 'plural' && str_contains($text, ':plural')) {
                $count = (int) ($replace['count'] ?? 0);
                $plural = $count === 1 ? '' : 's';
                if ($locale === self::LOCALE_KM) {
                    $plural = '';
                }
                $text = str_replace(':plural', $plural, $text);
            }
            $text = str_replace(':'.$name, (string) $value, $text);
        }

        return $text;
    }
}
