locals {
  # Сертификат домена — файлы на хосте, вне git (ADR-0033)
  tls_host_path      = abspath("${path.module}/tls")
  tls_container_path = "/etc/nginx/tls"
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

  depends_on = [docker_container.app]

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

  # Сертификат и ключ кладёт оператор (ADR-0033); master-процесс nginx (root) читает ключ при загрузке конфига.
  # Каталог, а не upload: иначе ключ попал бы в state Terraform
  volumes {
    container_path = local.tls_container_path
    host_path      = local.tls_host_path
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

  # Без сертификата nginx не загрузит конфиг с ssl_certificate: apply останавливается до создания контейнера
  lifecycle {
    precondition {
      condition     = fileexists("${local.tls_host_path}/fullchain.pem") && fileexists("${local.tls_host_path}/privkey.pem")
      error_message = "Нет сертификата: положи fullchain.pem и privkey.pem для ${var.domain_name} в infra/tls/ (ADR-0033)"
    }
  }
}
