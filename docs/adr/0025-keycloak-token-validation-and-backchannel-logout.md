# ADR-0025: Аутентификация в `kb-app` — локальная проверка токена Keycloak, MCP OAuth и backchannel logout (частичное замещение ADR-0021, ADR-0024)

Статус: принят  
Deciders: AI Architect  
Дата: 2026-09-23  
Техническая история: ТЗ-ТМ-2026-014, п. 2.2, 5 (FR-1, FR-7), 6.1, 6.3, 6.4, 7.8, 9.1, 9.2

## Контекст

П. 7.8 и FR-7 требуют, чтобы MCP-сервер получал токен Keycloak от системы диалогов и по нему применял RBAC. П. 2.2 и глоссарий называют Keycloak источником аутентификации. ADR-0024 закрепил принцип «источник прав — подпись токена, проверяемая `kb-app`», но не выбрал способ проверки. ADR-0020 объявил MCP-эндпоинт, но не выбрал guard.

Два принятых условия разошлись с реализацией на стенде:

- ADR-0021: «Аутентификация — тот же OIDC-guard Keycloak, что и у MCP-эндпоинта (ADR-0020)». Guard'ов два: у админ-панели — сессия после входа по authorization code, у MCP — bearer-токен без сессии.
- ADR-0024: токен в MCP передаётся заголовком `Authorization: Bearer {{LIBRECHAT_OPENID_ACCESS_TOKEN}}`, вход LibreChat — со scope `offline_access`. Проверка на стенде показала, что при таком входе сессия LibreChat держится только на offline-токене: выход из LibreChat не завершает её в Keycloak, а завершение SSO-сессии не достигает `kb-app`.

## Драйверы

- **FR-7: пользователь без полномочий не получает ответ по ограниченному документу.** Отключение пользователя администратором Keycloak должно останавливать доступ к MCP сразу, а не когда истечёт срок уже выданного токена.
- **Бюджет latency (6.1).** Проверка токена выполняется на каждом вызове MCP; сетевой запрос в Keycloak на каждый вызов добавляется к TTFT.
- **Keycloak — сервер авторизации, а не `kb-app` (2.2).** Выдача токенов остаётся у Keycloak Заказчика; `kb-app` только их принимает.
- **Хост MCP заменяем (9.1, ADR-0024).** Стенд — LibreChat, пилот — чат Заказчика. Путь токена должен опираться на спецификацию MCP, а не на шаблоны заголовков конкретного хоста.
- **Air-gapped (6.3).** Проверка не должна требовать обращений за пределы `kb-internal`.

## Варианты

| | Суть |
|---|---|
| **A. Локальная проверка JWT по JWKS + backchannel logout** | Подпись и claims проверяются в `kb-app`, ключи кешируются; отзыв сессии Keycloak сообщает сам |
| B. `userinfo` через Socialite | Онлайн-проверка на каждый вызов; `userinfo` принимает токен любого клиента realm — проверка audience теряется |
| C. Introspection (RFC 7662) | Онлайн-проверка с немедленным отзывом, включая смену ролей; сетевой запрос и секрет клиента на каждый вызов |
| D. Passport и `Mcp::oauthRoutes()` | `kb-app` сам становится сервером авторизации — против 2.2 |
| E. Готовый guard-пакет для Keycloak | Та же модель, что A, но без отзыва сессии; новая зависимость |

## Решение

**Вариант A.**

Условия реализации:

- **Access token принимается**, если он подписан ключом realm, `iss` — realm `kb`, `aud` содержит `kb-app`, `typ` — `Bearer`, есть `exp`, `sub` и `sid`, а `sid` не отозван. JWKS кешируется на час; незнакомый `kid` вызывает повторную загрузку не чаще раза в минуту; ключи шифрования (`use=enc`) отбрасываются.
- **Путь токена — MCP OAuth.** `kb-app` публикует метаданные защищённого ресурса (RFC 9728) с realm `kb` в `authorization_servers`; ответ 401 на `/mcp` указывает на них в `WWW-Authenticate`. Хост получает токен у Keycloak сам. Заголовок `Bearer {{LIBRECHAT_OPENID_ACCESS_TOKEN}}` из ADR-0024 не используется.
- **Клиенты realm `kb`:**
  - `kb-app` — confidential, PKCE S256: вход в админ-панель и MCP OAuth хоста. На нём backchannel logout URL. Секрет клиента хост получает через своё окружение, а не через файл конфигурации.
  - `librechat` — confidential, PKCE S256: вход в LibreChat, scope `openid profile email` без `offline_access`.

  Вход хоста и MCP-токен живут в разных client session одной SSO-сессии: revoke MCP-токена в хосте не завершает вход, а выход из хоста завершает сессию целиком.
- **Backchannel logout** (OpenID Connect Back-Channel Logout 1.0). `POST /mcp/backchannel-logout` принимает logout token с подписью realm, `aud=kb-app`, `iat`, событием `backchannel-logout`, `sid` и без `nonce`. Отозванный `sid` помечается в Redis (ADR-0019) на час — дольше срока жизни access token realm. `backchannel.logout.session.required` включён. Эндпоинт доступен только из `kb-internal` через `kb-nginx`; на внешнем порту админ-панели путь `/mcp` и всё под ним закрыты.
- **Админ-панель** — сессионный guard после входа по authorization code через Socialite, клиент `kb-app`.
- **Стенд (ADR-0024).** При `client_secret` LibreChat не читает метаданные Keycloak, поэтому `authorization_url`, `token_url` и `revocation_endpoint` задаются явно. LibreChat читает OIDC discovery один раз при старте, поэтому его контейнер создаётся после того, как Keycloak прошёл healthcheck готовности.

## Замещение

- **ADR-0021**, условие реализации «Аутентификация — тот же OIDC-guard Keycloak, что и у MCP-эндпоинта (ADR-0020)» замещается условием об админ-панели выше. Остальное в ADR-0021, включая `canAccessPanel()` по роли «администратор», сохраняет силу.
- **ADR-0024**, первое условие реализации в части заголовка `Authorization: Bearer {{LIBRECHAT_OPENID_ACCESS_TOKEN}}` и входа со scope `offline_access` замещается путём токена и клиентами выше. Сохраняют силу Streamable HTTP на `/mcp`, вход через realm `kb` с `OPENID_REUSE_TOKENS`, выключенная локальная регистрация и принцип «источник прав — подпись токена, проверяемая `kb-app`».

## Последствия

Плюс: вызов MCP не ходит в Keycloak — проверка не добавляется к бюджету 6.1 и переживает кратковременную недоступность Keycloak; завершение SSO-сессии — выход из хоста, «Sign out» администратора — отключает MCP-токены этой сессии сразу; путь токена задан спецификацией MCP, а не шаблоном LibreChat, и переносится на чат Заказчика без изменений `kb-app`.

Минус: revoke по RFC 7009, который делает хост, до `kb-app` не доходит — отозванный access token принимается до `exp`, продлить его нельзя; отнятая роль действует до `exp` уже выданного токена; срок жизни access token realm не зафиксирован и равен значению Keycloak по умолчанию (5 минут); backchannel доходит, только пока SSO-сессия с client session `kb-app` существует; при потере Redis метки отзыва теряются, окно снова равно `exp`; хост держит секрет клиента `kb-app` — он может получать токены от имени `kb-app`; без `offline_access` вход в LibreChat живёт не дольше SSO-сессии Keycloak.

Не измерено: доставка backchannel при недоступном `kb-app` — повторяет ли Keycloak запрос, не проверялось; поведение при ≥ 10 одновременных пользователях (6.1).

Точки ревизии: требование немедленно применять смену ролей или revoke хоста — переход на introspection (вариант C); чат Заказчика не поддерживает MCP OAuth с `client_secret` или хранение секрета `kb-app` на стороне хоста неприемлемо — отдельный клиент под хост с тем же backchannel URL и расширенной проверкой `aud` logout token.

## Ссылки

- ТЗ-ТМ-2026-014, п. 2.2, 5 (FR-1, FR-7), 6.1, 6.3, 6.4, 7.8, 9.1, 9.2
- ADR-0019 (Redis — хранение меток отзыва), ADR-0020 (MCP-эндпоинт `kb-app`), ADR-0021 (админ-панель — частично замещается), ADR-0024 (LibreChat — частично замещается)
- https://modelcontextprotocol.io/specification/2026-07-28/basic/authorization — MCP authorization
- https://www.rfc-editor.org/rfc/rfc9728 — OAuth 2.0 Protected Resource Metadata
- https://openid.net/specs/openid-connect-backchannel-1_0.html — OpenID Connect Back-Channel Logout 1.0
- https://www.rfc-editor.org/rfc/rfc7009 · https://www.rfc-editor.org/rfc/rfc7662 — token revocation, token introspection
