# syntax=docker/dockerfile:1
FROM node:26.12.1-alpine@sha256:40cd011b55415bc894e45b02fdd5a5dfde056921b7d23a64c74f80497af48797

ARG UID=1000
ARG GID=1000

# Ensure non-root node user directory and npm cache exist with correct ownership
RUN mkdir -p /home/node/.npm /var/www/html \
    && chown -R ${UID}:${GID} /home/node/.npm /var/www/html

USER ${UID}:${GID}
