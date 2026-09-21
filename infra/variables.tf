variable "domain_name" {
  description = "Домен для внешних сервисов (без ведущей точки, например \"localhost\")"
  type        = string
  default     = "localhost"
}

variable "vllm_gpus" {
  description = "GPU-устройства для vLLM generate (строка вида \"device=0,1\" с кавычками)"
  type        = string
  default     = "\"device=0,1\""
}