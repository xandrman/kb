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

resource "docker_container" "nginx" {
  name    = "kb-nginx"
  image   = "nginx:stable-alpine-otel@sha256:21f5b7af9dad45efdd63e231bb211f8c90abc54cbdd7ae783ab9855be5374428"
  restart = "unless-stopped"

  command = ["/bin/sh", "-c", "rm -f /var/log/nginx/access.log /var/log/nginx/error.log && exec /docker-entrypoint.sh nginx -g 'daemon off;'"]

  ports {
    internal = 80
    external = 80
  }

  ports {
    internal = 3000
    external = 3000
  }

  ports {
    internal = 8080
    external = 8080
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
    file    = "/etc/nginx/conf.d/grafana.conf"
    content = templatefile("${path.module}/nginx/grafana.conf", { domain_name = var.domain_name })
  }

  upload {
    file    = "/etc/nginx/conf.d/keycloak.conf"
    content = templatefile("${path.module}/nginx/keycloak.conf", { domain_name = var.domain_name })
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
  name    = "kb-grafana"
  image   = "grafana/grafana:13.2.2@sha256:ac461fb352abc50da10a51c7d02462e9c05488f11f53f14b3ad79a8145f638a0"
  restart = "unless-stopped"

  env = [
    "GF_SERVER_ROOT_URL=http://${var.domain_name}:3000",
    "GF_SERVER_DOMAIN=${var.domain_name}",
  ]

  upload {
    file    = "/etc/grafana/provisioning/datasources/datasources.yml"
    content = file("${path.module}/grafana/datasources.yml")
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
    "--http-enabled=true",
    "--hostname=http://${var.domain_name}:8080",
    "--proxy-headers=xforwarded",
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

resource "docker_container" "vllm_generate" {
  name     = "kb-vllm-generate"
  image    = "vllm/vllm-openai:v0.29.0@sha256:c2914767605584b6d8f45686b82de173ecc99e781897aa3d0a66dacd72c51ae1"
  restart  = "unless-stopped"
  runtime  = "nvidia"
  ipc_mode = "private"
  shm_size = 16384

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
    "--max-num-seqs", "4",
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
    "--max-num-seqs", "4",
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
