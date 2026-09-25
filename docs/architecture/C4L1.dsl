workspace "Платформа корпоративных знаний" "C4-модель Платформы корпоративных знаний на базе GraphRAG (ТЗ-ТМ-2026-014)" {

    !identifiers hierarchical

    model {
        employee = person "Сотрудник" "Сервис, поддержка, продажи"
        kbAdmin = person "Администратор базы знаний" "Ведёт корпус документов"

        chat = softwareSystem "Система диалогов с LLM" "Чат сотрудников с LLM" "Система Заказчика"
        keycloak = softwareSystem "Keycloak" "Аутентификация и роли" "Система Заказчика"

        platform = softwareSystem "Платформа корпоративных знаний" "GraphRAG с RBAC в закрытом контуре"

        employee -> chat "Задаёт вопросы" "HTTPS"
        employee -> keycloak "Входит в чат и MCP" "OIDC"
        kbAdmin -> keycloak "Входит" "OIDC"

        chat -> keycloak "Получает токены" "OIDC, OAuth 2.0"
        chat -> platform "Поиск информации" "MCP, Bearer"
        platform -> chat "Стриминг ответа" "SSE"

        platform -> keycloak "Проверяет токены" "OIDC"
        keycloak -> platform "Отзывает сессии" "Back-Channel Logout"

        kbAdmin -> platform "Загружает документы, смотрит дашборды" "HTTPS"
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
            relationship "Relationship" {
                color #707070
            }
        }
    }

}
