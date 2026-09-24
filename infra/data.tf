resource "docker_volume" "qdrant_data" {
  name = "kb-qdrant-data"
}

resource "docker_container" "qdrant" {
  name    = "kb-qdrant"
  image   = "qdrant/qdrant:v1.19.1@sha256:0699e7733a6fa7fa7f6b95dcbed84ebb04584110da525cdfdef9f305c4f57738"
  restart = "unless-stopped"

  # Логи — драйвером syslog в Alloy (ADR-0032)
  log_driver = "syslog"
  log_opts   = local.syslog_log_opts

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

  # Логи — драйвером syslog в Alloy (ADR-0032)
  log_driver = "syslog"
  log_opts   = local.syslog_log_opts

  # Пароль задаётся только при первом старте с пустым томом: auth хранится в /data, смена пароля — ALTER USER или пересоздание тома.
  # Без NEO4J_AUTH остаётся neo4j/neo4j с обязательной сменой, и сервер отклоняет любые запросы (CredentialsExpired)
  env = [
    "NEO4J_AUTH=neo4j/${var.neo4j_password}",
  ]

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

  # Логи — драйвером syslog в Alloy (ADR-0032)
  log_driver = "syslog"
  log_opts   = local.syslog_log_opts

  # В лог не попадают текст запроса и DETAIL — там PostgreSQL печатает значения из строк (ADR-0032)
  command = ["postgres", "-c", "log_error_verbosity=terse", "-c", "log_min_error_statement=panic"]

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

  # Логи — драйвером syslog в Alloy (ADR-0032)
  log_driver = "syslog"
  log_opts   = local.syslog_log_opts

  volumes {
    container_path = "/data"
    volume_name    = docker_volume.redis_data.name
  }

  networks_advanced {
    name = docker_network.internal.name
  }
}
