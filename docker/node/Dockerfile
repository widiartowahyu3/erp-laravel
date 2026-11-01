# syntax=docker/dockerfile:1
FROM node:26.13.0-alpine@sha256:c6ad9e9c1d80f6eb607c26b4b0a132ff7a2344fad1c78ea3baa2a7d4c8de6d94

ARG UID=1000
ARG GID=1000

# Ensure non-root node user directory and npm cache exist with correct ownership
RUN mkdir -p /home/node/.npm /var/www/html \
    && chown -R ${UID}:${GID} /home/node/.npm /var/www/html

USER ${UID}:${GID}
