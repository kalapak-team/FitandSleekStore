# Fitandsleek Chat AI — Hugging Face Space

> Space: https://huggingface.co/spaces/Kalapak/fitandsleekAIchat  
> Host: https://kalapak-fitandsleekaichat.hf.space  
> កាលបរិច្ឆេទ: 2026-08-03

## Overview

Admin Ask AI + storefront chatbot call the **trained Gradio Space** (default), not Ollama/Dify/Gemini.

```
Admin / Customer UI
        ↓
Laravel API
        ↓
Hugging Face Space (Gradio /chat)
        ↓
Trained Fitandsleek model + Space FAQ/RAG/DB
```

Ollama remains optional: set `CHAT_AI_PROVIDER=ollama`.

## Env (backend/.env)

```env
CHAT_AI_PROVIDER=huggingface
HF_CHAT_BASE_URL=https://kalapak-fitandsleekaichat.hf.space
HF_CHAT_SPACE_ID=Kalapak/fitandsleekAIchat
HF_CHAT_TIMEOUT=180
# HF_TOKEN=   # only if Space is private
```

On **Render** dashboard: add the same keys (remove reliance on `OLLAMA_BASE_URL=127.0.0.1`).

## Verify

```bash
cd backend
php artisan config:clear
php artisan chat-ai:diagnose --ping-chat
```

## API used by Laravel

1. `POST {HF_CHAT_BASE_URL}/gradio_api/call/chat`  
   Body: `{"data":["message text", null]}`
2. `GET {HF_CHAT_BASE_URL}/gradio_api/call/chat/{event_id}` (SSE)  
   Wait for `event: complete` → `data: ["reply", …]`

## Notes

- Keep the Space **Running** on Hugging Face (CPU basic is fine for FAQ paths; LLM may be slow/cold).
- Admin chat still injects live Laravel dashboard facts into the prompt.
- Customer chat leans on the Space’s own store/FAQ/RAG; Laravel still attaches product cards when useful.
- Free HF Spaces can sleep — first request after idle may take longer (raise `HF_CHAT_TIMEOUT` if needed).

## Related

- [own-chat-ai-plan.md](./own-chat-ai-plan.md)
- [vps-backend-ollama.md](./vps-backend-ollama.md) — only if you switch provider back to Ollama on a VPS
