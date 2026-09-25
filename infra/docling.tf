locals {
  docling_redis_url = "redis://kb-redis:6379/1"
  # Путь передаётся в запросе чанкования как chunking_tokenizer
  docling_tokenizer_path = "/opt/tokenizers/embedding"
  docling_image          = "quay.io/docling-project/docling-serve-cu128:v1.34.0@sha256:0095f2171deb2f43f0914c198f37c51193dce3c280f95cd2e97023d7dea87176"
}

resource "docker_container" "docling" {
  name    = "kb-docling"
  image   = local.docling_image
  restart = "unless-stopped"

  # Логи — драйвером syslog в Alloy (ADR-0032)
  log_driver = "syslog"
  log_opts   = local.syslog_log_opts

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

  # Логи — драйвером syslog в Alloy (ADR-0032)
  log_driver = "syslog"
  log_opts   = local.syslog_log_opts

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
  # кода модели (trust_remote_code), который docling не передаёт. Поэтому файлы токенизатора скачиваются в отдельный том.
  # Пока они не скачаны, каталог пуст: задачи чанкования падают и повторяются очередью
  volumes {
    container_path = local.docling_tokenizer_path
    volume_name    = docker_volume.models["embedding-tokenizer"].name
    read_only      = true
  }

  networks_advanced {
    name = docker_network.internal.name
  }
}
