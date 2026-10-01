#!/bin/sh
# Source: anonymized production Laravel project
# Hook /etc/entrypoint.d/ (serversideup/php): all executable NN-*.sh from this
# directories are automatically picked up entrypoint'ohm at EVERY container start,
# in order of numeric prefixes (10-, 20-, ...).
#
# Installation in Dockerfile:
#   COPY docker/php/entrypoint.d/20-init-media-permissions.sh /etc/entrypoint.d/20-init-media-permissions.sh
#   RUN chmod +x /etc/entrypoint.d/20-init-media-permissions.sh
#
# Example: setting rights to public/media — directory appears on bind-mount
# after build, therefore chown in Dockerfile doesn't help.
set -eu

MEDIA_DIR="/var/www/html/public/media"

mkdir -p "$MEDIA_DIR"

# chown is only possible under root (container with user "0:0" or PUID=0);
# under non-root hook remains no-op and the start does not fail.
if [ "$(id -u)" -eq 0 ]; then
    chown -R www-data:www-data "$MEDIA_DIR"
    chmod -R ug+rwX "$MEDIA_DIR"
fi
