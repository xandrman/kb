resource "docker_network" "dmz" {
  name = "kb-dmz"
}

resource "docker_network" "internal" {
  name     = "kb-internal"
  internal = true
}

# Только Alloy: dockerd с хоста шлёт сюда логи контейнеров по syslog, из других сетей Docker адрес недоступен (ADR-0032)
resource "docker_network" "logs" {
  name     = "kb-logs"
  internal = true

  ipam_config {
    subnet  = var.log_network_subnet
    gateway = cidrhost(var.log_network_subnet, 1)
  }
}

locals {
  # dockerd не знает имён контейнеров, поэтому адрес Alloy фиксирован; UDP — чтобы запуск контейнеров не зависел от Alloy
  log_collector_address = cidrhost(var.log_network_subnet, 2)

  syslog_log_opts = {
    "syslog-address" = "udp://${local.log_collector_address}:1514"
    "syslog-format"  = "rfc5424micro"
    "tag"            = "{{.Name}}"
  }
}
