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

variable "docling_gpus" {
  description = "GPU device for Docling layout and table models (single index, e.g. '3')"
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

variable "grafana_oauth_client_secret" {
  description = "Client secret of the 'grafana' OIDC client in the 'kb' Keycloak realm"
  type        = string
  sensitive   = true
}

variable "keycloak_seed_username" {
  description = "Seed user in the 'kb' realm, granted the grafana-admin role (lab bootstrap)"
  type        = string
}

variable "keycloak_seed_password" {
  description = "Password of the seed user in the 'kb' realm"
  type        = string
  sensitive   = true
}
