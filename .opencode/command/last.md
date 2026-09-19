---
description: Find the latest version/tag of a container image.
---

Find the latest released version of the container image: $ARGUMENTS

Steps:
1. Determine the official registry and repository for the image (Docker Hub, ghcr.io, quay.io, etc.).
2. Search the web for the latest release/version (GitHub releases, Docker Hub tags, official docs).
3. Report the latest stable version and the full image reference to pull (e.g. `qdrant/qdrant:v1.19.1`), including any `-unprivileged` or distroless variants if relevant.

If the image name isn't given, ask which image to check.