workspace "Платформа корпоративных знаний" "C4-модель Платформы корпоративных знаний на базе GraphRAG (ТЗ-ТМ-2026-014)" {

    !identifiers hierarchical

    model {
        employee = person "Сотрудник" "Сервис, поддержка, продажи"
        kbAdmin = person "Администратор базы знаний" "Ведёт корпус документов"

        chat = softwareSystem "Система диалогов с LLM" "Чат сотрудников с LLM" "Система Заказчика"
        keycloak = softwareSystem "Keycloak" "Аутентификация и роли" "Система Заказчика"

        platform = softwareSystem "Платформа корпоративных знаний" "GraphRAG с RBAC в закрытом контуре" {
            nginx = container "Reverse proxy" "TLS, публикация" "nginx"

            group "Control Plane" {
                app = container "MCP-сервер и админ-панель" "GraphRAG, RBAC, guardrails" "Laravel, php-fpm" {
                    group "Вход" {
                        mcpEndpoint = component "MCP-эндпоинт" "JSON-RPC, прогресс" "laravel/mcp"
                        auth = component "Аутентификация" "Токен, роли, допуск" "Guard keycloak-bearer"
                        logout = component "Отзыв сессий" "Backchannel logout" "Controller"
                    }

                    searchTool = component "Инструмент search" "Вопрос → ответ" "MCP Tool"

                    group "Оркестрация" {
                        orchestrator = component "Оркестратор ответа" "Порядок проверок и агента" "AnswerQuestion"
                        inputGuardrails = component "Входные guardrails" "ПДн, prompt injection" "Actions + Neuron"
                        outputGuardrails = component "Выходные guardrails" "Утечка контекста, ПДн" "Actions"
                        journals = component "Журналы" "Guardrails, аудит доступа" "Eloquent"
                    }

                    group "RAG-агент" {
                        ragAgent = component "RAG-агент" "Шаги ответа, прогресс" "Neuron RAG"
                        retrieval = component "Гибридный поиск" "Вектор + граф, гриф" "KnowledgeBaseRetrieval"
                        graphClient = component "Клиент графа" "Связанные чанки" "KnowledgeGraph"
                        reranker = component "Отбор фрагментов" "Реранкер, порог, дубли" "PostProcessors"
                        answerNode = component "Генерация ответа" "Контекст, источники, отказ" "GroundedContextNode"
                    }

                    tracing = component "Трейсинг" "Спаны запроса" "OpenTelemetry"
                }
                worker = container "Воркер документов" "Извлечение и чанкование" "Laravel queue"
                graphWorker = container "Воркер графа" "ПДн, граф, индекс" "Laravel queue"
            }

            group "Data Plane: хранилища" {
                postgres = container "Реляционная БД" "Метаданные, аудит" "PostgreSQL" "Database"
                redis = container "Кэш и очереди" "Очереди, сессии" "Redis" "Database"
                qdrant = container "Векторный индекс" "Чанки" "Qdrant" "Database"
                neo4j = container "Граф знаний" "Сущности и связи" "Neo4j" "Database"
                documents = container "Хранилище документов" "Оригиналы и текст" "Docker volume" "Folder"
            }

            group "Data Plane: модели" {
                vllmGenerate = container "Генеративная LLM" "Qwen3.6-35B-A3B" "vLLM" "Модель"
                vllmEmbedding = container "Эмбеддинги" "Giga-Embeddings" "vLLM" "Модель"
                vllmReranker = container "Реранкер" "BGE reranker" "vLLM" "Модель"
                docling = container "Извлечение текста" "API задач" "docling-serve" "Модель"
                doclingWorker = container "OCR и разбор" "GPU-воркер" "docling rq-worker" "Модель"
            }

            group "Наблюдаемость" {
                alloy = container "Сборщик телеметрии" "Трейсы и логи" "Grafana Alloy" "Наблюдаемость"
                tempo = container "Трейсы" "" "Tempo" "Наблюдаемость"
                loki = container "Логи" "" "Loki" "Наблюдаемость"
                prometheus = container "Метрики" "" "Prometheus" "Наблюдаемость"
                dcgm = container "Метрики GPU" "" "DCGM exporter" "Наблюдаемость"
                grafana = container "Дашборды" "" "Grafana" "Наблюдаемость"
            }
        }

        employee -> chat "Задаёт вопросы" "HTTPS"
        employee -> keycloak "Входит в чат и MCP" "OIDC"
        kbAdmin -> keycloak "Входит" "OIDC"

        chat -> keycloak "Получает токены" "OIDC, OAuth 2.0"
        chat -> platform "Поиск информации" "MCP, Bearer"
        platform -> chat "Стриминг ответа" "SSE"

        platform -> keycloak "Проверяет токены" "OIDC"
        keycloak -> platform "Отзывает сессии" "Back-Channel Logout"

        kbAdmin -> platform "Загружает документы, смотрит дашборды" "HTTPS"

        # Вход
        chat -> platform.nginx "Поиск информации" "MCP, Bearer"
        platform.nginx -> chat "Стриминг ответа" "SSE"
        chat -> platform.vllmGenerate "Запрашивает генерацию" "OpenAI API"
        kbAdmin -> platform.nginx "Работает в админке и Grafana" "HTTPS"
        keycloak -> platform.nginx "Отзывает сессии" "Back-Channel Logout"
        platform.nginx -> platform.app "Проксирует" "FastCGI"
        platform.nginx -> platform.grafana "Проксирует" "HTTP" "Telemetry"

        # Запрос
        platform.app -> keycloak "Проверяет токены" "OIDC"
        platform.app -> platform.redis "Хранит сессии, ставит задачи" "RESP"
        platform.app -> platform.postgres "Пишет документы и аудит" "SQL"
        platform.app -> platform.vllmEmbedding "Векторизует вопрос" "HTTP" "Query"
        platform.app -> platform.qdrant "Ищет чанки" "HTTP" "Query"
        platform.app -> platform.neo4j "Ищет по графу" "Bolt" "Query"
        platform.app -> platform.vllmReranker "Ранжирует чанки" "HTTP" "Query"
        platform.app -> platform.vllmGenerate "Запрашивает ответ и проверки" "OpenAI API" "Query"

        # Загрузка
        platform.app -> platform.documents "Сохраняет оригиналы" "Файлы" "Ingest"
        platform.worker -> platform.redis "Берёт задачи" "RESP" "Ingest"
        platform.worker -> platform.postgres "Пишет статусы" "SQL" "Ingest"
        platform.worker -> platform.documents "Читает оригиналы, пишет текст" "Файлы" "Ingest"
        platform.worker -> platform.docling "Извлекает текст, чанкует" "HTTP" "Ingest"
        platform.worker -> platform.qdrant "Сбрасывает индекс" "HTTP" "Ingest"
        platform.worker -> platform.neo4j "Сбрасывает граф" "Bolt" "Ingest"
        platform.graphWorker -> platform.redis "Берёт задачи" "RESP" "Ingest"
        platform.graphWorker -> platform.postgres "Пишет прогресс" "SQL" "Ingest"
        platform.graphWorker -> platform.vllmGenerate "Маскирует ПДн, извлекает граф" "OpenAI API" "Ingest"
        platform.graphWorker -> platform.vllmEmbedding "Векторизует чанки" "HTTP" "Ingest"
        platform.graphWorker -> platform.qdrant "Индексирует чанки" "HTTP" "Ingest"
        platform.graphWorker -> platform.neo4j "Пишет граф" "Bolt" "Ingest"
        platform.docling -> platform.redis "Ставит задачи" "RESP" "Ingest"
        platform.doclingWorker -> platform.redis "Берёт задачи" "RESP" "Ingest"
        platform.doclingWorker -> platform.vllmGenerate "Распознаёт изображения" "OpenAI API" "Ingest"

        # Телеметрия
        platform.app -> platform.alloy "Шлёт трейсы" "OTLP" "Telemetry"
        platform.worker -> platform.alloy "Шлёт трейсы" "OTLP" "Telemetry"
        platform.graphWorker -> platform.alloy "Шлёт трейсы" "OTLP" "Telemetry"
        platform.alloy -> platform.nginx "Читает логи" "Файлы" "Telemetry"
        platform.alloy -> platform.tempo "Передаёт трейсы" "OTLP" "Telemetry"
        platform.alloy -> platform.loki "Передаёт логи" "HTTP" "Telemetry"
        platform.tempo -> platform.prometheus "Пишет span-метрики" "Remote write" "Telemetry"
        platform.prometheus -> platform.vllmGenerate "Собирает метрики" "HTTP" "Telemetry"
        platform.prometheus -> platform.vllmEmbedding "Собирает метрики" "HTTP" "Telemetry"
        platform.prometheus -> platform.vllmReranker "Собирает метрики" "HTTP" "Telemetry"
        platform.prometheus -> platform.qdrant "Собирает метрики" "HTTP" "Telemetry"
        platform.prometheus -> platform.dcgm "Собирает метрики GPU" "HTTP" "Telemetry"
        platform.grafana -> platform.prometheus "Читает метрики" "PromQL" "Telemetry"
        platform.grafana -> platform.loki "Читает логи" "LogQL" "Telemetry"
        platform.grafana -> platform.tempo "Читает трейсы" "TraceQL" "Telemetry"
        platform.grafana -> keycloak "Выполняет вход" "OIDC" "Telemetry"

        # L3: MCP-сервер
        platform.nginx -> platform.app.mcpEndpoint "Проксирует запрос" "FastCGI"
        platform.nginx -> platform.app.logout "Проксирует logout" "FastCGI"
        platform.app.mcpEndpoint -> platform.app.auth "Аутентифицирует"
        platform.app.mcpEndpoint -> platform.app.searchTool "Вызывает инструмент"
        platform.app.mcpEndpoint -> platform.app.tracing "Открывает спан запроса"
        platform.app.auth -> keycloak "Загружает ключи" "JWKS"
        platform.app.auth -> platform.redis "Кэширует ключи, проверяет sid" "RESP"
        platform.app.auth -> platform.postgres "Синхронизирует пользователя" "SQL"
        platform.app.logout -> platform.app.auth "Проверяет подпись"
        platform.app.logout -> platform.redis "Отзывает sid" "RESP"
        platform.app.searchTool -> platform.app.orchestrator "Передаёт вопрос и допуск"
        platform.app.orchestrator -> platform.app.inputGuardrails "Проверяет вопрос"
        platform.app.orchestrator -> platform.app.ragAgent "Запускает агента"
        platform.app.orchestrator -> platform.app.outputGuardrails "Проверяет ответ"
        platform.app.orchestrator -> platform.app.journals "Пишет события"
        platform.app.orchestrator -> platform.app.tracing "Открывает спаны шагов"
        platform.app.inputGuardrails -> platform.vllmGenerate "Классифицирует вопрос" "OpenAI API"
        platform.app.ragAgent -> platform.app.retrieval "Ищет фрагменты"
        platform.app.ragAgent -> platform.app.reranker "Ранжирует фрагменты"
        platform.app.ragAgent -> platform.app.answerNode "Формирует ответ"
        platform.app.retrieval -> platform.vllmEmbedding "Векторизует вопрос" "HTTP"
        platform.app.retrieval -> platform.qdrant "Ищет с фильтром грифа" "HTTP"
        platform.app.retrieval -> platform.app.graphClient "Расширяет по графу"
        platform.app.retrieval -> platform.postgres "Читает метаданные" "SQL"
        platform.app.graphClient -> platform.neo4j "Читает связи" "Bolt"
        platform.app.reranker -> platform.vllmReranker "Оценивает фрагменты" "HTTP"
        platform.app.answerNode -> platform.vllmGenerate "Запрашивает ответ" "OpenAI API"
        platform.app.journals -> platform.postgres "Пишет журналы" "SQL"
        platform.app.tracing -> platform.alloy "Шлёт трейсы" "OTLP"
    }

    views {
        systemLandscape "Landscape" {
            title "Ландшафт Заказчика"
            include *
            autolayout lr
        }

        systemContext platform "L1-Context" {
            title "C4 L1 — Контекст Платформы"
            include * employee
            autolayout lr
        }

        container platform "L2-Containers" {
            title "C4 L2 — Контейнеры Платформы"
            include * employee
            exclude "element.tag==Наблюдаемость"
            autolayout lr
        }

        container platform "L2-Query" {
            title "C4 L2 — Путь вопроса"
            include employee chat keycloak platform.nginx platform.app platform.redis platform.postgres platform.vllmEmbedding platform.qdrant platform.neo4j platform.vllmReranker platform.vllmGenerate
            exclude relationship.tag==Ingest relationship.tag==Telemetry
            autolayout lr
        }

        container platform "L2-Ingestion" {
            title "C4 L2 — Путь документа"
            include kbAdmin platform.nginx platform.app platform.documents platform.redis platform.postgres platform.worker platform.graphWorker platform.docling platform.doclingWorker platform.vllmGenerate platform.vllmEmbedding platform.qdrant platform.neo4j
            exclude relationship.tag==Query relationship.tag==Telemetry
            autolayout lr
        }

        container platform "L2-Observability" {
            title "C4 L2 — Наблюдаемость"
            include kbAdmin keycloak platform.nginx platform.app platform.worker platform.graphWorker platform.vllmGenerate platform.vllmEmbedding platform.vllmReranker platform.qdrant "element.tag==Наблюдаемость"
            exclude "platform.nginx -> platform.app" "keycloak -> platform.nginx" "platform.app -> keycloak" relationship.tag==Query relationship.tag==Ingest
            autolayout lr
        }

        component platform.app "L3-MCP" {
            title "C4 L3 — MCP-сервер"
            include *
            autolayout lr
        }

        styles {
            element "Element" {
                color #ffffff
            }
            element "Person" {
                shape Person
                background #08427b
            }
            element "Software System" {
                background #1168bd
            }
            element "Container" {
                background #438dd5
            }
            element "Component" {
                background #85bbf0
                color #000000
            }
            element "Database" {
                shape Cylinder
            }
            element "Folder" {
                shape Folder
            }
            element "Модель" {
                background #5b8c5a
            }
            element "Наблюдаемость" {
                background #8a6fb0
            }
            element "Система Заказчика" {
                background #999999
            }
            relationship "Relationship" {
                color #707070
            }
        }
    }

}
