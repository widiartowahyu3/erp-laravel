# syntax=docker/dockerfile:1
FROM node:26.13.1-alpine@sha256:6bd9ab43c0ec0190ca7b2d193906cb8b7edf6e7f4afa43ddeccd6452710bffb5

ARG UID=1000
ARG GID=1000

# Ensure non-root node user directory and npm cache exist with correct ownership
RUN mkdir -p /home/node/.npm /var/www/html \
    && chown -R ${UID}:${GID} /home/node/.npm /var/www/html

USER ${UID}:${GID}
