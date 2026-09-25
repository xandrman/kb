# public/ берётся из конкретного образа приложения: разойтись с ним копия не может.
# Тег передаётся из terraform, образ уже лежит на демоне — наружу сборка не ходит (ТЗ 6.3)
ARG APP_IMAGE
FROM ${APP_IMAGE} AS app

FROM nginx:stable-alpine-otel@sha256:21f5b7af9dad45efdd63e231bb211f8c90abc54cbdd7ae783ab9855be5374428

COPY --from=app /var/www/html/public /var/www/html/public
