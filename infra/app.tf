locals {
  app_context = "${path.module}/../backend"

  # Dockerfile копирует контекст целиком (COPY . .), поэтому пересборку обязан вызывать любой
  # попадающий в образ файл: триггер по Dockerfile и lock-файлам пропускал правки в app/, config/ и routes/.
  # Исключения — это backend/.dockerignore плюс storage/ и bootstrap/cache целиком: рантайм-состояние
  # на содержимое образа не влияет. .env.example не хэшируется по той же причине
  app_context_excluded = setunion(
    fileset(local.app_context, "vendor/**"),
    fileset(local.app_context, "node_modules/**"),
    fileset(local.app_context, "tests/**"),
    fileset(local.app_context, "storage/**"),
    fileset(local.app_context, "bootstrap/cache/**"),
    fileset(local.app_context, "public/build/**"),
    fileset(local.app_context, "public/{css,js,fonts}/filament/**"),
    fileset(local.app_context, "public/fonts-manifest.dev.json"),
    fileset(local.app_context, "public/hot"),
    fileset(local.app_context, "database/*.sqlite*"),
    fileset(local.app_context, ".idea/**"),
    fileset(local.app_context, ".phpunit.cache/**"),
    fileset(local.app_context, ".phpunit.result.cache"),
    fileset(local.app_context, ".env"),
    fileset(local.app_context, ".env.*"),
    fileset(local.app_context, "compose.yaml"),
  )

  # Имя файла входит в хэш вместе с содержимым — иначе переименование при том же наборе байт проходит незамеченным
  app_source_hash = sha1(join("", [
    for f in sort(setsubtract(fileset(local.app_context, "**"), local.app_context_excluded)) :
    "${f}:${filesha256("${local.app_context}/${f}")}"
  ]))
}

resource "docker_image" "app" {
  name = "kb-app:1.0.0"

  build {
    context     = local.app_context
    dockerfile  = "Dockerfile"
    pull_parent = true
  }

  triggers = {
    source = local.app_source_hash
  }
}

# ADR-0018: оригиналы и результаты извлечения, адресация по sha256. Монтируется только в контейнеры PHP-слоя
resource "docker_volume" "documents" {
  name = "kb-documents"
}

resource "docker_container" "app" {
  name    = "kb-app"
  image   = docker_image.app.image_id
  restart = "unless-stopped"

  # Логи — драйвером syslog в Alloy (ADR-0032)
  log_driver = "syslog"
  log_opts   = local.syslog_log_opts

  command = ["sh", "-c", "php artisan migrate --force && exec php-fpm"]

  depends_on = [docker_container.postgres, docker_container.redis]

  env = concat([
    "APP_ENV=production",
    "APP_DEBUG=false",
    "APP_KEY=${var.app_key}",
    "APP_URL=https://${var.domain_name}:8001",
    "LOG_CHANNEL=stderr",
    # Bootstrap-роль PostgreSQL: она же владелец таблиц. ADR-0014 требует для приложения роль-невладельца — заводится вместе с первой политикой RLS
    "DB_CONNECTION=pgsql",
    "DB_HOST=kb-postgres",
    "DB_PORT=5432",
    "DB_DATABASE=${var.postgres_db}",
    "DB_USERNAME=${var.postgres_user}",
    "DB_PASSWORD=${var.postgres_password}",
    "REDIS_HOST=kb-redis",
    "REDIS_PORT=6379",
    "QUEUE_CONNECTION=redis",
    "CACHE_STORE=redis",
    "SESSION_DRIVER=redis",
    "DOCUMENTS_ROOT=/data/documents",
    "OTEL_SERVICE_NAME=kb-app",
    # FR-9: спаны запроса по OTLP/HTTP в Alloy (ADR-0015); PHP-SDK шлёт без gRPC-расширения
    "OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=http://kb-alloy:4318/v1/traces",
    # Issuer совпадает с --hostname Keycloak: и браузер, и kb-app ходят по domain_name:8002 (алиас kb-nginx в kb-internal)
    "KEYCLOAK_BASE_URL=https://${var.domain_name}:8002",
    "KEYCLOAK_REALM=kb",
    "KEYCLOAK_CLIENT_ID=kb-app",
    "KEYCLOAK_CLIENT_SECRET=${var.app_oauth_client_secret}",
    "KEYCLOAK_REDIRECT_URI=https://${var.domain_name}:8001/auth/keycloak/callback",
  ], local.ai_services_env)

  upload {
    file    = "/usr/local/etc/php-fpm.d/zz-kb.conf"
    content = file("${path.module}/php-fpm/zz-kb.conf")
  }

  upload {
    file    = "/usr/local/etc/php/conf.d/zz-kb.ini"
    content = file("${path.module}/php-fpm/zz-kb.ini")
  }

  # rw: kb-app — загрузчик админ-панели (ADR-0018). Nginx тома не видит — файлы отдаёт только PHP после проверки доступа
  volumes {
    container_path = "/data/documents"
    volume_name    = docker_volume.documents.name
  }

  healthcheck {
    test     = ["CMD-SHELL", "nc -z 127.0.0.1 9000"]
    interval = "10s"
    timeout  = "3s"
    retries  = 3
  }

  # Порты не публикуются (ТЗ 6.4): FastCGI доступен только из kb-internal, снаружи — через kb-nginx
  networks_advanced {
    name = docker_network.internal.name
  }
}

# ADR-0026: Docker не создаёт подкаталог при подключении subpath — raw/ и extracted/ должны существовать до старта kb-worker.
# Запуск от www-data: каталоги сразу получают владельца php-процессов, chown не нужен; на существующих каталогах mkdir -p ничего не меняет
resource "docker_container" "documents_init" {
  name  = "kb-documents-init"
  image = docker_image.app.image_id
  user  = "www-data"

  # Одноразовый запуск: terraform ждёт завершения и не перезапускает остановленный контейнер
  must_run = false
  attach   = true
  logs     = true

  command = ["mkdir", "-p", "/data/documents/raw", "/data/documents/extracted"]

  volumes {
    container_path = "/data/documents"
    volume_name    = docker_volume.documents.name
  }

  lifecycle {
    # Пересозданный том — снова пустой: инициализация повторяется вместе с ним
    replace_triggered_by = [docker_volume.documents]

    postcondition {
      condition     = self.exit_code == 0
      error_message = "Не удалось создать подкаталоги kb-documents, смотри docker logs kb-documents-init"
    }
  }
}

resource "docker_container" "worker" {
  name    = "kb-worker"
  image   = docker_image.app.image_id
  restart = "unless-stopped"
  user    = "www-data"

  # Логи — драйвером syslog в Alloy (ADR-0032)
  log_driver = "syslog"
  log_opts   = local.syslog_log_opts

  # Задачи короткие: ожидание docling — повторная постановка задачи, а не блокировка процесса, поэтому хватает одного процесса.
  # --tries=0: срок жизни задачи задаёт retryUntil(). --max-time/--memory перезапускают процесс, restart поднимает его снова
  command = [
    "php", "artisan", "queue:work", "redis",
    "--queue=documents,default",
    "--sleep=3",
    "--timeout=120",
    "--tries=0",
    "--max-time=3600",
    "--memory=192",
  ]

  # Миграции выполняет kb-app, подкаталоги raw/ и extracted/ создаёт kb-documents-init (ADR-0026)
  depends_on = [docker_container.app, docker_container.documents_init]

  env = concat(local.worker_env, ["OTEL_SERVICE_NAME=kb-worker"])

  upload {
    file    = "/usr/local/etc/php/conf.d/zz-kb.ini"
    content = file("${path.module}/php-fpm/zz-kb.ini")
  }

  # ADR-0026: оригиналы только на чтение, результат извлечения — на запись; корень тома воркеру не виден
  mounts {
    type      = "volume"
    source    = docker_volume.documents.name
    target    = "/data/documents/raw"
    read_only = true

    volume_options {
      subpath = "raw"
    }
  }

  mounts {
    type   = "volume"
    source = docker_volume.documents.name
    target = "/data/documents/extracted"

    volume_options {
      subpath = "extracted"
    }
  }

  # Больше --timeout: по SIGTERM текущая задача успевает завершиться
  stop_timeout = 150

  healthcheck {
    test     = ["CMD-SHELL", "pgrep -f queue:work"]
    interval = "30s"
    timeout  = "3s"
    retries  = 3
  }

  networks_advanced {
    name = docker_network.internal.name
  }
}

# Окружение задач очереди: общее у kb-worker и kb-graph-worker
locals {
  worker_env = concat([
    "APP_ENV=production",
    "APP_DEBUG=false",
    "APP_KEY=${var.app_key}",
    "APP_URL=https://${var.domain_name}:8001",
    "LOG_CHANNEL=stderr",
    # Bootstrap-роль PostgreSQL: она же владелец таблиц. ADR-0014 требует для приложения роль-невладельца — заводится вместе с первой политикой RLS
    "DB_CONNECTION=pgsql",
    "DB_HOST=kb-postgres",
    "DB_PORT=5432",
    "DB_DATABASE=${var.postgres_db}",
    "DB_USERNAME=${var.postgres_user}",
    "DB_PASSWORD=${var.postgres_password}",
    "REDIS_HOST=kb-redis",
    "REDIS_PORT=6379",
    "QUEUE_CONNECTION=redis",
    "CACHE_STORE=redis",
    "SESSION_DRIVER=redis",
    "DOCUMENTS_ROOT=/data/documents",
    "OTEL_EXPORTER_OTLP_ENDPOINT=http://kb-alloy:4317",
    "OTEL_EXPORTER_OTLP_INSECURE=true",
    # ADR-0012: извлечение в docling, сканы распознаёт модель kb-vllm-generate
    "DOCLING_URL=http://kb-docling:5001",
    "DOCLING_VLM_URL=http://kb-vllm-generate:8000/v1/chat/completions",
    "DOCLING_VLM_MODEL=default",
    "DOCLING_CHUNK_TOKENIZER=${local.docling_tokenizer_path}",
  ], local.ai_services_env)

  # Модели и хранилища знаний: ими пользуются и конвейер (воркеры), и поиск по запросу пользователя (kb-app)
  ai_services_env = [
    # ADR-0009: генеративная модель — извлечение графа (FR-4) и ответ (FR-5)
    "LLM_URL=http://kb-vllm-generate:8000/v1",
    "LLM_MODEL=default",
    # ADR-0010/0006: эмбеддинги и векторный индекс чанков
    "EMBEDDING_URL=http://kb-vllm-embedding:8000/v1",
    "EMBEDDING_MODEL=default",
    "QDRANT_URL=http://kb-qdrant:6333",
    "QDRANT_API_KEY=${var.qdrant_api_key}",
    # ADR-0007: граф знаний
    "NEO4J_URI=bolt://kb-neo4j:7687",
    "NEO4J_USERNAME=neo4j",
    "NEO4J_PASSWORD=${var.neo4j_password}",
    # ADR-0011: реранкер выдачи
    "RERANKER_URL=http://kb-vllm-reranker:8000",
    "RERANKER_MODEL=default",
  ]
}

# Извлечение графа (FR-4): задача на чанк ждёт LLM, поэтому параллельность — число процессов. vLLM держит 16 слотов (ADR-0016),
# половина оставлена чату. Тома документов не нужны: текст чанка приходит в задаче
resource "docker_container" "graph_worker" {
  count   = var.graph_workers
  name    = "kb-graph-worker-${count.index + 1}"
  image   = docker_image.app.image_id
  restart = "unless-stopped"
  user    = "www-data"

  # Логи — драйвером syslog в Alloy (ADR-0032)
  log_driver = "syslog"
  log_opts   = local.syslog_log_opts

  # --tries=0: число попыток задаёт сама задача ($tries)
  command = [
    "php", "artisan", "queue:work", "redis",
    "--queue=graph",
    "--sleep=3",
    "--timeout=120",
    "--tries=0",
    "--max-time=3600",
    "--memory=192",
  ]

  depends_on = [docker_container.app]

  env = concat(local.worker_env, ["OTEL_SERVICE_NAME=kb-graph-worker"])

  upload {
    file    = "/usr/local/etc/php/conf.d/zz-kb.ini"
    content = file("${path.module}/php-fpm/zz-kb.ini")
  }

  stop_timeout = 150

  healthcheck {
    test     = ["CMD-SHELL", "pgrep -f queue:work"]
    interval = "30s"
    timeout  = "3s"
    retries  = 3
  }

  networks_advanced {
    name = docker_network.internal.name
  }
}
