FROM node:24.16.0-alpine AS src
RUN apk add --no-cache git
RUN git clone --branch claude/mcp-progress-ui --depth 1 https://github.com/xandrman/LibreChat.git /src

FROM node:24.16.0-alpine AS node
RUN apk upgrade --no-cache
RUN apk add --no-cache jemalloc
RUN apk add --no-cache python3 py3-pip uv

ENV LD_PRELOAD=/usr/lib/libjemalloc.so.2

COPY --from=ghcr.io/astral-sh/uv:0.9.5-python3.12-alpine /usr/local/bin/uv /usr/local/bin/uvx /bin/
RUN uv --version

ARG NODE_MAX_OLD_SPACE_SIZE=6144
RUN mkdir -p /app && chown node:node /app
WORKDIR /app
USER node

COPY --from=src --chown=node:node /src/package.json /src/package-lock.json ./
COPY --from=src --chown=node:node /src/api/package.json ./api/package.json
COPY --from=src --chown=node:node /src/client/package.json ./client/package.json
COPY --from=src --chown=node:node /src/packages/data-provider/package.json ./packages/data-provider/package.json
COPY --from=src --chown=node:node /src/packages/data-schemas/package.json ./packages/data-schemas/package.json
COPY --from=src --chown=node:node /src/packages/api/package.json ./packages/api/package.json

RUN touch .env ; \
    mkdir -p /app/client/public/images /app/logs /app/uploads /app/skill ; \
    npm config set fetch-retry-maxtimeout 600000 ; \
    npm config set fetch-retries 5 ; \
    npm config set fetch-retry-mintimeout 15000 ; \
    npm ci --no-audit

COPY --from=src --chown=node:node /src/. .

RUN NODE_OPTIONS="--max-old-space-size=${NODE_MAX_OLD_SPACE_SIZE}" npm run frontend ; \
    npm prune --production ; \
    npm cache clean --force

ARG BUILD_COMMIT=
ARG BUILD_BRANCH=claude/mcp-progress-ui
ARG BUILD_DATE=
ENV BUILD_COMMIT=${BUILD_COMMIT}
ENV BUILD_BRANCH=${BUILD_BRANCH}
ENV BUILD_DATE=${BUILD_DATE}

EXPOSE 3080
ENV HOST=0.0.0.0
CMD ["npm", "run", "backend"]
