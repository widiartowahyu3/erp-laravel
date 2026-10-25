# syntax=docker/dockerfile:1
FROM node:24.22.1-alpine@sha256:06d7adf3e0ef61a13f1d28682726366f97a15aa1888c934d3ecec86c33918b53

ARG UID=1000
ARG GID=1000

# Ensure non-root node user directory and npm cache exist with correct ownership
RUN mkdir -p /home/node/.npm /var/www/html \
    && chown -R ${UID}:${GID} /home/node/.npm /var/www/html

USER ${UID}:${GID}
