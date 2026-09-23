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
