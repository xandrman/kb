variable "model_path" {
  description = "Mount path of each model volume inside the hf-cli container"
  type        = string
  default     = "/model"
}