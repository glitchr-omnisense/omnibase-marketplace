# syntax=docker/dockerfile:1
#
# Standalone, runnable demo of glitchr/base-bundle-market — a bare Symfony
# skeleton with base-bundle and this checkout installed, a SQLite database,
# a one-page tour that seeds a small board and checks the wiring, and the
# forum itself at /bbs.
#
# Build from the repository root (the local checkout is what gets installed):
#
#   docker build -t base-bundle-market-demo .
#   docker run --rm -p 8000:8000 base-bundle-market-demo
#   → http://localhost:8000/
#
# base-bundle and its companions are fetched from gitlab.glitchr.dev (public).
# For the test suite instead of the tour, see Dockerfile.test / docker-compose.yml.

FROM php:8.4-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git unzip libicu-dev libzip-dev libpng-dev libjpeg-dev libwebp-dev libfreetype-dev libxslt1-dev libmagickwand-dev \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j"$(nproc)" intl zip gd bcmath ftp xsl exif \
    && pecl install imagick igbinary \
    && docker-php-ext-enable imagick igbinary

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

# --- bare Symfony skeleton ---------------------------------------------------
WORKDIR /srv/demo
RUN composer create-project symfony/skeleton . --no-interaction --no-progress

# The glitchr packages are branch releases (N.x-dev); plugins patch vendor
# code at install time (doctrine-dc2type restores DC2Type column comments on
# DBAL 4, base-plugin hooks third-party packages).
RUN composer config minimum-stability dev \
    && composer config prefer-stable true \
    && composer config extra.symfony.allow-contrib true \
    && composer config allow-plugins.glitchr/base-plugin true \
    && composer config allow-plugins.glitchr/doctrine-dc2type true \
    && composer config allow-plugins.endroid/installer true \
    && composer config allow-plugins.php-http/discovery true \
    && composer config repositories.backup-manager vcs https://gitlab.glitchr.dev/public-repository/agnostic/backup-manager \
    && composer config repositories.base-bundle vcs https://gitlab.glitchr.dev/public-repository/symfony/bundle/base/component \
    && composer config repositories.base-bundle-admin vcs https://gitlab.glitchr.dev/public-repository/symfony/bundle/base/extension/admin \
    && composer config repositories.base-plugin vcs https://gitlab.glitchr.dev/public-repository/symfony/bundle/base/plugin \
    && composer config repositories.doctrine-dc2type vcs https://gitlab.glitchr.dev/public-repository/symfony/bundle/doctrine-dc2type \
    && composer config repositories.well-known vcs https://gitlab.glitchr.dev/public-repository/symfony/bundle/well-known \
    && composer config repositories.ux-google vcs https://gitlab.glitchr.dev/public-repository/symfony/bundle/ux/google-api

# --- this checkout becomes the installed package -----------------------------
# base-bundle publishes its 3.x branch as 3.x-dev while its extensions ask for
# 3.0.*-dev: the inline alias below is the one every host application uses.
# symfony/translation is required by base-bundle's Translator without being
# declared by it; a full application always has it, a bare skeleton does not.
# (.dockerignore keeps .git, node_modules, var and vendor out of the context)
COPY . /srv/base-bundle-marketplace
# --no-scripts: Flex recipes and cache:clear would compile the container
# against recipe defaults BEFORE the demo config overlay below exists. The
# overlay ships every config file the demo needs; the container is compiled
# at first run instead.
RUN composer config repositories.base-bundle-marketplace '{"type": "path", "url": "/srv/base-bundle-marketplace", "options": {"symlink": false}}' \
    && composer require "glitchr/base-bundle:3.x-dev as 3.0.x-dev" "glitchr/base-bundle-market:*@dev" symfony/translation --no-interaction --no-progress --no-scripts \
    # Recipe leftovers for bundles the demo does not register: the
    # google/recaptcha contrib recipe references a class recaptcha 1.5 no
    # longer ships, and webauthn's expects credential repositories the demo
    # has no use for.
    && rm -f config/packages/google_recaptcha.yaml config/packages/webauthn.yaml config/routes/webauthn_routes.yaml config/routes/api_platform.yaml

# base-bundle's OrderedArrayCollection::matching() declares a return type
# doctrine/collections 3 refuses; applications carry this patch through
# cweagans/composer-patches, the demo applies it by hand.
RUN cd vendor/glitchr/base-bundle \
    && git apply -p1 /srv/base-bundle-marketplace/example/patches/glitchr-base-bundle-doctrine-collections-3.patch

# --- demo app overlay: config, one controller, two templates -----------------
COPY example/app/ ./

# composer's recipe scripts compiled the container BEFORE the overlay was
# copied — drop that stale cache so the first warmup starts clean. The
# bundle's warmers need more than the 128M default.
RUN rm -rf var/cache \
    && echo "memory_limit = 512M" > /usr/local/etc/php/conf.d/zz-demo.ini

EXPOSE 8000

# Schema into SQLite (ignored if it already exists), the bundle's public
# files (the forum stylesheet), then a plain PHP dev server.
CMD ["sh", "-c", "php bin/console doctrine:schema:create --no-interaction || true; php bin/console assets:install public --no-interaction && php bin/console cache:warmup && php -S 0.0.0.0:8000 -t public"]
