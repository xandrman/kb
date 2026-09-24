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
                app = container "MCP-сервер и админ-панель" "GraphRAG, RBAC, guardrails" "Laravel, php-fpm"
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

        # Deployment: стенд на эталонном сервере (ТЗ 7.2), по infra/*.tf
        stand = deploymentEnvironment "Стенд" {
            corporate = deploymentNode "Корпоративная сеть" "" "" "Внешняя сеть" {
                browser = infrastructureNode "Браузер" "Сотрудник, администратор" "HTTPS"
            }

            server = deploymentNode "Сервер" "EPYC, 4× RTX 3090, 96 ГБ VRAM" "Linux, Docker" {
                dockerd = infrastructureNode "Docker Engine" "Логи контейнеров" "syslog-драйвер"

                dmz = deploymentNode "kb-dmz" "Сегмент DMZ" "Docker network" "DMZ" {
                    nginx = containerInstance platform.nginx
                    certbot = infrastructureNode "certbot" "Сертификаты TLS, только стенд" "Let's Encrypt"
                    hfCli = infrastructureNode "hf-cli" "Загрузка моделей, только стенд" "Hugging Face"
                }

                internal = deploymentNode "kb-internal" "Сегмент Internal, без выхода наружу" "Docker network, internal" "Internal" {
                    deploymentNode "Control Plane" "" "Docker" {
                        containerInstance platform.app
                        containerInstance platform.worker
                        deploymentNode "kb-graph-worker" "" "Docker" "" 8 {
                            containerInstance platform.graphWorker
                        }
                    }

                    deploymentNode "Хранилища" "" "Docker" {
                        containerInstance platform.postgres
                        containerInstance platform.redis
                        containerInstance platform.qdrant
                        containerInstance platform.neo4j
                        containerInstance platform.documents
                    }

                    deploymentNode "GPU 0–1" "2× RTX 3090, TP=2" "NVIDIA runtime" "GPU" {
                        containerInstance platform.vllmGenerate
                    }
                    deploymentNode "GPU 2" "RTX 3090" "NVIDIA runtime" "GPU" {
                        containerInstance platform.vllmEmbedding
                    }
                    deploymentNode "GPU 3" "RTX 3090, общая" "NVIDIA runtime" "GPU" {
                        containerInstance platform.vllmReranker
                        containerInstance platform.doclingWorker
                    }
                    deploymentNode "Docling API" "" "Docker" {
                        containerInstance platform.docling
                    }

                    obs = deploymentNode "Наблюдаемость" "" "Docker" {
                        alloy = containerInstance platform.alloy
                        containerInstance platform.tempo
                        containerInstance platform.loki
                        containerInstance platform.prometheus
                        containerInstance platform.dcgm
                        containerInstance platform.grafana
                    }

                    deploymentNode "Системы Заказчика" "На стенде — свои экземпляры" "Docker" {
                        softwareSystemInstance keycloak
                        softwareSystemInstance chat
                    }
                }
            }

            stand.corporate.browser -> stand.server.dmz.nginx "Открывает" "HTTPS: 443, 8001–8003"
            stand.server.dockerd -> stand.server.internal.obs.alloy "Шлёт логи контейнеров" "syslog UDP, kb-logs"
        }
    }

    views {
        deployment platform "Стенд" "Deployment" {
            title "Deployment — стенд на эталонном сервере"
            include *
            exclude relationship.tag==Query relationship.tag==Ingest relationship.tag==Telemetry
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
            element "Deployment Node" {
                color #000000
            }
            element "Infrastructure Node" {
                background #ffffff
                color #000000
            }
            element "DMZ" {
                stroke #c0392b
                strokeWidth 4
            }
            element "Internal" {
                stroke #27ae60
                strokeWidth 4
            }
            element "GPU" {
                stroke #5b8c5a
            }
            element "Внешняя сеть" {
                stroke #999999
            }
            relationship "Relationship" {
                color #707070
            }
        }
    }

}
