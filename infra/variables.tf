variable "domain_name" {
  description = "Domain for externally exposed services (no leading dot, e.g. 'localhost')"
  type        = string
  default     = "localhost"
}

variable "vllm_gpus" {
  description = "GPU devices for vLLM generate (comma-separated, e.g. '0,1')"
  type        = string
  default     = "0,1"
}

variable "vllm_embedding_gpus" {
  description = "GPU device for vLLM embedding (single index, e.g. '2')"
  type        = string
  default     = "2"
}

variable "vllm_reranker_gpus" {
  description = "GPU device for vLLM reranker (single index, e.g. '3')"
  type        = string
  default     = "3"
}

variable "keycloak_admin_username" {
  description = "Bootstrap admin username for Keycloak (applied only on the first start with an empty database)"
  type        = string
}

variable "keycloak_admin_password" {
  description = "Bootstrap admin password for Keycloak (applied only on the first start with an empty database)"
  type        = string
  sensitive   = true
}
