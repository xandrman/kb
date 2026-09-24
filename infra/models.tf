locals {
  model_path = "/model"
  embedding_model = {
    repo     = "ai-sage/Giga-Embeddings-instruct-10B-A1.8B-0826"
    revision = "3bca8f1e01478765d17df9237174a1419c38e496"
  }
  # files пуст — скачивается весь репозиторий
  hf_models = {
    "reranker" = {
      repo     = "BAAI/bge-reranker-v2-m3"
      revision = "953dc6f6f85a1b2dbfca4c34a2796e7dde08d41e"
      files    = []
    }
    "embedding" = merge(local.embedding_model, { files = [] })
    # Токенизатор эмбеддинг-модели для чанкования в docling (см. docling_worker)
    "embedding-tokenizer" = merge(local.embedding_model, {
      files = ["tokenizer.json", "tokenizer_config.json", "special_tokens_map.json"]
    })
    "generate" = {
      repo     = "Intel/Qwen3.6-35B-A3B-int4-mixed-AutoRound"
      revision = "65f69c73f17488236c85c85211f6ba28d7106157"
      files    = []
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

  name  = "kb-hf-cli-${each.key}"
  image = docker_image.hf_cli.name

  # Загрузка идёт после apply: 20+ минут не укладываются в таймаут создания. vLLM на недокачанной модели падает
  # и перезапускается, пока загрузка не закончится — hf download кладёт файл на место только целиком.
  # Ошибку загрузки apply не покажет, смотри docker logs kb-hf-cli-<модель>
  must_run = false
  restart  = "on-failure"

  command = concat(["hf", "download", each.value.repo], each.value.files, [
    "--revision", each.value.revision,
    "--local-dir", local.model_path,
  ])

  volumes {
    container_path = local.model_path
    volume_name    = docker_volume.models[each.key].name
  }

  networks_advanced {
    name = docker_network.dmz.name
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
    "HF_HUB_OFFLINE=1",
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

  env = [
    "NVIDIA_VISIBLE_DEVICES=${var.vllm_embedding_gpus}",
    "NVIDIA_DRIVER_CAPABILITIES=compute,utility",
    "HF_HUB_OFFLINE=1",
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
    # Без CUDA-графов: прогрев размечает рабочий буфер MoE на ~32 токена (1 MB), и батч крупнее роняет движок —
    # в 0.29 буфер растёт под захваченными графами (illegal memory access), в 0.30 заблокирован ("Workspace is locked")
    "--enforce-eager",
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
    "HF_HUB_OFFLINE=1",
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
