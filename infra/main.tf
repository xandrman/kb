resource "docker_network" "dmz" {
  name = "kb-dmz"
}

resource "docker_network" "internal" {
  name     = "kb-internal"
  internal = true
}

resource "docker_volume" "nginx_logs" {
  name = "kb-nginx-logs"
}

# Собирается поверх kb-app: public/ копируется из образа приложения, том для этого не нужен.
# pull_parent здесь недопустим — --pull попытается стянуть kb-app из registry
resource "docker_image" "nginx" {
  name = "kb-nginx:1.0.0"

  build {
    context    = "${path.module}/nginx"
    dockerfile = "Dockerfile"
    build_args = { APP_IMAGE = docker_image.app.name }
  }

  # Любая пересборка приложения обязана пересобрать и nginx, иначе копии public/ разойдутся
  triggers = {
    app        = docker_image.app.image_id
    dockerfile = filesha256("${path.module}/nginx/Dockerfile")
  }
}

resource "docker_container" "nginx" {
  name    = "kb-nginx"
  image   = docker_image.nginx.image_id
  restart = "unless-stopped"

  # Без сертификата nginx не загрузит конфиг с ssl_certificate — первичный выпуск должен завершиться до старта
  depends_on = [docker_container.app, docker_container.certbot_init]

  command = ["/bin/sh", "-c", "rm -f /var/log/nginx/access.log /var/log/nginx/error.log && exec /docker-entrypoint.sh nginx -g 'daemon off;'"]

  ports {
    internal = 80
    external = 80
  }

  ports {
    internal = 443
    external = 443
  }

  ports {
    internal = 8001
    external = 8001
  }

  ports {
    internal = 8002
    external = 8002
  }

  ports {
    internal = 8003
    external = 8003
  }

  upload {
    file    = "/etc/nginx/nginx.conf"
    content = file("${path.module}/nginx/nginx.conf")
  }

  upload {
    file    = "/etc/nginx/conf.d/default.conf"
    content = templatefile("${path.module}/nginx/default.conf", { domain_name = var.domain_name })
  }

  upload {
    file = "/etc/nginx/conf.d/kb-app.conf"
    content = templatefile("${path.module}/nginx/kb-app.conf", {
      internal_subnet  = one(docker_network.internal.ipam_config).subnet
      internal_gateway = one(docker_network.internal.ipam_config).gateway
      domain_name      = var.domain_name
    })
  }

  upload {
    file    = "/etc/nginx/conf.d/kb-grafana.conf"
    content = templatefile("${path.module}/nginx/kb-grafana.conf", { domain_name = var.domain_name })
  }

  upload {
    file    = "/etc/nginx/conf.d/kb-keycloak.conf"
    content = templatefile("${path.module}/nginx/kb-keycloak.conf", { domain_name = var.domain_name })
  }

  volumes {
    container_path = "/var/log/nginx"
    volume_name    = docker_volume.nginx_logs.name
  }

  volumes {
    container_path = "/var/www/acme"
    volume_name    = docker_volume.acme_webroot.name
  }

  # Серт и ключ Let's Encrypt из тома certbot; master-процесс nginx (root) читает ключ при загрузке конфига
  volumes {
    container_path = "/etc/letsencrypt"
    volume_name    = docker_volume.certbot_data.name
    read_only      = true
  }

  networks_advanced {
    name = docker_network.dmz.name
  }

  # kb-internal изолирована от внешней сети, а issuer Keycloak прибит к domain_name:8002.
  # Алиас даёт kb-app тот же адрес Keycloak, что и браузеру: один base_url для /auth, /token и /userinfo
  networks_advanced {
    name    = docker_network.internal.name
    aliases = [var.domain_name]
  }
}

resource "docker_volume" "certbot_data" {
  name = "kb-certbot-data"
}

# Общий webroot для ACME HTTP-01: certbot кладёт файл челленджа, nginx его обслуживает.
# Один том в обоих контейнерах по одному пути — файл, который пишет certbot, отдаёт nginx.
resource "docker_volume" "acme_webroot" {
  name = "kb-acme-webroot"
}

# Первичный выпуск сертификата до старта nginx: webroot здесь не работает, потому что nginx без
# сертификата не стартует, а без nginx некому отдать файл челленджа. Standalone-режим certbot сам
# слушает :80. Сеть хоста вместо публикации порта: docker бронирует опубликованный :80 при каждом
# старте контейнера и упал бы на работающем nginx, а certbot занимает порт, только когда выпускает.
# Сертификат уже есть — no-op, продлевает его долгоживущий kb-certbot через webroot
resource "docker_container" "certbot_init" {
  name         = "kb-certbot-init"
  image        = "certbot/certbot:v5.8.0@sha256:f70ad0adbb7e117f0fe42a63c553f28ea451edabc0148757b6efcd9735acaa20"
  network_mode = "host"

  # Одноразовый запуск: terraform ждёт завершения и не перезапускает остановленный контейнер
  must_run = false
  attach   = true
  logs     = true

  entrypoint = ["/bin/sh", "-c"]

  command = ["test -f /etc/letsencrypt/live/${var.domain_name}/fullchain.pem || certbot certonly --standalone -d ${var.domain_name} --non-interactive --agree-tos --register-unsafely-without-email"]

  volumes {
    container_path = "/etc/letsencrypt"
    volume_name    = docker_volume.certbot_data.name
  }

  # Код выхода проверяется явно: при неудачном выпуске apply останавливается до создания nginx
  lifecycle {
    postcondition {
      condition     = self.exit_code == 0
      error_message = "certbot не выпустил сертификат, смотри docker logs kb-certbot-init"
    }
  }
}

# certbot в DMZ: исходящий доступ к ACME-серверу Let's Encrypt. certonly идемпотентен —
# выпускает сертификат, когда его нет, renew-ит, когда срок < 30 дней, иначе no-op.
resource "docker_container" "certbot" {
  name    = "kb-certbot"
  image   = "certbot/certbot:v5.8.0@sha256:f70ad0adbb7e117f0fe42a63c553f28ea451edabc0148757b6efcd9735acaa20"
  restart = "unless-stopped"

  # Webroot-челлендж отдаёт nginx: до его старта первая итерация цикла упала бы и заснула на сутки
  depends_on = [docker_container.nginx]

  # ENTRYPOINT образа — сам бинарник certbot, поэтому shell-цикл должен жить в entrypoint, а не в command
  entrypoint = ["/bin/sh", "-c"]

  command = ["while :; do certbot certonly --webroot -w /var/www/acme -d ${var.domain_name} --non-interactive --agree-tos --register-unsafely-without-email; sleep 86400; done"]

  volumes {
    container_path = "/etc/letsencrypt"
    volume_name    = docker_volume.certbot_data.name
  }

  volumes {
    container_path = "/var/www/acme"
    volume_name    = docker_volume.acme_webroot.name
  }

  networks_advanced {
    name = docker_network.dmz.name
  }
}

resource "docker_volume" "grafana_data" {
  name = "kb-grafana-data"
}

resource "docker_container" "grafana" {
  name    = "kb-grafana"
  image   = "grafana/grafana:13.2.2@sha256:ac461fb352abc50da10a51c7d02462e9c05488f11f53f14b3ad79a8145f638a0"
  restart = "unless-stopped"

  env = [
    "GF_SERVER_ROOT_URL=https://${var.domain_name}:8003",
    "GF_SERVER_DOMAIN=${var.domain_name}",

    "GF_AUTH_DISABLE_LOGIN_FORM=true",
    "GF_SECURITY_DISABLE_INITIAL_ADMIN_CREATION=true",
    "GF_AUTH_GENERIC_OAUTH_ENABLED=true",
    "GF_AUTH_GENERIC_OAUTH_NAME=Keycloak",
    "GF_AUTH_GENERIC_OAUTH_AUTO_LOGIN=true",
    "GF_AUTH_GENERIC_OAUTH_ALLOW_SIGN_UP=true",
    "GF_AUTH_GENERIC_OAUTH_CLIENT_ID=grafana",
    "GF_AUTH_GENERIC_OAUTH_CLIENT_SECRET=${var.grafana_oauth_client_secret}",
    "GF_AUTH_GENERIC_OAUTH_SCOPES=openid email profile roles",
    "GF_AUTH_GENERIC_OAUTH_USE_PKCE=true",
    "GF_AUTH_GENERIC_OAUTH_USE_REFRESH_TOKEN=true",

    "GF_AUTH_GENERIC_OAUTH_AUTH_URL=https://${var.domain_name}:8002/realms/kb/protocol/openid-connect/auth",
    "GF_AUTH_GENERIC_OAUTH_TOKEN_URL=http://kb-keycloak:8080/realms/kb/protocol/openid-connect/token",
    "GF_AUTH_GENERIC_OAUTH_API_URL=http://kb-keycloak:8080/realms/kb/protocol/openid-connect/userinfo",

    "GF_AUTH_GENERIC_OAUTH_LOGIN_ATTRIBUTE_PATH=preferred_username",
    "GF_AUTH_GENERIC_OAUTH_EMAIL_ATTRIBUTE_PATH=email",
    "GF_AUTH_GENERIC_OAUTH_NAME_ATTRIBUTE_PATH=name",
    "GF_AUTH_GENERIC_OAUTH_ROLE_ATTRIBUTE_PATH=contains(realm_access.roles[*], 'kb-admin') && 'GrafanaAdmin' || ''",
    "GF_AUTH_GENERIC_OAUTH_ROLE_ATTRIBUTE_STRICT=true",
    "GF_AUTH_GENERIC_OAUTH_ALLOW_ASSIGN_GRAFANA_ADMIN=true",

    "GF_AUTH_SIGNOUT_REDIRECT_URL=https://${var.domain_name}:8002/realms/kb/protocol/openid-connect/logout?post_logout_redirect_uri=${urlencode("https://${var.domain_name}:8003/login")}&client_id=grafana",
  ]

  upload {
    file    = "/etc/grafana/provisioning/datasources/datasources.yml"
    content = file("${path.module}/grafana/datasources.yml")
  }

  volumes {
    container_path = "/var/lib/grafana"
    volume_name    = docker_volume.grafana_data.name
  }

  networks_advanced {
    name = docker_network.internal.name
  }
}

resource "docker_volume" "tempo_data" {
  name = "kb-tempo-data"
}

resource "docker_container" "tempo" {
  name    = "kb-tempo"
  image   = "grafana/tempo:3.0.3@sha256:0296560ac66f8a3600d7fb3014a52c189d4d9c3549ad6ff441bf2409855d68d5"
  restart = "unless-stopped"

  command = ["-target=all", "-config.file", "/etc/tempo/tempo.yaml"]

  upload {
    file    = "/etc/tempo/tempo.yaml"
    content = file("${path.module}/tempo/tempo.yaml")
  }

  volumes {
    container_path = "/var/tempo"
    volume_name    = docker_volume.tempo_data.name
  }

  networks_advanced {
    name = docker_network.internal.name
  }
}

resource "docker_container" "alloy" {
  name    = "kb-alloy"
  image   = "grafana/alloy:v1.19.2@sha256:b8ec653c44235fbe910879145dac3597d66b0aaecf60bcbbe82580767771a839"
  restart = "unless-stopped"

  command = ["run", "--server.http.listen-addr=0.0.0.0:12345", "/etc/alloy/config.alloy"]

  upload {
    file    = "/etc/alloy/config.alloy"
    content = file("${path.module}/alloy/config.alloy")
  }

  volumes {
    container_path = "/var/log/nginx"
    volume_name    = docker_volume.nginx_logs.name
    read_only      = true
  }

  networks_advanced {
    name = docker_network.internal.name
  }
}

resource "docker_volume" "loki_data" {
  name = "kb-loki-data"
}

resource "docker_container" "loki" {
  name    = "kb-loki"
  image   = "grafana/loki:3.7.8@sha256:81a6802ec4bd1b88c564494f06376889ed022998a188826190d26d2754ac2aae"
  restart = "unless-stopped"
  user    = "root"

  command = ["-target=all", "-config.file", "/etc/loki/loki.yaml"]

  upload {
    file    = "/etc/loki/loki.yaml"
    content = file("${path.module}/loki/loki.yaml")
  }

  volumes {
    container_path = "/var/loki"
    volume_name    = docker_volume.loki_data.name
  }

  networks_advanced {
    name = docker_network.internal.name
  }
}

resource "docker_volume" "keycloak_data" {
  name = "kb-keycloak-data"
}

resource "docker_volume" "prometheus_data" {
  name = "kb-prometheus-data"
}

resource "docker_container" "prometheus" {
  name    = "kb-prometheus"
  image   = "prom/prometheus:v3.14.0@sha256:e906cef998316bbe319f98711e1b4d8613ad37e14b08ff831d7036e77b7464f9"
  restart = "unless-stopped"

  command = [
    "--config.file=/etc/prometheus/prometheus.yml",
    "--storage.tsdb.path=/prometheus",
    "--storage.tsdb.retention.time=30d",
    "--web.enable-remote-write-receiver",
    "--enable-feature=exemplar-storage",
  ]

  upload {
    file    = "/etc/prometheus/prometheus.yml"
    content = file("${path.module}/prometheus/prometheus.yml")
  }

  volumes {
    container_path = "/prometheus"
    volume_name    = docker_volume.prometheus_data.name
  }

  networks_advanced {
    name = docker_network.internal.name
  }
}

resource "docker_container" "dcgm_exporter" {
  name    = "kb-dcgm-exporter"
  image   = "nvidia/dcgm-exporter:4.6.1-4.8.4-distroless@sha256:148b0c025e5f2850256816fa33754fbf4733b7a086697a95613fc0ba3db5b003"
  restart = "unless-stopped"
  runtime = "nvidia"

  env = [
    "NVIDIA_VISIBLE_DEVICES=all",
    "NVIDIA_DRIVER_CAPABILITIES=compute,utility",
  ]

  networks_advanced {
    name = docker_network.internal.name
  }
}

resource "docker_volume" "qdrant_data" {
  name = "kb-qdrant-data"
}

resource "docker_container" "qdrant" {
  name    = "kb-qdrant"
  image   = "qdrant/qdrant:v1.19.1@sha256:0699e7733a6fa7fa7f6b95dcbed84ebb04584110da525cdfdef9f305c4f57738"
  restart = "unless-stopped"

  volumes {
    container_path = "/qdrant/storage"
    volume_name    = docker_volume.qdrant_data.name
  }

  networks_advanced {
    name = docker_network.internal.name
  }
}

resource "docker_volume" "neo4j_data" {
  name = "kb-neo4j-data"
}

resource "docker_container" "neo4j" {
  name    = "kb-neo4j"
  image   = "neo4j:2026.08.1@sha256:d8f4c156caa3af76499134947deb11d13042e471d9733060449f2a01eb7a248e"
  restart = "unless-stopped"

  volumes {
    container_path = "/data"
    volume_name    = docker_volume.neo4j_data.name
  }

  networks_advanced {
    name = docker_network.internal.name
  }
}

resource "docker_volume" "postgres_data" {
  name = "kb-postgres-data"
}

resource "docker_container" "postgres" {
  name    = "kb-postgres"
  image   = "postgres:18.6-alpine@sha256:d8703cd7fba306b9fec9268ecedfa8a966846c053036a60e3635791957eb2f66"
  restart = "unless-stopped"

  env = [
    "POSTGRES_DB=${var.postgres_db}",
    "POSTGRES_USER=${var.postgres_user}",
    "POSTGRES_PASSWORD=${var.postgres_password}",
  ]

  # В образе 18 PGDATA перенесён в /var/lib/postgresql/18/docker, том объявлен на /var/lib/postgresql
  volumes {
    container_path = "/var/lib/postgresql"
    volume_name    = docker_volume.postgres_data.name
  }

  networks_advanced {
    name = docker_network.internal.name
  }
}

resource "docker_volume" "redis_data" {
  name = "kb-redis-data"
}

resource "docker_container" "redis" {
  name    = "kb-redis"
  image   = "redis:8.10.2-alpine@sha256:2d3814be5e9b06a30a0be54770b7e12052e7e79ec85271aefd34875c1f393b23"
  restart = "unless-stopped"

  volumes {
    container_path = "/data"
    volume_name    = docker_volume.redis_data.name
  }

  networks_advanced {
    name = docker_network.internal.name
  }
}

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

  command = ["sh", "-c", "php artisan migrate --force && exec php-fpm"]

  depends_on = [docker_container.postgres, docker_container.redis]

  env = [
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
    "OTEL_EXPORTER_OTLP_ENDPOINT=http://kb-alloy:4317",
    "OTEL_EXPORTER_OTLP_INSECURE=true",
    # Issuer совпадает с --hostname Keycloak: и браузер, и kb-app ходят по domain_name:8002 (алиас kb-nginx в kb-internal)
    "KEYCLOAK_BASE_URL=https://${var.domain_name}:8002",
    "KEYCLOAK_REALM=kb",
    "KEYCLOAK_CLIENT_ID=kb-app",
    "KEYCLOAK_CLIENT_SECRET=${var.app_oauth_client_secret}",
    "KEYCLOAK_REDIRECT_URI=https://${var.domain_name}:8001/auth/keycloak/callback",
  ]

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

  env = [
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
    "OTEL_SERVICE_NAME=kb-worker",
    "OTEL_EXPORTER_OTLP_ENDPOINT=http://kb-alloy:4317",
    "OTEL_EXPORTER_OTLP_INSECURE=true",
    # ADR-0012: извлечение в docling, сканы распознаёт модель kb-vllm-generate
    "DOCLING_URL=http://kb-docling:5001",
    "DOCLING_VLM_URL=http://kb-vllm-generate:8000/v1/chat/completions",
    "DOCLING_VLM_MODEL=default",
    "DOCLING_CHUNK_TOKENIZER=${local.docling_tokenizer_path}",
    # ADR-0010/0006: эмбеддинги чанков и векторный индекс
    "EMBEDDING_URL=http://kb-vllm-embedding:8000/v1",
    "EMBEDDING_MODEL=default",
    "QDRANT_URL=http://kb-qdrant:6333",
  ]

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

resource "docker_container" "keycloak" {
  name    = "kb-keycloak"
  image   = "quay.io/keycloak/keycloak:26.7.4@sha256:3d911baa186f352563854039b95f21a7e2c01c76b527fdc64f24a0885b927bdf"
  restart = "unless-stopped"

  env = [
    "KC_BOOTSTRAP_ADMIN_USERNAME=${var.keycloak_admin_username}",
    "KC_BOOTSTRAP_ADMIN_PASSWORD=${var.keycloak_admin_password}",
  ]

  command = [
    "start",
    "--import-realm",
    "--http-enabled=true",
    "--hostname=https://${var.domain_name}:8002",
    "--proxy-headers=xforwarded",
    "--cache=local",
    "--health-enabled=true",
  ]

  # LibreChat читает OIDC discovery один раз при старте и без Keycloak не регистрирует вход: apply ждёт
  # готовности realm, прежде чем создавать зависимые контейнеры. curl в образе нет, поэтому bash /dev/tcp
  healthcheck {
    test         = ["CMD", "bash", "-c", "exec 3<>/dev/tcp/127.0.0.1/9000 && printf 'GET /health/ready HTTP/1.1\\r\\nHost: localhost\\r\\nConnection: close\\r\\n\\r\\n' >&3 && grep -q '\"status\": \"UP\"' <&3"]
    interval     = "10s"
    timeout      = "5s"
    retries      = 3
    start_period = "1m0s" # в форме, которую возвращает Docker: "60s" даёт вечный diff в plan
  }

  wait         = true
  wait_timeout = 180

  upload {
    file = "/opt/keycloak/data/import/realm-kb.json"
    content = templatefile("${path.module}/keycloak/realm-kb.json", {
      domain_name             = var.domain_name
      grafana_client_secret   = var.grafana_oauth_client_secret
      app_client_secret       = var.app_oauth_client_secret
      librechat_client_secret = var.librechat_oauth_client_secret
      seed_username           = var.keycloak_seed_username
      seed_password           = var.keycloak_seed_password
    })
  }

  volumes {
    container_path = "/opt/keycloak/data"
    volume_name    = docker_volume.keycloak_data.name
  }

  networks_advanced {
    name = docker_network.internal.name
  }
}

resource "docker_container" "vllm_generate" {
  name     = "kb-vllm-generate"
  image    = "vllm/vllm-openai:v0.29.0@sha256:c2914767605584b6d8f45686b82de173ecc99e781897aa3d0a66dacd72c51ae1"
  restart  = "unless-stopped"
  runtime  = "nvidia"
  ipc_mode = "private"
  shm_size = 16384

  timeouts {
    create = "3h"
  }

  env = [
    "NVIDIA_VISIBLE_DEVICES=${var.vllm_gpus}",
    "NVIDIA_DRIVER_CAPABILITIES=compute,utility",
    "VLLM_WORKER_MULTIPROC_METHOD=spawn",
    "VLLM_SKIP_P2P_CHECK=1",
    "NCCL_P2P_DISABLE=1",
    "VLLM_NO_USAGE_STATS=1",
    "OMP_NUM_THREADS=1",
    "NCCL_CUMEM_ENABLE=0",
    "PYTORCH_CUDA_ALLOC_CONF=expandable_segments:True",
    "OTEL_SERVICE_NAME=kb-vllm-generate",
    "OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=http://kb-alloy:4317",
    "OTEL_EXPORTER_OTLP_TRACES_INSECURE=true",
  ]

  command = [
    local.model_path,
    "--disable-custom-all-reduce",
    "--quantization", "auto_round",
    "--dtype", "float16",
    "--override-generation-config", "{\"temperature\":0.6,\"top_p\":0.95,\"top_k\":20,\"min_p\":0.0,\"repetition_penalty\":1.0}",
    "--host", "0.0.0.0",
    "--port", "8000",
    "--served-model-name", "default",
    "--tool-call-parser", "qwen3_coder",
    "--reasoning-parser", "qwen3",
    "--default-chat-template-kwargs", "{\"enable_thinking\": false}",
    "--chat-template", "/chat-template/chat_template.jinja",
    "--enable-auto-tool-choice",
    "--tensor-parallel-size", "2",
    "--pipeline-parallel-size", "1",
    "--max-model-len", "262144",
    "--gpu-memory-utilization", "0.92",
    "--max-num-seqs", "16",
    "--max-num-batched-tokens", "8192",
    "--kv-cache-dtype", "fp8_e4m3",
    "--trust-remote-code",
    "--enable-prefix-caching",
    "--enable-chunked-prefill",
    "--long-prefill-token-threshold", "0",
    "--otlp-traces-endpoint", "http://kb-alloy:4317",
  ]

  volumes {
    container_path = local.model_path
    volume_name    = docker_volume.models["generate"].name
  }

  upload {
    file    = "/chat-template/chat_template.jinja"
    content = file("${path.module}/vllm-generate/chat_template.jinja")
  }

  networks_advanced {
    name = docker_network.internal.name
  }
}

resource "docker_container" "vllm_embedding" {
  name     = "kb-vllm-embedding"
  image    = "vllm/vllm-openai:v0.29.0@sha256:c2914767605584b6d8f45686b82de173ecc99e781897aa3d0a66dacd72c51ae1"
  restart  = "unless-stopped"
  runtime  = "nvidia"
  ipc_mode = "private"
  shm_size = 16384

  timeouts {
    create = "3h"
  }

  env = [
    "NVIDIA_VISIBLE_DEVICES=${var.vllm_embedding_gpus}",
    "NVIDIA_DRIVER_CAPABILITIES=compute,utility",
    "OTEL_SERVICE_NAME=kb-vllm-embedding",
    "OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=http://kb-alloy:4317",
    "OTEL_EXPORTER_OTLP_TRACES_INSECURE=true",
  ]

  command = [
    local.model_path,
    "--host", "0.0.0.0",
    "--served-model-name", "default",
    "--tensor-parallel-size", "1",
    "--gpu-memory-utilization", "0.9",
    "--max-num-seqs", "16",
    "--runner", "pooling",
    "--convert", "embed",
    "--trust-remote-code",
    "--hf-overrides", jsonencode({
      model_type = "deepseek_v3"
      auto_map   = null
    }),
    "--pooler-config", jsonencode({
      pooling_type   = "MEAN"
      use_activation = true
    }),
    "--otlp-traces-endpoint", "http://kb-alloy:4317",
  ]

  volumes {
    container_path = local.model_path
    volume_name    = docker_volume.models["embedding"].name
  }

  networks_advanced {
    name = docker_network.internal.name
  }
}

resource "docker_container" "vllm_reranker" {
  name     = "kb-vllm-reranker"
  image    = "vllm/vllm-openai:v0.29.0@sha256:c2914767605584b6d8f45686b82de173ecc99e781897aa3d0a66dacd72c51ae1"
  restart  = "unless-stopped"
  runtime  = "nvidia"
  ipc_mode = "private"
  shm_size = 16384

  timeouts {
    create = "3h"
  }

  env = [
    "NVIDIA_VISIBLE_DEVICES=${var.vllm_reranker_gpus}",
    "NVIDIA_DRIVER_CAPABILITIES=compute,utility",
    "OTEL_SERVICE_NAME=kb-vllm-reranker",
    "OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=http://kb-alloy:4317",
    "OTEL_EXPORTER_OTLP_TRACES_INSECURE=true",
  ]

  command = [
    local.model_path,
    "--host", "0.0.0.0",
    "--served-model-name", "default",
    "--gpu-memory-utilization", "0.4",
    "--max-num-seqs", "16",
    "--runner", "pooling",
    "--otlp-traces-endpoint", "http://kb-alloy:4317",
  ]

  volumes {
    container_path = local.model_path
    volume_name    = docker_volume.models["reranker"].name
  }

  networks_advanced {
    name = docker_network.internal.name
  }
}

resource "docker_container" "docling" {
  name    = "kb-docling"
  image   = local.docling_image
  restart = "unless-stopped"

  env = [
    "UVICORN_HOST=0.0.0.0",
    "UVICORN_PORT=5001",
    "DOCLING_SERVE_ENABLE_REMOTE_SERVICES=true",
    "UVICORN_WORKERS=8",
    "DOCLING_SERVE_OPTIONS_CACHE_SIZE=1",
    "DOCLING_SERVE_ENG_KIND=rq",
    "DOCLING_SERVE_ENG_RQ_REDIS_URL=${local.docling_redis_url}",
    "DOCLING_SERVE_LOAD_MODELS_AT_BOOT=false",
    "HF_HUB_OFFLINE=1",
    "TRANSFORMERS_OFFLINE=1",
  ]

  networks_advanced {
    name = docker_network.internal.name
  }
}

resource "docker_container" "docling_worker" {
  name    = "kb-docling-worker"
  image   = local.docling_image
  restart = "unless-stopped"
  runtime = "nvidia"

  command = ["docling-serve", "rq-worker"]

  env = [
    "NVIDIA_VISIBLE_DEVICES=${var.docling_gpus}",
    "NVIDIA_DRIVER_CAPABILITIES=compute,utility",
    "DOCLING_SERVE_ENABLE_REMOTE_SERVICES=true",
    "DOCLING_SERVE_ENG_KIND=rq",
    "DOCLING_SERVE_ENG_RQ_REDIS_URL=${local.docling_redis_url}",
    "DOCLING_SERVE_OPTIONS_CACHE_SIZE=1",
    "HF_HUB_OFFLINE=1",
    "TRANSFORMERS_OFFLINE=1",
  ]

  # Чанкеру нужен токенизатор модели эмбеддингов (ADR-0010), иначе размер чанка в токенах не совпадёт с моделью.
  # В офлайн-режиме HuggingFace его не скачать, а каталог модели целиком не годится: config.json требует исполнения
  # кода модели (trust_remote_code), который docling не передаёт. Поэтому из тома подключаются только файлы токенизатора
  dynamic "mounts" {
    for_each = toset(["tokenizer.json", "tokenizer_config.json", "special_tokens_map.json"])

    content {
      type      = "volume"
      source    = docker_volume.models["embedding"].name
      target    = "${local.docling_tokenizer_path}/${mounts.value}"
      read_only = true

      volume_options {
        subpath = mounts.value
      }
    }
  }

  # subpath подключается только к существующему файлу: модель должна быть скачана до старта воркера
  depends_on = [docker_container.hf_cli["embedding"]]

  networks_advanced {
    name = docker_network.internal.name
  }
}

locals {
  model_path        = "/model"
  docling_redis_url = "redis://kb-redis:6379/1"
  # Путь передаётся в запросе чанкования как chunking_tokenizer
  docling_tokenizer_path = "/opt/tokenizers/embedding"
  docling_image          = "quay.io/docling-project/docling-serve-cu128:v1.34.0@sha256:0095f2171deb2f43f0914c198f37c51193dce3c280f95cd2e97023d7dea87176"
  hf_models = {
    "reranker" = {
      repo     = "BAAI/bge-reranker-v2-m3"
      revision = "953dc6f6f85a1b2dbfca4c34a2796e7dde08d41e"
    }
    "embedding" = {
      repo     = "ai-sage/Giga-Embeddings-instruct-10B-A1.8B-0826"
      revision = "3bca8f1e01478765d17df9237174a1419c38e496"
    }
    "generate" = {
      repo     = "Intel/Qwen3.6-35B-A3B-int4-mixed-AutoRound"
      revision = "65f69c73f17488236c85c85211f6ba28d7106157"
    }
  }
}

resource "docker_volume" "models" {
  for_each = local.hf_models

  name = "kb-models-${each.key}"
}

resource "docker_image" "hf_cli" {
  name = "kb-hf-cli:1.0.0"

  build {
    context     = "${path.module}/hf-cli"
    dockerfile  = "Dockerfile"
    pull_parent = true
  }

  triggers = {
    dockerfile = filesha256("${path.module}/hf-cli/Dockerfile")
  }
}

resource "docker_container" "hf_cli" {
  for_each = local.hf_models

  name     = "kb-hf-cli-${each.key}"
  image    = docker_image.hf_cli.name
  attach   = true
  logs     = true
  must_run = false

  command = [
    "hf",
    "download",
    each.value.repo,
    "--revision", each.value.revision,
    "--local-dir", local.model_path,
  ]

  volumes {
    container_path = local.model_path
    volume_name    = docker_volume.models[each.key].name
  }

  networks_advanced {
    name = docker_network.dmz.name
  }

  lifecycle {
    postcondition {
      condition     = self.exit_code == 0
      error_message = "Загрузка ${each.value.repo} завершилась с кодом ${self.exit_code}"
    }
  }
}

resource "docker_volume" "librechat_data" {
  name = "kb-librechat-data"
}

resource "docker_volume" "librechat_mongo_data" {
  name = "kb-librechat-mongo-data"
}

resource "docker_image" "librechat" {
  name = "kb-librechat:1.0.0"

  build {
    context    = "${path.module}/librechat"
    dockerfile = "Dockerfile"
  }

  triggers = {
    dockerfile = filesha256("${path.module}/librechat/Dockerfile")
  }
}

resource "docker_container" "librechat_mongo" {
  name    = "kb-librechat-mongo"
  image   = "mongo:8.0.20"
  restart = "unless-stopped"

  command = ["mongod", "--noauth"]

  volumes {
    container_path = "/data/db"
    volume_name    = docker_volume.librechat_mongo_data.name
  }

  networks_advanced {
    name = docker_network.internal.name
  }
}

resource "docker_container" "librechat" {
  name    = "kb-librechat"
  image   = docker_image.librechat.image_id
  restart = "unless-stopped"

  # Keycloak — через kb-nginx (issuer на domain_name:8002): оба должны быть готовы до первого discovery
  depends_on = [docker_container.librechat_mongo, docker_container.keycloak, docker_container.nginx]

  env = [
    "HOST=0.0.0.0",
    "PORT=3080",
    "MONGO_URI=mongodb://kb-librechat-mongo:27017/LibreChat",
    "JWT_SECRET=${var.librechat_jwt_secret}",
    "JWT_REFRESH_SECRET=${var.librechat_jwt_refresh_secret}",
    "DOMAIN_CLIENT=https://${var.domain_name}",
    "DOMAIN_SERVER=https://${var.domain_name}",
    "TRUST_PROXY=1",
    "SESSION_COOKIE_SECURE=true",
    # OIDC-вход через Keycloak realm kb: issuer прибит к domain:8002 (алиас kb-nginx в kb-internal),
    # поэтому и браузер, и бэкенд LibreChat ходят на один и тот же адрес /auth, /token, /userinfo.
    # Кнопка OpenID/Keycloak у LibreChat — "social login": показывается только при ALLOW_SOCIAL_LOGIN=true,
    # а создание аккаунта при первом входе — при ALLOW_SOCIAL_REGISTRATION=true. Доступ при этом закреплён
    # за Keycloak (realm kb + OPENID_REQUIRED_ROLE), локальная email-регистрация и email-вход выключены.
    "OPENID_ISSUER=https://${var.domain_name}:8002/realms/kb",
    # Вход в LibreChat — свой клиент librechat (PKCE S256); MCP-токены выдаёт kb-app (librechat.yaml), поэтому revoke
    # MCP-токена снимает только client session kb-app и не трогает вход в LibreChat
    "OPENID_CLIENT_ID=librechat",
    "OPENID_CLIENT_SECRET=${var.librechat_oauth_client_secret}",
    "KB_MCP_CLIENT_SECRET=${var.app_oauth_client_secret}",
    "OPENID_USE_PKCE=true",
    "OPENID_SESSION_SECRET=${var.librechat_session_secret}",
    # Ключ шифрования сохранённых учётных данных: без него токены OAuth MCP не сохраняются ("Invalid key length")
    "CREDS_KEY=${var.librechat_creds_key}",
    "CREDS_IV=${var.librechat_creds_iv}",
    "OPENID_CALLBACK_URL=/oauth/openid/callback",
    # Без offline_access: вход LibreChat живёт в онлайн SSO-сессии рядом с MCP-токеном kb-app, поэтому выход из
    # LibreChat завершает всю сессию (backchannel logout в kb-app), а revoke MCP не удаляет сессию входа
    "OPENID_SCOPE=openid profile email",
    "OPENID_REUSE_TOKENS=true",
    "OPENID_REQUIRED_ROLE=kb-admin",
    "OPENID_REQUIRED_ROLE_TOKEN_KIND=access",
    "OPENID_REQUIRED_ROLE_PARAMETER_PATH=realm_access.roles",
    "OPENID_USE_END_SESSION_ENDPOINT=true",
    "OPENID_POST_LOGOUT_REDIRECT_URI=https://${var.domain_name}/",
    "ALLOW_SOCIAL_LOGIN=true",
    "ALLOW_SOCIAL_REGISTRATION=true",
    "ALLOW_REGISTRATION=false",
    "ALLOW_EMAIL_LOGIN=false",
    # Облачные эндпоинты выключены: остаются custom (kb-vllm-generate из librechat.yaml) и agents,
    # через который LibreChat вызывает MCP-инструменты.
    "ENDPOINTS=custom,agents",
    "CONFIG_PATH=/app/librechat.yaml",
  ]

  upload {
    file = "/app/librechat.yaml"
    content = templatefile("${path.module}/librechat/librechat.yaml", {
      domain_name = var.domain_name
    })
  }

  volumes {
    container_path = "/app/uploads"
    volume_name    = docker_volume.librechat_data.name
  }

  networks_advanced {
    name = docker_network.internal.name
  }
}
