resource "docker_volume" "grafana_data" {
  name = "kb-grafana-data"
}

resource "docker_container" "grafana" {
  name    = "kb-grafana"
  image   = "grafana/grafana:13.2.2@sha256:ac461fb352abc50da10a51c7d02462e9c05488f11f53f14b3ad79a8145f638a0"
  restart = "unless-stopped"

  # Логи — драйвером syslog в Alloy (ADR-0032)
  log_driver = "syslog"
  log_opts   = local.syslog_log_opts

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

  upload {
    file    = "/etc/grafana/provisioning/dashboards/dashboards.yml"
    content = file("${path.module}/grafana/dashboards.yml")
  }

  dynamic "upload" {
    for_each = fileset("${path.module}/grafana/dashboards", "*.json")

    content {
      file    = "/etc/grafana/dashboards/${upload.value}"
      content = file("${path.module}/grafana/dashboards/${upload.value}")
    }
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

  # Логи — драйвером syslog в Alloy (ADR-0032)
  log_driver = "syslog"
  log_opts   = local.syslog_log_opts

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
    file = "/etc/alloy/config.alloy"
    content = templatefile("${path.module}/alloy/config.alloy", {
      log_collector_address = local.log_collector_address
      log_gateway_regex     = replace(cidrhost(var.log_network_subnet, 1), ".", "\\\\.")
    })
  }

  volumes {
    container_path = "/var/log/nginx"
    volume_name    = docker_volume.nginx_logs.name
    read_only      = true
  }

  networks_advanced {
    name = docker_network.internal.name
  }

  # Приём syslog от dockerd по фиксированному адресу (ADR-0032)
  networks_advanced {
    name         = docker_network.logs.name
    ipv4_address = local.log_collector_address
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

  # Логи — драйвером syslog в Alloy (ADR-0032)
  log_driver = "syslog"
  log_opts   = local.syslog_log_opts

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

resource "docker_volume" "prometheus_data" {
  name = "kb-prometheus-data"
}

resource "docker_container" "prometheus" {
  name    = "kb-prometheus"
  image   = "prom/prometheus:v3.14.0@sha256:e906cef998316bbe319f98711e1b4d8613ad37e14b08ff831d7036e77b7464f9"
  restart = "unless-stopped"

  # Логи — драйвером syslog в Alloy (ADR-0032)
  log_driver = "syslog"
  log_opts   = local.syslog_log_opts

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

  # Ключ Qdrant только на чтение: с ключом /metrics без авторизации отвечает 401
  upload {
    file    = "/etc/prometheus/qdrant-api-key"
    content = var.qdrant_read_only_api_key
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

  # Логи — драйвером syslog в Alloy (ADR-0032)
  log_driver = "syslog"
  log_opts   = local.syslog_log_opts

  env = [
    "NVIDIA_VISIBLE_DEVICES=all",
    "NVIDIA_DRIVER_CAPABILITIES=compute,utility",
  ]

  networks_advanced {
    name = docker_network.internal.name
  }
}
