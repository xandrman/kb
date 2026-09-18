resource "docker_network" "dmz" {
  name = "kb_dmz"
}

resource "docker_container" "nginx" {
  name    = "kb_nginx"
  image   = "nginx:stable-alpine-otel@sha256:21f5b7af9dad45efdd63e231bb211f8c90abc54cbdd7ae783ab9855be5374428"
  restart = "unless-stopped"

  ports {
    internal = 80
    external = 80
  }

  networks_advanced {
    name = docker_network.dmz.name
  }

  lifecycle {
    create_before_destroy = true
  }
}
