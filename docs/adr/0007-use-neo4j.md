# ADR-0007: Graph DB — Neo4j

Статус: принят
Deciders: AI Architect
Дата: 2026-09-19
Техническая история: ТЗ-ТМ-2026-014, п. 7.5

## Контекст

Граф знаний (FR-4): сущности и связи из 125–500 документов, узлы привязаны к чанкам (FR-5). Критично: multi-hop поиск (п. 8.2) и RBAC на узлах/рёбрах, **включая опосредованный доступ через граф** (п. 8.3, FR-7). Интеграция из PHP 8.x, self-hosted, лицензия под закрытый коммерческий продукт.

## Драйверы

- Cypher выражает multi-hop путь одним запросом, без ручных join
- **Path-level RBAC**: фильтр по `access_level` должен работать на каждом хопе обхода, а не post-filter результата — иначе опосредованная утечка (п. 8.3). Аналог драйвера ADR-0006 (фильтр внутри ANN, не после)
- Один контейнер, air-gapped (6.3)
- Крупнейшая экосистема GraphRAG — ниже риск для PHP-команды без ML-экспертизы (п. 2.2)

## Варианты

| | Суть |
|---|---|
| **Neo4j** | Cypher; крупнейшая экосистема GraphRAG; GPLv3 (Community) |
| Memgraph | in-memory, openCypher; лицензия BSL — ограничения при масштабировании |
| ArangoDB | свой язык AQL, не Cypher; лицензия шла к BSL |
| JanusGraph | Apache 2.0, но требует Cassandra/HBase + ES — избыточен для пилота |
| PostgreSQL + Apache AGE | минимальный footprint, но extension незрелый |

## Решение

**Neo4j Community Edition.** RBAC — не Enterprise FGAC (платный), а фильтр в самом Cypher-запросе (решение о правах и так принимает MCP-сервер, 7.8): узел/связь несёт `access_level`, `department`.

- **Path-level фильтр (п. 8.3) — Quantified Path Patterns**, нативный Cypher 5.9+, без плагинов: `MATCH (a) ((n)-[r]-(m) WHERE m.access_level <= $lvl){1,4}(b)` — предикат на каждой итерации. Обычный `[*1..N]` до QPP этого не умел (ни предикатов, ни доступа к промежуточным узлам).
- **APOC Core** (`apoc.path.expandConfig`) — fallback для обходов, не выражаемых QPP; подтверждён Apache 2.0, бесплатен для Community, но не входит в образ по умолчанию — ставится через `NEO4J_PLUGINS`/`/plugins`, версия синхронна с Neo4j.
- **`access_level` узла** = максимальная рестрикция среди связанных чанков (наследуется при построении графа, FR-4 → метаданные п. 4.2) — зафиксировать в ER-модели.
- Bolt по TLS, отдельный сервисный аккаунт (не встроенный `neo4j`), пароль из Vault; Neo4j Browser — только `kb_internal`, в проде отключён.
- Контейнер `kb_neo4j`, сеть `kb_internal`, без публикации портов (паттерн ADR-0001/0004–0006); клиент — `laudis/neo4j-php-client`; персистентность — volume `/data`; версия/sha256 — в `infra/main.tf`.
- GPLv3 (Community) допустим: используется как сетевой сервис без модификации/встраивания кода — копилефт не распространяется на PHP (аналог MySQL). Обосновать в пакете лицензий (10, артефакт 2).

## Последствия

Плюс: multi-hop одним запросом (п. 8.2); path-level RBAC без Enterprise (п. 8.3); один контейнер, не расширяет стек; зрелая GraphRAG-экосистема; Browser упрощает отладку графа при разработке.

Минус: enforcement только на уровне запросов MCP-сервера, не СУБД — нужны тесты именно на сценарий п. 8.3, не только review; GPLv3 требует обоснования в пакете лицензий; Community — без HA (`dump` ≠ online HA), для MVP ок, для масштабирования нужен пересмотр; JVM тяжелее Qdrant по RAM — учесть в Capacity Planning; **оркестрация Qdrant + Neo4j для FR-5 не описана — нужен отдельный ADR-0008**.

## Ссылки

- ТЗ-ТМ-2026-014, п. 5 (FR-4/5/7), 7.5, 7.8, 8.2–8.3
- ADR-0001, ADR-0006, ADR-0008 «GraphRAG-пайплайн» (не написан)
- https://neo4j.com/docs/cypher-manual/current/patterns/ — Quantified Path Patterns
- https://neo4j.com/docs/apoc/current/overview/apoc.path/apoc.path.expandConfig/ — APOC Core
- https://github.com/neo4j/apoc — Apache 2.0
