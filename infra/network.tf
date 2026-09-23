resource "docker_network" "dmz" {
  name = "kb-dmz"
}

resource "docker_network" "internal" {
  name     = "kb-internal"
  internal = true
}
