# Fitandsleek — Own Chat AI Plan

> ឯកសារចងក្រងការសម្រេចចិត្ត (Decision doc)  
> កាលបរិច្ឆេទ: 2026-07-30  
> ស្ថានភាព: **Step 1 implemented in code** — ត្រូវ install Ollama ដោយដៃ (មើល `docs/own-chat-ai-setup.md`)

---

## 0. Implementation status (updated)

| ផ្នែក | ស្ថានភាព |
|---|---|
| Laravel → Ollama (Admin AI) | ✅ Done — `AdminAiChatController` |
| Laravel → Ollama (Customer chatbot) | ✅ Done — `ChatbotController` |
| Conversation history (admin) | ✅ Done — `AiConversationStore` (cache) |
| FAQ snippets in prompt | ✅ Done — `ChatFaqContextService` |
| Rate limits | ✅ Done — `admin-ai-chat`, `storefront-ai-chat` |
| Diagnose command | ✅ Done — `php artisan ollama:diagnose` |
| Fine-tune starter dataset | ✅ Done — `docs/ai-finetune/` |
| Install Ollama + pull model | ⏳ **អ្នកត្រូវធ្វើ** — `docs/own-chat-ai-setup.md` |
| Colab fine-tune | ⏳ ក្រោយ chat ដំណើរការ |
| Qdrant for chat RAG | ⏭ មិនចាំបាច់ឥឡូវ (FAQ keyword OK; Qdrant នៅ image search) |

**Setup guide:** [own-chat-ai-setup.md](./own-chat-ai-setup.md)

---

## 1. បញ្ហាបច្ចុប្បន្ន

ឥឡូវ Admin AI Chat រត់តាម stack នេះ:

```
Admin UI → Laravel (AdminAiChatController) → Dify → Gemini
```

| បញ្ហា | ព័ត៌មាន |
|---|---|
| ពឹង platform គេ | Dify (workflow + chat API) |
| ពឹង model គេ | Google Gemini |
| Quota | Gemini free ≈ 20 requests/day → អស់ងាយ |
| គ្រប់គ្រង | មិនមែន 100% របស់ Fitandsleek |

**គោលបំណង:** មាន Chat AI ជារបស់ខ្លួន — អត់ពឹង Dify/Gemini cloud។

---

## 2. អ្វីដែលមានរួចក្នុង project (រក្សាទុក)

| ផ្នែក | ទីតាំង | តួនាទី |
|---|---|---|
| Admin chat API | `backend/app/Http/Controllers/Api/Admin/AdminAiChatController.php` | Proxy chat → Dify |
| Live DB context | `backend/app/Services/AdminDashboardContextService.php` | ផ្តល់លេខពិត (orders, sales…) ទៅ AI |
| Config | `backend/config/services.php` → `dify` | `DIFY_BASE_URL`, `DIFY_API_KEY` |
| Route | `POST /api/admin/ai/chat` | Admin AI endpoint |
| Frontend | `frontend/src/components/admin/AdminAiAssistant.jsx` | Admin chat UI |
| Mobile | `mobile/lib/widgets/admin/admin_ai_sheet.dart` | Admin chat UI |

> **សំខាន់:** `AdminDashboardContextService` ត្រូវរក្សា។  
> Fine-tune / Ollama **មិនជំនួស** ទិន្នន័យ live ពី DB។

> **ចំណាំ:** `ai-service/` បច្ចុប្បន្នជា CLIP image search — **មិនមែន** chat LLM។

---

## 3. កម្រិត “Own AI” (យល់ខុសគ្នា)

| កម្រិត | មានន័យ | សម Fitandsleek? |
|---|---|---|
| **A. Fine-tune** | យក Llama/Qwen បណ្តុះបន្ថែមជាមួយទិន្នន័យ Fitandsleek | បាទ (ជំហាន ២) |
| **B. Train from scratch** | បង្កើត model ពីសូន្យ | ទេ (ថ្លៃ / មិនចាំបាច់) |
| **C. Own stack** | រត់ model លើម៉ាស៊ីនអ្នក (Ollama) + Laravel | បាទ (ជំហាន ១) |

**“Own” ≠ ត្រូវ train ពីសូន្យ។**  
Own = model រត់លើម៉ាស៊ីនដែល **អ្នកគ្រប់គ្រង** (local ឬ VPS)។

---

## 4. ជម្រើសដែលបានពិភាក្សា

### 4.1 Laravel + Ollama (ណែនាំ — ផ្លូវសំខាន់)

```
Admin/Customer UI
        ↓
Laravel API (system prompt + DB context)
        ↓
Ollama (Qwen)  ← លើ Mac/PC សម្រាប់សាក ឬ VPS សម្រាប់ production
        ↓
ចម្លើយត្រឡប់
```

| គុណសម្បត្តិ | គុណវិបត្តិ |
|---|---|
| គ្រប់គ្រង 100% | ត្រូវមានម៉ាស៊ីនគ្រប់គ្រាន់ (RAM/CPU/GPU) |
| អត់ quota ដូច Gemini free | Setup ច្រើនជាង Dify បន្តិច |
| រក្សា DashboardContext បាន | ខ្មែរល្អតែមិនស្មើ Gemini ទាំងស្រុង |
| Privacy — ទិន្នន័យមិនចេញ Google | |

### 4.2 Qdrant + Ollama (RAG)

```
សំណួរ → Qdrant (រក FAQ/docs) → Ollama ឆ្លើយ
```

- **ល្អសម្រាប់:** FAQ, policy, product guide, knowledge docs  
- **មិនជួយ:** “ថ្ងៃនេះមាន order ប៉ុន្មាន?” → ត្រូវ Laravel/DB  
- **សម្រេច:** បន្ថែម **ក្រោយ** បើមាន docs ច្រើន — មិនមែនជំហានទី ១

### 4.3 Google Colab + Query Laravel API

```
Colab (GPU) → ហៅ Laravel API យកទិន្នន័យ → model ឆ្លើយ
```

- **ល្អសម្រាប់:** Fine-tune / experiment (មាន GPU ឥតទិញ)  
- **មិនល្អសម្រាប់:** Production chat (Colab sleep, URL ប្តូរ, មិន stable)  
- **សម្រេច:** ប្រើតែជា **ឧបករណ៍ train** មិនមែន chat server

### 4.4 Open-source chatbot platform (Open WebUI, LibreChat…)

- Self-host បាន តែនៅតែជា platform កណ្តាល  
- Fitandsleek មាន Admin UI រួច → **មិនចាំបាច់**

---

## 5. ការសម្រេចចិត្ត (Recommended path)

```
ជំហាន 1 — Production chat
  Laravel → Ollama (ជំនួស Dify/Gemini)

ជំហាន 2 — Own model tone
  Google Colab fine-tune (LoRA) → export → ដាក់ចូល Ollama

ជំហាន 3 — Knowledge docs (optional)
  បន្ថែម Qdrant បើមាន FAQ/docs ច្រើន
```

**មិនធ្វើ:** Train from scratch · ប្រើ Colab ជា chat server · ពឹង Gemini quota

---

## 6. Local vs Production — ត្រូវ run លើ laptop ទេ?

**ទេ — មិនចាំបាច់តែ laptop។**

| បរិយាកាស | រត់ Ollama នៅណា |
|---|---|
| Dev / សាកល្បង | Mac / PC របស់អ្នក |
| Production | VPS / server របស់អ្នក (ដូច Laravel) |

“Own AI” = ម៉ាស៊ីន **អ្នកគ្រប់គ្រង** — local ឬ VPS បានទាំងពីរ។  
Production មិនត្រូវបើក laptop ទាំងយប់ទេ។

---

## 7. Limit chat — Admin + Customer សួរច្រើន?

| | Gemini (Dify) | Ollama (own) |
|---|---|---|
| Limit API | មាន (free quota) | **អត់** |
| Limit ពិត | Google quota | **CPU / RAM / GPU** + concurrent requests |
| Chat ច្រើន | អស់ quota | យឺត / timeout បើម៉ាស៊ីនខ្សោយ |

**ប្រហែល:**
- ១០–៥០ chat/ថ្ងៃ → ម៉ាស៊ីនធម្មតាគ្រប់គ្រាន់  
- រាប់រយ / ច្រើននាក់ពេលតែមួយ → ត្រូវ server ខ្លាំង ឬ queue  

**Optional ក្នុង Laravel:** rate limit / ដែនកំណត់តាម user (rule របស់អ្នក មិនមែន Google)។

---

## 8. ភាសាខ្មែរ + English

| Model | ខ្មែរ | English | លាយ KH+EN |
|---|---|---|---|
| **Qwen2.5 / Qwen3** (ណែនាំ) | ល្អជាង open models ភាគច្រើន | ល្អ | ល្អ |
| Llama 3.1 / 3.2 | មធ្យម | ល្អខ្លាំង | OK |
| Gemini / GPT | ល្អណាស់ | ល្អណាស់ | ល្អបំផុត |

**វិធីឱ្យល្អជាង:**
1. ជ្រើស **Qwen** សម្រាប់ខ្មែរ  
2. System prompt: ឆ្លើយតាមភាសាអ្នកសួរ  
3. Fine-tune ក្រោយជាមួយឧទាហរណ៍ Fitandsleek (ខ្មែរ)  
4. លេខ order/sales នៅតែមកពី Laravel DB

សម្រាប់ shop + admin chat: **Qwen លើ Ollama គ្រប់គ្រាន់**។

---

## 9. Architecture គោលដៅ

```
┌──────────────────────────────────────────┐
│  TRAIN (ម្តងម្កាល)                         │
│  Google Colab + LoRA                     │
│  → export model → import ទៅ Ollama       │
└──────────────────────────────────────────┘
                    ↓
┌──────────────────────────────────────────┐
│  PRODUCTION (រាល់ថ្ងៃ)                     │
│                                          │
│  UI (Admin / Customer)                   │
│       ↓                                  │
│  Laravel API                             │
│    • system prompt                       │
│    • AdminDashboardContextService (DB)   │
│    • conversation history (optional)     │
│       ↓                                  │
│  Ollama (Qwen / fine-tuned model)        │
│       ↓                                  │
│  (+ Qdrant ក្រោយ — FAQ/docs)              │
└──────────────────────────────────────────┘
```

**តួនាទីច្បាស់:**

| ត្រូវការ | ឧបករណ៍ |
|---|---|
| Live numbers (orders, stock) | Laravel + DB |
| ភាសា / tone Fitandsleek | Ollama model (± fine-tune) |
| FAQ / long docs | Qdrant (optional) |
| Fine-tune GPU | Google Colab |

---

## 10. ផែនការជំហាន (Implementation roadmap)

### ជំហាន 1 — ជំនួស Dify → Ollama
- [x] Ollama config (`services.ollama`) + `.env.example`
- [x] `OllamaChatService` + `AiConversationStore` + `ChatFaqContextService`
- [x] ប្តូរ `AdminAiChatController` ឱ្យហៅ Ollama
- [x] ប្តូរ storefront `ChatbotController` ពី Gemini → Ollama
- [x] Rate limit Admin / Customer
- [x] `php artisan ollama:diagnose`
- [ ] **អ្នក:** Install Ollama + `ollama pull qwen2.5:7b` (មើល setup guide)
- [ ] **អ្នក:** បន្ថែម `OLLAMA_*` ទៅ `backend/.env`
- [ ] **អ្នក:** សាក Admin + Customer chat

### ជំហាន 2 — Fine-tune (optional)
- [x] Dataset template `docs/ai-finetune/`
- [ ] ពង្រីក dataset + Colab LoRA (ក្រោយ)
- [ ] Export → Ollama → ប្តូរ `OLLAMA_MODEL`

### ជំហាន 3 — Production harden
- [ ] ដាក់ Ollama លើ VPS (មិនមែន laptop)
- [ ] Rate limit Admin / Customer
- [ ] Conversation history ក្នុង DB (បើត្រូវ)
- [ ] Customer chat endpoint (បើត្រូវ — ឥឡូវមានចម្បង Admin)

### ជំហាន 4 — Qdrant (optional)
- [ ] បើមាន FAQ/docs ច្រើន → index ទៅ Qdrant
- [ ] Retrieve top chunks → បញ្ចូល prompt មុនហៅ Ollama

---

## 11. Config គំរូ (ពេល implement)

```env
# ជំនួស Dify — មិនប្រើទៀតពេល migrate រួច
# DIFY_BASE_URL=
# DIFY_API_KEY=

OLLAMA_BASE_URL=http://127.0.0.1:11434
OLLAMA_MODEL=qwen2.5
OLLAMA_TIMEOUT=120
```

Ollama chat endpoint (ឧទាហរណ៍):

```
POST {OLLAMA_BASE_URL}/api/chat
```

---

## 12. អ្វីដែលមិនត្រូវភាន់

1. **Fine-tune ≠ ដឹង order ថ្ងៃនេះ** — លេខមកពី DB  
2. **Colab ≠ production server** — ប្រើតែ train  
3. **Qdrant ≠ database orders** — ប្រើតែ docs/FAQ  
4. **Own AI ≠ តែ laptop** — production = VPS របស់អ្នក  
5. **អត់ API quota ≠ ឥត limit** — នៅមាន limit hardware  

---

## 13. ឯកសារពាក់ព័ន្ធ

| ឯកសារ | ខ្លឹមសារ |
|---|---|
| `DFD.md` | System DFD (មាន Dify AI Platform) |
| `backend/app/Http/Controllers/Api/Admin/AdminAiChatController.php` | Chat proxy បច្ចុប្បន្ន |
| `backend/app/Services/AdminDashboardContextService.php` | Live admin context |
| `ai-service/README.md` | CLIP image search (មិនមែន chat) |

---

## 14. សម្រេចចិត្តសង្ខេប

> **ផ្លូវផ្លូវការ:** Laravel + Ollama (Qwen)  
> **Train:** Colab LoRA ក្រោយ  
> **RAG:** Qdrant ក្រោយទៀត បើត្រូវ  
> **លុបពឹង:** Dify + Gemini cloud សម្រាប់ admin chat  

បើចង់ចាប់ផ្តើម code: ធ្វើ **ជំហាន 1** មុន។
