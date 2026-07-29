# Own Chat AI — អ្វីដែលអ្នកត្រូវធ្វើដោយដៃ

Code ក្នុង repo បានភ្ជាប់ **Laravel → Ollama** រួចហើយ (Admin + Customer chatbot)។  
អ្នកត្រូវ install និងទាញ model ម្តង — បើមិនធ្វើ Chat នឹងបាន error `Ollama is not reachable`។

Mac របស់អ្នក: **Apple M3 Pro / 18 GB** → ស័ក្តិសម `qwen2.5:7b`។

---

## ជំហានទី 1 — Install Ollama (ត្រូវធ្វើ)

1. បើក: https://ollama.com/download  
2. Install សម្រាប់ macOS  
3. បើក app **Ollama** (icon នៅ menu bar)

---

## ជំហានទី 2 — ទាញ model (ត្រូវធ្វើ)

បើក Terminal:

```bash
ollama pull qwen2.5:7b
```

(ទាញ ~4–5 GB — រង់ចាំឲ្យរួច)

សាក:

```bash
ollama run qwen2.5:7b "សួស្តី ឆ្លើយខ្មែរខ្លីៗ"
```

---

## ជំហានទី 3 — បន្ថែម config ក្នុង `backend/.env` (ត្រូវធ្វើ)

បើក `backend/.env` រួចបន្ថែម (ឬជំនួស Dify block):

```env
OLLAMA_BASE_URL=http://127.0.0.1:11434
OLLAMA_MODEL=qwen2.5:7b
OLLAMA_TIMEOUT=120
OLLAMA_TEMPERATURE=0.4
OLLAMA_NUM_PREDICT=512
```

> បើមិនបន្ថែមទេ Laravel នៅតែប្រើ default ដូចខាងលើ — ប៉ុន្តែគួរដាក់ច្បាស់ក្នុង `.env`។

បន្ទាប់មក:

```bash
cd backend
php artisan config:clear
php artisan ollama:diagnose --ping-chat
```

ត្រូវឃើញ `✓` ទាំងអស់។

---

## ជំហានទី 4 — សាកក្នុង app

1. ចាប់ផ្តើម backend + frontend ដូចធម្មតា  
2. **Admin** → ប៊ូតុង Ask AI → សួរ «ថ្ងៃនេះមាន order ប៉ុន្មាន?»  
3. **Customer** → chatbot widget → សួរ shipping / return  

---

## Production ក្រោយ (ពេលដាក់ server)

- Install Ollama លើ **VPS** (មិនមែន laptop)  
- កំណត់ `OLLAMA_BASE_URL=http://127.0.0.1:11434` លើ server នោះ (ឬ private IP)  
- កុំបើក Ollama public internet ដោយគ្មាន firewall  

---

## Fine-tune (ជំហាន ២ — ក្រោយ chat ដំណើរការ)

មើល: `docs/ai-finetune/README.md`  
Dataset គំរូ: `docs/ai-finetune/fitandsleek-chat-samples.jsonl`  
→ ប្រើ Google Colab ពេលអ្នករួចរាល់ (មិនចាំបាច់ឥឡូវ)

---

## FAQ / Qdrant

- Chat ឥឡូវទាញ FAQ ពី settings (keyword match) រួចហើយ  
- Qdrant នៅតែសម្រាប់ **image search** — មិនចាំបាច់សម្រាប់ chat ដំណាក់កាលនេះ  

---

## បើមានបញ្ហា

| សារ | ធ្វើអ្វី |
|---|---|
| Ollama is not reachable | បើក app Ollama |
| model not found | `ollama pull qwen2.5:7b` |
| យឺតខ្លាំង | បិទ app ផ្សេងៗ / ប្រើ model តូចជាង `qwen2.5:3b` |
| Too Many Attempts | Rate limit — រង់ចាំ 1 នាទី |
