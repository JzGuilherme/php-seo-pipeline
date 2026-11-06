## 🎯 Objetivo deste Pull Request

Descreva de forma clara e objetiva o escopo técnico desta entrega e qual problema arquitetural ou de negócio ela resolve.

---

## 🛠️ Principais Alterações Realizadas

- [ ] **Domínio & Contratos (`src/Contracts`, `src/DTO`, `src/Exceptions`):**
- [ ] **Serviços Core & IA (`src/Services`, `src/Providers`):**
- [ ] **Fila Concorrente & Adapters (`src/Queue`, `src/Adapters`):**
- [ ] **Banco de Dados & Infraestrutura (`database/`, `docker/`):**
- [ ] **Testes & Qualidade (`tests/`, `phpstan.neon`):**

---

## 🔒 Checklist de Governança & Segurança (Zero Leak Policy)

- [ ] **Zero Leak Policy:** Confirmei via `git status` e `git diff` que nenhum arquivo `.env`, `.env.local` ou chave real de API (`GEMINI_API_KEY`, credenciais de banco ou tokens REST) foi incluído no histórico.
- [ ] **Análise Estática:** `composer analyse` (PHPStan Level 8) executa sem erros.
- [ ] **Testes Unitários:** `composer test` (PHPUnit 10.5) passa 100% dos cenários (limites de caracteres SEO, sanitização XSS, JSON-LD FAQPage e idempotência SHA-256).
- [ ] **Concorrência & Banco:** Queries na fila preservam o uso dos índices `uk_product_hash` e `idx_queue_poll` com `SELECT ... FOR UPDATE SKIP LOCKED`.
- [ ] **Conventional Commits:** Todos os commits seguem o padrão semântico (`feat:`, `fix:`, `chore:`, `docs:`, `test:`).
