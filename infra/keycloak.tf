resource "docker_volume" "keycloak_data" {
  name = "kb-keycloak-data"
}

resource "docker_container" "keycloak" {
  name    = "kb-keycloak"
  image   = "quay.io/keycloak/keycloak:26.7.4@sha256:3d911baa186f352563854039b95f21a7e2c01c76b527fdc64f24a0885b927bdf"
  restart = "unless-stopped"

  # Логи — драйвером syslog в Alloy (ADR-0032)
  log_driver = "syslog"
  log_opts   = local.syslog_log_opts

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
