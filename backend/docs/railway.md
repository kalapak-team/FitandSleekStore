# Railway — backend deploy (Fit & Sleek)

Production API is proxied from Netlify (`fitandsleek.online/api/*`) to **one** Railway web service. Use the same hostname everywhere (Netlify `_redirects`, `APP_URL`, OAuth redirect URIs, mobile `API_BASE_URL`).

**Canonical backend URL (current):** `https://fitandsleek-official-production.up.railway.app`

Neon Postgres, Cloudflare R2, and Netlify frontend stay as-is.  
Chat AI + image search run on Hugging Face Spaces (not inside the Railway container).

## Deploy (dashboard)

1. [railway.app](https://railway.app) → New Project → **Deploy from GitHub** (this repo).
2. Service settings:
   - **Root Directory:** `backend`
   - **Builder:** Dockerfile (`backend/Dockerfile` / `railway.toml`)
3. Generate a public domain (Settings → Networking → Generate Domain), or attach a custom domain.
4. Paste variables from **Environment** below (secrets from Render / local `.env`).
5. Deploy → wait until `/api/health` returns `{"ok":true}`.

## CLI (optional)

```bash
cd backend
railway login
railway link          # pick project + service
railway up            # or push to GitHub if connected
railway domain        # generate / list public URL
railway variables     # set env
```

## Environment

Replace `APP_URL` if your Railway hostname differs. Never commit real secrets.

### Core

```env
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:PASTE_FROM_php_artisan_key_generate_show
APP_URL=https://fitandsleek-official-production.up.railway.app
FRONTEND_URL=https://www.fitandsleek.online
SANCTUM_STATEFUL_DOMAINS=fitandsleek.online,www.fitandsleek.online,fitandsleek-official-frontend.netlify.app,fitandsleek-official.vercel.app

DATABASE_URL=postgresql://USER:PASS@HOST/neondb?sslmode=require
DB_SSLMODE=require

CACHE_STORE=database
QUEUE_CONNECTION=database
SESSION_DRIVER=database

RUN_MIGRATIONS=false
RUN_QUEUE_WORKER=false
RUN_SCHEDULER=false
```

### OAuth

```env
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=https://fitandsleek-official-production.up.railway.app/api/auth/google/callback
FACEBOOK_CLIENT_ID=
FACEBOOK_CLIENT_SECRET=
FACEBOOK_REDIRECT_URI=https://fitandsleek-official-production.up.railway.app/api/auth/facebook/callback
MOBILE_OAUTH_CALLBACK_URL=fitandsleek://oauth/callback
```

### Media (Cloudflare R2)

```env
FILESYSTEM_DISK=r2
ADMIN_PROFILE_IMAGE_DISK=r2
MEDIA_FALLBACK_TO_PUBLIC=false
ADMIN_PROFILE_IMAGE_FALLBACK_TO_PUBLIC=false
R2_ACCESS_KEY_ID=
R2_SECRET_ACCESS_KEY=
R2_BUCKET=fitandsleek
R2_ENDPOINT=https://29531ec21763c961814bc2de1cf2aa75.r2.cloudflarestorage.com
R2_PUBLIC_URL=https://media.fitandsleek.online
R2_REGION=auto
R2_USE_PATH_STYLE_ENDPOINT=true
```

### Mail

```env
MAIL_MAILER=resend
MAIL_FROM_ADDRESS=no-reply@fitandsleek.online
MAIL_FROM_NAME=FitandSleek
RESEND_API_KEY=
```

### Payments (Rielpay)

```env
RIELPAY_API_URL=https://api-norng-rielpay.fitandsleek.online
RIELPAY_API_KEY=
RIELPAY_CURRENCY=KHR
RIELPAY_EXPIRES_IN=300
RIELPAY_DEEPLINK_APP_NAME=FitandSleek
RIELPAY_DEEPLINK_ICON_URL=https://www.fitandsleek.online/logo-circle.png
RIELPAY_DEEPLINK_CALLBACK=fitandsleek://payment/callback
```

### Chat AI — Hugging Face Space

https://huggingface.co/spaces/Kalapak/fitandsleekAIchat

```env
HF_CHAT_BASE_URL=https://kalapak-fitandsleekaichat.hf.space
HF_CHAT_SPACE_ID=Kalapak/fitandsleekAIchat
HF_CHAT_TIMEOUT=180
# HF_TOKEN=   # only if the Space is private
```

### Image scan search — Vector Space

https://huggingface.co/spaces/Kalapak/fitandsleek-vector  
Docs: `docs/huggingface-vector-space.md`

```env
FITANDSLEEK_VECTOR_URL=https://kalapak-fitandsleek-vector.hf.space
FITANDSLEEK_VECTOR_API_KEY=fsv_PASTE_YOUR_KEY
QDRANT_COLLECTION=products_fitandsleek_512
QDRANT_VECTOR_SIZE=512
IMAGE_SEARCH_TIMEOUT=90
VECTOR_PROVIDER=fitandsleek_vector
QDRANT_SETUP_ON_BOOT=false
QDRANT_INDEX_MISSING_ON_BOOT=false
```

Do **not** set legacy `QDRANT_URL` / `FITANDSLEEK_CLIP_ENDPOINT` to Docker or Qdrant Cloud unless you intentionally override. `FITANDSLEEK_VECTOR_URL` is enough.

After first deploy (Railway shell / one-off):

```bash
php artisan qdrant:setup
php artisan qdrant:index-products
php artisan qdrant:diagnose
```

### Telegram (optional)

```env
TELEGRAM_BOT_TOKEN=
TELEGRAM_BOT_NAME=fitandsleek_bot
TELEGRAM_WEBHOOK_SECRET=
TELEGRAM_WEBHOOK_URL=https://fitandsleek-official-production.up.railway.app
TELEGRAM_ADMIN_CHAT_ID=
```

Then: `php artisan telegram:set-webhook`

## After deploy is Live

1. Health: `curl -sS "$APP_URL/api/health"` → `{"ok":true}`
2. Confirm Netlify proxy → Railway URL.
3. Confirm mobile `API_BASE_URL` → Railway.
4. Update Google / Facebook OAuth console redirect URIs to Railway callbacks.
5. Set Chat + Vector env vars above; index products once for image search.
6. Suspend or delete the old Render service once traffic is confirmed.

## Verify

```bash
curl -sS "https://fitandsleek-official-production.up.railway.app/api/health"
curl -sS "https://fitandsleek.online/api/health"
php artisan chat-ai:diagnose --ping-chat
php artisan qdrant:diagnose
```
