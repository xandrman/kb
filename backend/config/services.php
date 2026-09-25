<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'keycloak' => [
        'client_id' => env('KEYCLOAK_CLIENT_ID'),
        'client_secret' => env('KEYCLOAK_CLIENT_SECRET'),
        'redirect' => env('KEYCLOAK_REDIRECT_URI'),
        'base_url' => env('KEYCLOAK_BASE_URL'),
        'realms' => env('KEYCLOAK_REALM'),
    ],

    // ADR-0012: извлечение текста; VLM-маршрут распознаёт сканы моделью kb-vllm-generate
    'docling' => [
        'url' => env('DOCLING_URL'),
        'vlm_url' => env('DOCLING_VLM_URL'),
        'vlm_model' => env('DOCLING_VLM_MODEL', 'default'),
        // Токенизатор модели эмбеддингов внутри контейнера docling: иначе размер чанка в токенах не совпадёт с моделью
        'chunk_tokenizer' => env('DOCLING_CHUNK_TOKENIZER'),
        'chunk_max_tokens' => (int) env('DOCLING_CHUNK_MAX_TOKENS', 512),
    ],

    // FR-9: трейсы приложения в kb-alloy по OTLP/HTTP; без адреса трассировка выключена
    'otel' => [
        'traces_endpoint' => env('OTEL_EXPORTER_OTLP_TRACES_ENDPOINT'),
        'service_name' => env('OTEL_SERVICE_NAME', 'kb-app'),
    ],

    // ADR-0009: генеративная модель kb-vllm-generate — извлечение графа (FR-4)
    'llm' => [
        'url' => env('LLM_URL'),
        'model' => env('LLM_MODEL', 'default'),
    ],

    // ADR-0010: эмбеддинги чанков в kb-vllm-embedding
    'embedding' => [
        'url' => env('EMBEDDING_URL'),
        'model' => env('EMBEDDING_MODEL', 'default'),
    ],

    // ADR-0011: реранкер kb-vllm-reranker — отбор лучших чанков гибридной выдачи (FR-5)
    'reranker' => [
        'url' => env('RERANKER_URL'),
        'model' => env('RERANKER_MODEL', 'default'),
        'top_n' => (int) env('RERANKER_TOP_N', 5),
        // Ниже порога фрагмент не считается источником; на стенде нерелевантное получало 0,01–0,06, релевантное — от 0,27
        'threshold' => (float) env('RERANKER_THRESHOLD', 0.1),
    ],

    // ADR-0007: расширение выдачи через граф (FR-5)
    'graph' => [
        // Сколько чанков добавляет обход графа к векторной выдаче
        'expansion_limit' => (int) env('GRAPH_EXPANSION_LIMIT', 5),
        // Сущности с большим числом связей (модель изделия) — хабы: через них обход не идёт
        'max_degree' => (int) env('GRAPH_MAX_DEGREE', 30),
        // Второй шаг multi-hop через общую модель изделия: из скольких чанков других документов выбирать и сколько брать на модель
        'bridge_candidates' => (int) env('GRAPH_BRIDGE_CANDIDATES', 500),
        'bridge_limit' => (int) env('GRAPH_BRIDGE_LIMIT', 2),
    ],

    // Поиск по номеру акта или коду модели из вопроса: сколько идентификаторов искать и сколько чанков брать на каждый
    'identifiers' => [
        'limit' => (int) env('IDENTIFIER_SEARCH_LIMIT', 3),
        'chunks' => (int) env('IDENTIFIER_SEARCH_CHUNKS', 2),
    ],

    // ADR-0006: векторный индекс чанков; размерность снята с модели фактически (ADR-0010, п. 6)
    'qdrant' => [
        'url' => env('QDRANT_URL'),
        'key' => env('QDRANT_API_KEY'),
        'collection' => env('QDRANT_COLLECTION', 'chunks'),
        // Векторы имён сущностей графа — поиск кандидатов в синонимы (FR-4)
        'entity_collection' => env('QDRANT_ENTITY_COLLECTION', 'entities'),
        'dimension' => (int) env('QDRANT_DIMENSION', 1536),
        // Сколько чанков отдаёт векторный поиск (FR-5)
        'search_limit' => (int) env('QDRANT_SEARCH_LIMIT', 10),
    ],

    // ADR-0007: граф знаний
    'neo4j' => [
        'uri' => env('NEO4J_URI'),
        'username' => env('NEO4J_USERNAME', 'neo4j'),
        'password' => env('NEO4J_PASSWORD'),
    ],

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
