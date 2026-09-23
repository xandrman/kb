resource "docker_volume" "librechat_data" {
  name = "kb-librechat-data"
}

resource "docker_volume" "librechat_mongo_data" {
  name = "kb-librechat-mongo-data"
}

resource "docker_image" "librechat" {
  name = "kb-librechat:1.0.0"

  build {
    context    = "${path.module}/librechat"
    dockerfile = "Dockerfile"
  }

  triggers = {
    dockerfile = filesha256("${path.module}/librechat/Dockerfile")
  }
}

resource "docker_container" "librechat_mongo" {
  name    = "kb-librechat-mongo"
  image   = "mongo:8.0.20"
  restart = "unless-stopped"

  command = ["mongod", "--noauth"]

  volumes {
    container_path = "/data/db"
    volume_name    = docker_volume.librechat_mongo_data.name
  }

  networks_advanced {
    name = docker_network.internal.name
  }
}

resource "docker_container" "librechat" {
  name    = "kb-librechat"
  image   = docker_image.librechat.image_id
  restart = "unless-stopped"

  # Keycloak — через kb-nginx (issuer на domain_name:8002): оба должны быть готовы до первого discovery
  depends_on = [docker_container.librechat_mongo, docker_container.keycloak, docker_container.nginx]

  env = [
    "HOST=0.0.0.0",
    "PORT=3080",
    "MONGO_URI=mongodb://kb-librechat-mongo:27017/LibreChat",
    "JWT_SECRET=${var.librechat_jwt_secret}",
    "JWT_REFRESH_SECRET=${var.librechat_jwt_refresh_secret}",
    "DOMAIN_CLIENT=https://${var.domain_name}",
    "DOMAIN_SERVER=https://${var.domain_name}",
    "TRUST_PROXY=1",
    "SESSION_COOKIE_SECURE=true",
    # OIDC-вход через Keycloak realm kb: issuer прибит к domain:8002 (алиас kb-nginx в kb-internal),
    # поэтому и браузер, и бэкенд LibreChat ходят на один и тот же адрес /auth, /token, /userinfo.
    # Кнопка OpenID/Keycloak у LibreChat — "social login": показывается только при ALLOW_SOCIAL_LOGIN=true,
    # а создание аккаунта при первом входе — при ALLOW_SOCIAL_REGISTRATION=true. Доступ при этом закреплён
    # за Keycloak (realm kb + OPENID_REQUIRED_ROLE), локальная email-регистрация и email-вход выключены.
    "OPENID_ISSUER=https://${var.domain_name}:8002/realms/kb",
    # Вход в LibreChat — свой клиент librechat (PKCE S256); MCP-токены выдаёт kb-app (librechat.yaml), поэтому revoke
    # MCP-токена снимает только client session kb-app и не трогает вход в LibreChat
    "OPENID_CLIENT_ID=librechat",
    "OPENID_CLIENT_SECRET=${var.librechat_oauth_client_secret}",
    "KB_MCP_CLIENT_SECRET=${var.app_oauth_client_secret}",
    "OPENID_USE_PKCE=true",
    "OPENID_SESSION_SECRET=${var.librechat_session_secret}",
    # Ключ шифрования сохранённых учётных данных: без него токены OAuth MCP не сохраняются ("Invalid key length")
    "CREDS_KEY=${var.librechat_creds_key}",
    "CREDS_IV=${var.librechat_creds_iv}",
    "OPENID_CALLBACK_URL=/oauth/openid/callback",
    # Без offline_access: вход LibreChat живёт в онлайн SSO-сессии рядом с MCP-токеном kb-app, поэтому выход из
    # LibreChat завершает всю сессию (backchannel logout в kb-app), а revoke MCP не удаляет сессию входа
    "OPENID_SCOPE=openid profile email",
    "OPENID_REUSE_TOKENS=true",
    "OPENID_REQUIRED_ROLE=kb-admin",
    "OPENID_REQUIRED_ROLE_TOKEN_KIND=access",
    "OPENID_REQUIRED_ROLE_PARAMETER_PATH=realm_access.roles",
    "OPENID_USE_END_SESSION_ENDPOINT=true",
    "OPENID_POST_LOGOUT_REDIRECT_URI=https://${var.domain_name}/",
    "ALLOW_SOCIAL_LOGIN=true",
    "ALLOW_SOCIAL_REGISTRATION=true",
    "ALLOW_REGISTRATION=false",
    "ALLOW_EMAIL_LOGIN=false",
    # Облачные эндпоинты выключены: остаются custom (kb-vllm-generate из librechat.yaml) и agents,
    # через который LibreChat вызывает MCP-инструменты.
    "ENDPOINTS=custom,agents",
    "CONFIG_PATH=/app/librechat.yaml",
  ]

  upload {
    file = "/app/librechat.yaml"
    content = templatefile("${path.module}/librechat/librechat.yaml", {
      domain_name = var.domain_name
    })
  }

  volumes {
    container_path = "/app/uploads"
    volume_name    = docker_volume.librechat_data.name
  }

  networks_advanced {
    name = docker_network.internal.name
  }
}
