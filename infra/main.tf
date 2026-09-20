resource "docker_network" "dmz" {
  name = "kb_dmz"
}

resource "docker_network" "internal" {
  name     = "kb_internal"
  internal = true
}

resource "docker_volume" "nginx_logs" {
  name = "kb_nginx_logs"
}

resource "docker_container" "nginx" {
  name    = "kb_nginx"
  image   = "nginx:stable-alpine-otel@sha256:21f5b7af9dad45efdd63e231bb211f8c90abc54cbdd7ae783ab9855be5374428"
  restart = "unless-stopped"

  command = ["/bin/sh", "-c", "rm -f /var/log/nginx/access.log /var/log/nginx/error.log && exec /docker-entrypoint.sh nginx -g 'daemon off;'"]

  ports {
    internal = 80
    external = 80
  }

  upload {
    file    = "/etc/nginx/nginx.conf"
    content = file("${path.module}/nginx/nginx.conf")
  }

  upload {
    file    = "/etc/nginx/conf.d/default.conf"
    content = file("${path.module}/nginx/default.conf")
  }

  upload {
    file    = "/etc/nginx/conf.d/grafana.conf"
    content = file("${path.module}/nginx/grafana.conf")
  }

  upload {
    file    = "/etc/nginx/conf.d/keycloak.conf"
    content = file("${path.module}/nginx/keycloak.conf")
  }

  volumes {
    container_path = "/var/log/nginx"
    volume_name    = docker_volume.nginx_logs.name
  }

  networks_advanced {
    name = docker_network.dmz.name
  }

  networks_advanced {
    name = docker_network.internal.name
  }
}

resource "docker_container" "grafana" {
  name    = "kb_grafana"
  image   = "grafana/grafana:13.2.2@sha256:ac461fb352abc50da10a51c7d02462e9c05488f11f53f14b3ad79a8145f638a0"
  restart = "unless-stopped"

  upload {
    file    = "/etc/grafana/provisioning/datasources/datasources.yml"
    content = file("${path.module}/grafana/datasources.yml")
  }

  networks_advanced {
    name = docker_network.internal.name
  }
}

resource "docker_volume" "tempo_data" {
  name = "kb_tempo_data"
}

resource "docker_container" "tempo" {
  name    = "kb_tempo"
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
  name    = "kb_alloy"
  image   = "grafana/alloy:v1.19.2@sha256:b8ec653c44235fbe910879145dac3597d66b0aaecf60bcbbe82580767771a839"
  restart = "unless-stopped"

  command = ["run", "/etc/alloy/config.alloy"]

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
  name = "kb_loki_data"
}

resource "docker_container" "loki" {
  name    = "kb_loki"
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
  name = "kb_keycloak_data"
}

resource "docker_volume" "qdrant_data" {
  name = "kb_qdrant_data"
}

resource "docker_container" "qdrant" {
  name    = "kb_qdrant"
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
  name = "kb_neo4j_data"
}

resource "docker_container" "neo4j" {
  name    = "kb_neo4j"
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

resource "docker_container" "keycloak" {
  name    = "kb_keycloak"
  image   = "quay.io/keycloak/keycloak:26.7.4@sha256:3d911baa186f352563854039b95f21a7e2c01c76b527fdc64f24a0885b927bdf"
  restart = "unless-stopped"

  command = [
    "start",
    "--http-enabled=true",
    "--hostname=kb-keycloak.localhost",
    "--cache=local",
    "--health-enabled=true",
  ]

  volumes {
    container_path = "/opt/keycloak/data"
    volume_name    = docker_volume.keycloak_data.name
  }

  networks_advanced {
    name = docker_network.internal.name
  }
}

locals {
  model_path = "/model"
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

  name = "kb_models_${each.key}"
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

  name     = "kb_hf_cli_${each.key}"
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
