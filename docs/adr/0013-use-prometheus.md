# ADR-0013: Prometheus — хранилище и сборщик метрик

Статус: принят  
Deciders: AI Architect  
Дата: 2026-09-20  
Техническая история: ТЗ-ТМ-2026-014, п. 5 (FR-9), 6.3, 6.4, 7.9, 8.5

## Контекст

ADR-0002 назвал источниками Grafana Prometheus / Loki / Tempo, ADR-0004 и ADR-0005 закрыли трейсы и логи. Метрики — последний столп: бэкенд не выбран, а вместе с ним не решено, кто их снимает. FR-9 требует токены/сек, TTFT, полный ответ, RPS, GPU/VRAM; 8.5 — нагрузочный отчёт. Закрытый контур (6.3), сегментация DMZ/Internal (6.4), один хост, нет DevOps в штате (2.2).

## Драйверы

- Источники отдают Prometheus-формат (vLLM — ADR-0008/0009, Qdrant — ADR-0006): ни конвертеров, ни переписывания готовых дашбордов
- Скрейпер встроен в бэкенд — промежуточный агент на пути метрик не добавляет функции
- `up` по каждой цели — встроенный health-check, в том числе для самого Alloy

## Варианты

| | Суть |
|---|---|
| **A. Prometheus** | Эталон формата и PromQL; Apache 2.0; один бинарь, локальный TSDB; нативен в Grafana |
| B. VictoriaMetrics | Экономнее по RAM, но краевые расхождения PromQL и меньше рецептов — риск без DevOps |
| C. Mimir / InfluxDB | Избыточен при одном хосте / свой язык запросов — дашборды неприменимы |
| D. SaaS | Нарушает 6.3/152-ФЗ — **исключён** |

## Решение

**Вариант A.** Prometheus хранит метрики и **сам их снимает** (`scrape_configs` в `infra/prometheus/prometheus.yml`); Alloy на пути метрик не участвует и остаётся на логах и трейсах. Это отступление от ADR-0003, закрепившего Alloy единым агентом для всей телеметрии, — его раздел «Решение» подлежит правке. Контейнер `kb_prometheus`, сеть `kb_internal`, без публикации портов (6.4), retention 30 дней как у Tempo и Loki, том под `/prometheus`, версия и sha256 — в `infra/main.tf`.

Цели: vLLM, Qdrant, Alloy (иначе отказ сборщика не виден никому), **DCGM Exporter** — GPU/VRAM по FR-9, которых cAdvisor и node_exporter не дают вопреки ADR-0003, сами cAdvisor и node_exporter — CPU/RAM/диск, **nginx-prometheus-exporter** — открытый nginx отдаёт только `stub_status`. Всё — Apache 2.0, без ограничений AGPL-дистрибутивов ADR-0002–0005.

**Neo4j остаётся без метрик сознательно:** их экспорт — функция Enterprise, а ADR-0007 выбрал Community. Перечень FR-9 внутренних метрик СУБД не содержит; графовый хоп виден спаном в трэйсе (ADR-0004), а RAM JVM — через cAdvisor, чего и требовал ADR-0007 для Capacity Planning.

## Последствия

Плюс: третий столп закрыт, FR-9 и 7.9 выполнены; конфигурация скрейпа дефолтная, без flow-синтаксиса Alloy; алерт строится на `up == 0`, а не на отсутствии данных.

Минус: ни HA, ни долгого хранения — при масштабировании пересмотр (Mimir / VictoriaMetrics); перезапуск даёт пропуск в метриках, буфера remote write здесь нет — не проводить в окне замера 8.5; цели описаны в двух местах (`prometheus.yml` и `config.alloy`); четыре экспортера-сайдкара для пиннинга; у Neo4j внутренних метрик не будет.

## Ссылки

- ТЗ-ТМ-2026-014: п. 5 (FR-9), 6.3, 6.4, 7.9, 8.5
- ADR-0002 (Grafana), ADR-0003 (Alloy), ADR-0004 (Tempo), ADR-0006 (Qdrant), ADR-0007 (Neo4j Community), ADR-0008/0009 (vLLM)
- https://neo4j.com/docs/operations-manual/current/monitoring/metrics/expose/ — «Expose metrics — Enterprise Edition»
- https://prometheus.io/docs/prometheus/latest/storage/ · https://github.com/NVIDIA/dcgm-exporter · https://github.com/nginx/nginx-prometheus-exporter
