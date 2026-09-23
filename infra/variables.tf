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

variable "postgres_db" {
  description = "Name of the database created on the first start of PostgreSQL"
  type        = string
}

variable "postgres_user" {
  description = "Name of the bootstrap PostgreSQL role (owner of the database)"
  type        = string
}

variable "postgres_password" {
  description = "Password for the bootstrap PostgreSQL role (applied only on the first start with an empty data directory)"
  type        = string
  sensitive   = true
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

variable "app_oauth_client_secret" {
  description = "Client secret of the 'kb-app' OIDC client in the 'kb' Keycloak realm"
  type        = string
  sensitive   = true
}

variable "keycloak_seed_username" {
  description = "Seed user in the 'kb' realm, granted the kb-admin role (lab bootstrap)"
  type        = string
}

variable "keycloak_seed_password" {
  description = "Password of the seed user in the 'kb' realm"
  type        = string
  sensitive   = true
}

variable "app_key" {
  description = "Laravel APP_KEY of kb-app in 'base64:...' form; generate with 'php artisan key:generate --show'"
  type        = string
  sensitive   = true
}

variable "librechat_jwt_secret" {
  description = "JWT signing secret for LibreChat session tokens (generate: openssl rand -hex 32)"
  type        = string
  sensitive   = true
}

variable "librechat_jwt_refresh_secret" {
  description = "JWT refresh token secret for LibreChat (generate: openssl rand -hex 32)"
  type        = string
  sensitive   = true
}

variable "librechat_session_secret" {
  description = "OpenID session storage secret for LibreChat (generate: openssl rand -hex 32)"
  type        = string
  sensitive   = true
}

variable "librechat_creds_key" {
  description = "AES-256 key LibreChat encrypts stored credentials with, MCP OAuth tokens included (generate: openssl rand -hex 32)"
  type        = string
  sensitive   = true

  validation {
    condition     = can(regex("^[0-9a-f]{64}$", var.librechat_creds_key))
    error_message = "librechat_creds_key must be 64 hex characters."
  }
}

variable "librechat_creds_iv" {
  description = "IV for LibreChat credential encryption (generate: openssl rand -hex 16)"
  type        = string
  sensitive   = true

  validation {
    condition     = can(regex("^[0-9a-f]{32}$", var.librechat_creds_iv))
    error_message = "librechat_creds_iv must be 32 hex characters."
  }
}
