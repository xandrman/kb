resource "docker_network" "dmz" {
  name = "kb_dmz"
}

resource "docker_network" "internal" {
  name = "kb_internal"
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

resource "docker_container" "grafana" {
  name    = "kb_grafana"
  image   = "grafana/grafana:13.2.2@sha256:ac461fb352abc50da10a51c7d02462e9c05488f11f53f14b3ad79a8145f638a0"
  restart = "unless-stopped"

  networks_advanced {
    name = docker_network.internal.name
  }

  lifecycle {
    create_before_destroy = true
  }
}
