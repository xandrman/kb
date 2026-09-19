# ADR-0004: Принять Grafana Tempo как хранилище распределённого трейсинга

Статус: принят  
Deciders: AI Architect  
Дата: 2026-09-19  
Техническая история: ТЗ-ТМ-2026-014, п. 5 (FR-9), 7.9, 8.4

## Контекст

ADR-0002 закрепляет Grafana OSS как единый слой визуализации с data sources Prometheus / Loki / **Tempo**; ADR-0003 закрепляет Grafana Alloy как единый агент, принимающий OTLP-трейсы и маршрутизирующий их в Tempo. Остал нерешённым вопрос **выбора бэкенда трейсинга** — конкретного хранилища, в которое Alloy записывает трэйсы.

Требования: FR-9/7.9 — сквозной трэйс запроса (вопрос → ретривал → агент → генерация) + метрики; 8.4 — полный трэйс и логи LLM-сервера видны в инструменте трейсинга. Трейсинг — OpenTelemetry. Закрытый контур (6.3), сегментация DMZ/Internal (6.4), нет DevOps в штате (2.2), эталонное оборудование EPYC + 4×RTX 3090 (7.2).

## Драйверы

- FR-9/8.4: полный трэйс запроса с привязкой к логам LLM-сервера (корреляция trace↔log)
- OpenTelemetry-нативность: трэйсы собираются Alloy (ADR-0003) и должны попадать в бэкенд нативно, без конвертеров
- Минимум движущихся частей: нет DevOps, один хост, ≤10 пользователей
- Интеграция с Grafana OSS (ADR-0002): дашборд, поиск по трэйсам, drill-down к логам Prometheus/Loki
- Air-gapped: без SaaS, self-hosted

## Варианты

| | Суть |
|---|---|
| **A. Grafana Tempo** | OTel-native, BLOK-хранилище в S3/GCS (или локальный volume); нативный data source в Grafana OSS; correlation с Loki (log↔trace) из коробки; один процесс, минимум конфигов |
| B. Jaeger (All-in-One) | Zippy: нативный OTel; но отдельная UI (не Grafana), корреляция с логами — ручная; All-in-One — in-memory, перезагрузка = потеря трэйсов; distributed-режим = Kafka + Cassandra/Elasticsearch |
| C. Zipkin | Меньше фич, слабая корреляция с логами; Grafana-интеграция через сторонние плагин-адаптеры |
| D. SaaS (Honeycomb, Datadog) | Нарушает п. 6.3/152-ФЗ — **исключён** |

## Решение

**Вариант A.** Grafana Tempo — бэкенд-хранилище распределённого трейсинга. Alloy (ADR-0003) отправляет OTLP-трейсы в Tempo через `otelcol.exporter.otlp`; Tempo хранит их на **локальном S3-совместимом хранилище** (MinIO, контейнер `kb_minio`, сеть `kb_internal`) или, при MVP, — на локальном volume (`storage.trace` → filesystem).

**Архитектура.**

```
vLLM / MCP (PHP) / Nginx  ──OTLP──▶  Alloy (otelcol.receiver.otlp)
                                           │
                                           ▼  otelcol.exporter.otlp
                                       Tempo
                                           │
                                           ▼  S3-совместимое хранилище
                                       MinIO (локальный volume)
                                           │
Grafana OSS  ◀── data source ──────────────┘
   (трэйс + correlation с Loki/Prometheus)
```

- Tempo — контейнер `kb_tempo`, сеть `kb_internal`, host-порты наружу не публикует (сегментация 6.4).
- Grafana OSS (ADR-0002) подключается к Tempo как data source; на дашборде — поиск по трэйсам, span-level detail, correlation с Loki-логами.
- Корреляция trace↔log: Tempo использует `service.name` + `span ID` из логов (Loki label), извлечённых Alloy → в Grafana — единый view: трэйс + логи LLM-сервера (8.4).

**Параметры MVP:** retention 30 дней (нагрузка пилота мала: ≤10 пользователей, трэйсы — короткие цепочки); compression `gzip`; single-binary режим (без ring — не требуется при одном нодe).

**Лицензия.** Grafana Tempo — **AGPL-3.0**: допустимо, т.к. сервис автономен и copyleft не распространяется на код Платформы; модификации/редистрибуция не допускаются — используем дистрибутив как есть (аналогично ADR-0002, ADR-0003).

## Последствия

Плюс:
- Нативная интеграция с Grafana OSS (ADR-0002): трэйс, метрики, логи — в одном дашборде; correlation trace↔log без ручных скриптов
- OTel-native: Alloy принимает и передаёт трэйсы без конвертеров; единая экосистема Grafana Labs (Alloy → Tempo → Grafana)
- Блоб-хранилище (S3): горизонтальное масштабирование = добавление дисков, без миграций BaaS
- Минимум конфигов: single-binary, `storage.trace` → filesystem/S3; один контейнер вместо Jaeger-agent + collector + ES
- Self-hosted, air-gapped — соответствует п. 6.3

Минус:
- AGPL-3.0: нет редистрибуции модификаций (на код Платформы не влияет)
- BLOK-хранилище: латентность чтения зависит от S3/диска; при MVP (локальный volume) — не критично
- Single-binary — SPOF для трейсинга (HA — за пределами MVP); при отказе Tempo трэйсы теряются (метрики/логи — нет)
- Jaeger-экосистема шире (CLI, UI-плагины); при переходе на Tempo — часть Jaeger-specific tooling становится недоступной

## Ссылки

- ТЗ-ТM-2026-014: п. 5 (FR-9), 6.3, 6.4, 7.2, 7.9, 8.4
- ADR-0001 «Развернуть инфраструктуру через Terraform»
- ADR-0002 «Взять Grafana OSS как единый слой наблюдаемости»
- ADR-0003 «Принять Grafana Alloy как единый агент сбора телеметрии»
- Grafana Tempo: https://grafana.com/docs/tempo/latest
