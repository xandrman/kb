# Платформа корпоративных знаний на базе GraphRAG

MVP конвейера извлечения знаний из неструктурированных документов (PDF, сканы, чертежи) в закрытом контуре. Платформа строит граф знаний и векторный индекс по корпусу документов. Сотрудник получает ответ со ссылками на источники прямо в чате с LLM: вопрос уходит в MCP-инструмент. Доступ разграничен на уровне чанков и узлов графа по ролям Keycloak.

Техническое задание — [`docs/SRS.md`](docs/SRS.md).

## Возможности

- **Админ-панель** (Filament): вход через Keycloak только для роли `kb-admin`, загрузка документов с метаданными, очередь обработки со статусами и повторной обработкой, статистика корпуса, журналы guardrails и чтения ограниченных документов.
- **Обработка документов**: извлечение текста, таблиц и структуры через Docling, OCR сканов через VLM-маршрут, маскирование ПДн до индексации, чанкование, эмбеддинги в Qdrant, граф сущностей и связей в Neo4j со слиянием синонимов.
- **Ответ на вопрос** (MCP-инструмент `search`): гибридный поиск (вектор + граф), реранк, ответ локальной LLM со ссылками на источники. Если источников нет — отказ «нет данных в базе знаний».
- **RBAC**: MCP-сервер проверяет токен Keycloak пользователя. Роль задаёт допуск (общедоступный / служебный / конфиденциальный), и допуск фильтрует чанки и узлы графа ещё до поиска.
- **Guardrails**: на входе — маскирование ПДн и защита от prompt injection, на выходе — маскирование ПДн и обнаружение утечки контекста. Это отдельные контрольные точки (ADR-0030), и каждое срабатывание журналируется.
- **Observability**: сквозной трейс запроса в Tempo, метрики в Prometheus (токены/с, TTFT, latency, GPU/VRAM), логи всех контейнеров в Loki, дашборды в Grafana.

## Архитектура

| Слой | Компоненты |
|---|---|
| Пользовательский канал | LibreChat — система диалогов с LLM (ADR-0024) |
| Прикладной слой (PHP 8, Laravel) | `kb-app`: MCP-сервер, админ-панель, RAG-конвейер на Neuron AI; `kb-worker` и `kb-graph-worker` — очереди обработки |
| Модели (vLLM) | Qwen3.6-35B-A3B INT4 — генерация и OCR; Giga-Embeddings-10B — эмбеддинги; bge-reranker-v2-m3 — реранк |
| Разбор документов | Docling (`kb-docling`, `kb-docling-worker`) |
| Хранилища | Qdrant, Neo4j, PostgreSQL, Redis |
| Идентификация | Keycloak (realm `kb`) |
| Наблюдаемость | Grafana, Prometheus, Tempo, Loki, Alloy, DCGM exporter |
| Периметр | nginx (TLS), сети `kb-dmz` / `kb-internal` |

Диаграммы C4 L1–L3 и Deployment лежат в [`docs/architecture/`](docs/architecture/), Sequence и ER — в [`docs/diagrams/`](docs/diagrams/). Архитектурные решения описаны в [`docs/adr/`](docs/adr/), расчёт ресурсов — в [`docs/capacity-planning.md`](docs/capacity-planning.md).

## Структура репозитория

| Каталог | Содержимое |
|---|---|
| [`backend/`](backend/) | приложение Laravel: MCP-сервер, админ-панель, конвейер обработки, guardrails |
| [`infra/`](infra/) | Terraform-конфигурация всего стека и конфиги сервисов (nginx, Keycloak, LibreChat, стек наблюдаемости) |
| [`docs/`](docs/) | ТЗ, ADR, диаграммы, Capacity Planning |

---

## Развёртывание

Весь стек разворачивается одним `terraform apply` через Docker-провайдер (ADR-0001) на одном сервере.

### Требования

**Оборудование** — эталонный сервер из п. 7.2 ТЗ, подробности в [`docs/capacity-planning.md`](docs/capacity-planning.md):

- 4× NVIDIA RTX 3090 24 GB или эквивалент с суммарной VRAM не меньше 96 GB;
- CPU с 48 ядрами и больше (EPYC);
- RAM не меньше 64 GiB, рекомендуется 128 GiB;
- NVMe не меньше 250 GB свободного места, рекомендуется 1 TB: образы ~40 GB, модели ~45 GB, данные и телеметрия.

**Программное обеспечение:**

- Linux с драйвером NVIDIA;
- Docker Engine с [NVIDIA Container Toolkit](https://docs.nvidia.com/datacenter/cloud-native/container-toolkit/) (runtime `nvidia`);
- Terraform ≥ 1.14.

**Сеть:**

- DNS-имя сервера (`domain_name`), доступное пользователям, и TLS-сертификат на него (например, от корпоративного центра сертификации);
- открытые входящие порты 80 (только перенаправление на HTTPS), 443, 8001, 8002, 8003;
- исходящий доступ в интернет **на время первого развёртывания**, чтобы скачать образы и модели с Hugging Face. После развёртывания модели работают офлайн (`HF_HUB_OFFLINE=1`).

### 1. Переменные

Создайте `infra/terraform.tfvars`. Файл в `.gitignore`, в репозиторий не попадает:

```hcl
domain_name = "kb.example.ru"

# GPU-раскладка (ADR-0009); индексы — из nvidia-smi
vllm_gpus           = "0,1" # генеративная модель, TP=2
vllm_embedding_gpus = "2"
vllm_reranker_gpus  = "3"
docling_gpus        = "3"

postgres_db       = "kb"
postgres_user     = "kb"
postgres_password = "<openssl rand -hex 24>"
neo4j_password    = "<openssl rand -hex 24>"

keycloak_admin_username = "admin"
keycloak_admin_password = "<openssl rand -hex 24>"

# Первый пользователь realm kb с ролью kb-admin; логин по умолчанию — kb-admin
# keycloak_seed_username = "kb-admin"
keycloak_seed_password = "<пароль>"

# Секреты OIDC-клиентов: попадают в realm при импорте и в конфиги сервисов
grafana_oauth_client_secret   = "<openssl rand -hex 32>"
app_oauth_client_secret       = "<openssl rand -hex 32>"
librechat_oauth_client_secret = "<openssl rand -hex 32>"

app_key = "base64:<openssl rand -base64 32>"

librechat_jwt_secret         = "<openssl rand -hex 32>"
librechat_jwt_refresh_secret = "<openssl rand -hex 32>"
librechat_session_secret     = "<openssl rand -hex 32>"
librechat_creds_key          = "<openssl rand -hex 32>"
librechat_creds_iv           = "<openssl rand -hex 16>"

# Ключи API Qdrant: полный — kb-app и воркерам, только на чтение — Prometheus. Значения должны различаться
qdrant_api_key           = "<openssl rand -hex 32>"
qdrant_read_only_api_key = "<openssl rand -hex 32>"
```

Полный список переменных с описаниями — в [`infra/variables.tf`](infra/variables.tf). Пароли PostgreSQL, Neo4j, администратора Keycloak и seed-пользователя применяются только при первом старте с пустыми томами. Если сменить их позже в `tfvars`, пароли в уже развёрнутых сервисах не изменятся.

### 2. Сертификат

Положите сертификат домена и закрытый ключ в формате PEM в `infra/tls/` (ADR-0033). Каталог в `.gitignore`, в репозиторий не попадает:

```bash
mkdir -p infra/tls
cp /путь/к/fullchain.pem infra/tls/fullchain.pem   # сертификат вместе с промежуточными
cp /путь/к/privkey.pem   infra/tls/privkey.pem
chmod 600 infra/tls/privkey.pem
```

Без этих файлов `terraform apply` остановится до создания nginx.

### 3. Применение

```bash
cd infra
terraform init
terraform apply
```

Порядок `apply`: сети, тома и сборка образов `kb-app`, `kb-nginx`, `kb-librechat`, затем хранилища и Keycloak (ждёт готовности realm). После этого стартуют приложение, nginx, LibreChat, модели и стек наблюдаемости.

### 4. Загрузка моделей

Модели скачиваются **после** `apply` контейнерами `kb-hf-cli-*`, это занимает 20 минут и больше: ~45 GB. Пока загрузка не закончилась, контейнеры vLLM перезапускаются — это ожидаемо. Ход загрузки:

```bash
docker logs -f kb-hf-cli-generate
docker logs -f kb-hf-cli-embedding
docker logs -f kb-hf-cli-reranker
docker logs -f kb-hf-cli-embedding-tokenizer
```

Модели готовы, когда все `kb-hf-cli-*` завершились с кодом 0 и в `docker logs kb-vllm-generate` появилась строка `Application startup complete`. Первый старт генеративной модели занимает ~5 минут: загрузка весов, компиляция, захват CUDA-графов.

### 5. Проверка

| Адрес | Сервис | Вход |
|---|---|---|
| `https://<domain_name>/` | LibreChat — чат с MCP-инструментом `kb` | пользователь realm `kb` |
| `https://<domain_name>:8001/admin` | админ-панель | пользователь с ролью `kb-admin` |
| `https://<domain_name>:8002/` | Keycloak | `keycloak_admin_*` — консоль master, пользователи realm `kb` |
| `https://<domain_name>:8003/` | Grafana | пользователь realm `kb` |

Базовая проверка конвейера:

1. Войдите в админ-панель под логином `kb-admin` (или значением `keycloak_seed_username`, если оно задано) и паролем `keycloak_seed_password`.
2. В разделе «Роли» назначьте ролям уровень допуска.
3. Загрузите документ с метаданными и дождитесь статуса «обработан».
4. В LibreChat задайте вопрос по документу: ответ придёт со ссылками на источники.

### Пользователи и доступ

Пользователи и роли заводятся в Keycloak (realm `kb`). При входе и при каждом вызове MCP роли пользователя синхронизируются в приложение. Уровень допуска каждой роли (общедоступный / служебный / конфиденциальный) администратор задаёт в админ-панели, в разделе «Роли». Доступ к админ-панели есть только у роли `kb-admin`.

### Обновление и удаление

- Изменения в `backend/` или `infra/` применяются повторным `terraform apply`: Terraform пересобирает образы по хешам исходников.
- Замена сертификата: положите новые `fullchain.pem` и `privkey.pem` в `infra/tls/` и выполните `docker exec kb-nginx nginx -s reload`.
- Ключи API Qdrant можно ввести или сменить в любой момент. Впишите `qdrant_api_key` и `qdrant_read_only_api_key` в `terraform.tfvars` и выполните `terraform apply`: Terraform пересоздаст `kb-qdrant`, `kb-app`, воркеры и `kb-prometheus`. Данные Qdrant лежат в томе и сохраняются.
- `terraform destroy` удаляет контейнеры, сети **и тома с данными**, в том числе загруженные документы, индексы и модели. Сертификат в `infra/tls/` остаётся.

### Ограничения MVP

- Образы и модели скачиваются из интернета, поэтому для первого развёртывания нужен выход наружу. Установка из заранее загруженных образов и моделей пока не реализована.
- Сертификат не продлевается автоматически: срок действия отслеживает оператор (ADR-0033).
- Секреты передаются через `terraform.tfvars`. Хранение в Vault (п. 6.4 ТЗ) пока не реализовано.
- TLS есть на внешнем периметре (nginx). Каналы между сервисами внутри `kb-internal` пока без TLS, поэтому ключ API Qdrant передаётся по сети открытым текстом.

---

## Разработка

Backend разрабатывается в Laravel Sail внутри `backend/`. Команды Sail, Artisan, Pint и PHPUnit, а также соглашения по коду описаны в [`backend/AGENTS.md`](backend/AGENTS.md).
