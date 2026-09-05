# syntax=docker/dockerfile:1
#
# One image, three roles.
#
# The FPM container, the queue worker and the scheduler all run the same
# application code and shell out to the same external binaries, so building them
# from one image means a dependency can never be present for the web request that
# enqueues a job and missing for the worker that runs it.
#
# Targets:
#   base      shared runtime (used by the worker and scheduler)
#   dev       + Xdebug-less dev conveniences, runs php-fpm      <- docker-compose
#   prod      + baked-in vendor/, opcache preloading friendly   <- see docs/DEPLOYMENT.md
#
FROM php:8.4-fpm-bookworm AS base

# System packages. Each one is here because the application actually calls it:
#
#   poppler-utils   `pdfinfo` is exec()'d by App\Console\Commands\AuditContent to
#                   re-count PDF pages independently of the importer, and
#                   pdftotext/pdftohtml/pdfimages are used by tools/*.py.
#   espeak-ng       App\AI\Providers\EspeakTtsProvider shells out to it as the
#                   offline TTS fallback in the audio chain.
#   ffmpeg          learner audio arrives in whatever the phone recorded; whisper.cpp
#                   wants 16 kHz mono WAV. The conversion step is not written yet, so
#                   ffmpeg is here ready for the speech pipeline rather than in use.
#   python3         tools/analyze_sources.py, extract_content.py, extract_images.py.
#   git-lfs         the audio archives and page images are LFS objects.
#   supervisor      runs the six queue-worker process pools in the worker container
#                   (docker/worker/supervisord.conf).
#
# Deliberately NOT installed: the `higgsfield` CLI and `whisper-cli`. Both are
# host-authenticated / model-bearing tools; see docs/DEPLOYMENT.md for how they are
# provided in production. Their absence is not fatal — the provider's isAvailable()
# returns false and AiOrchestrator falls through to the next link in the chain.
RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        ffmpeg \
        espeak-ng \
        git \
        git-lfs \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libzip-dev \
        poppler-utils \
        python3 \
        supervisor \
        unzip \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions.
#   pdo_mysql  MySQL 8 is the only supported production driver (see config/database.php)
#   gd         App\AI\Providers\PlaceholderImageProvider builds its fallback card with GD
#              and reports isAvailable() === false without it
#   pcntl      queue:work needs it for timeouts and graceful SIGTERM on redeploy
#   intl/zip/bcmath  framework + composer baseline
#   opcache    bundled but off by default
#   redis      cache, session and queue backend in the compose stack
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        gd \
        intl \
        opcache \
        pcntl \
        pdo_mysql \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# The application lives at <repo>/backend and reads sources through base_path('..'),
# so the whole repository — not just backend/ — has to be the container's root.
# ImportCurriculum, AuditContent and BuildActivities all resolve sources/, docs/data/
# and tools/ relative to the repository root.
WORKDIR /srv/zaban/backend

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-zaban.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-zaban.conf

# Run as a non-root user whose uid matches the developer's, so files the container
# writes into the bind mount (storage/logs, bootstrap/cache) stay editable on the host.
ARG UID=1000
ARG GID=1000
RUN groupadd -g "${GID}" -o app 2>/dev/null || true \
    && useradd -u "${UID}" -g "${GID}" -o -m -s /bin/bash app 2>/dev/null || true \
    && mkdir -p /srv/zaban /home/app/.composer \
    && chown -R "${UID}:${GID}" /srv/zaban /home/app

ENV COMPOSER_HOME=/home/app/.composer \
    COMPOSER_MEMORY_LIMIT=-1 \
    PATH="/srv/zaban/backend/vendor/bin:${PATH}"

COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint
ENTRYPOINT ["entrypoint"]


# --------------------------------------------------------------------------- dev
# Source is bind-mounted in development, so nothing is copied in: an edit on the
# host is live in the container without a rebuild.
FROM base AS dev
USER app
CMD ["php-fpm"]


# -------------------------------------------------------------------------- prod
# Self-contained image: code and vendor/ baked in, opcache validation off.
FROM base AS prod
ENV APP_ENV=production
COPY --chown=1000:1000 . /srv/zaban
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && composer clear-cache
RUN printf 'opcache.validate_timestamps=0\n' > /usr/local/etc/php/conf.d/zzz-opcache-prod.ini
USER app
CMD ["php-fpm"]
