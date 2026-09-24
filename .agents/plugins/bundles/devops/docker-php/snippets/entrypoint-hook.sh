#!/bin/sh
# Source: anonymized production Laravel project
# Хук /etc/entrypoint.d/ (serversideup/php): все исполняемые NN-*.sh из этой
# директории автоподхватываются entrypoint'ом при КАЖДОМ старте контейнера,
# в порядке числовых префиксов (10-, 20-, ...).
#
# Установка в Dockerfile:
#   COPY docker/php/entrypoint.d/20-init-media-permissions.sh /etc/entrypoint.d/20-init-media-permissions.sh
#   RUN chmod +x /etc/entrypoint.d/20-init-media-permissions.sh
#
# Пример: выставление прав на public/media — каталог появляется на bind-mount
# уже после build, поэтому chown в Dockerfile не помогает.
set -eu

MEDIA_DIR="/var/www/html/public/media"

mkdir -p "$MEDIA_DIR"

# chown возможен только под root (контейнер с user "0:0" или PUID=0);
# под non-root хук остаётся no-op и не валит старт.
if [ "$(id -u)" -eq 0 ]; then
    chown -R www-data:www-data "$MEDIA_DIR"
    chmod -R ug+rwX "$MEDIA_DIR"
fi
