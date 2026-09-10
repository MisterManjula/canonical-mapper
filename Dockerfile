FROM php:8.3-cli

# php:8.3-cli already ships dom, libxml, json, mbstring and ctype, which is every
# extension the three adapters parse with. The only thing missing is unzip:
# without it Composer cannot unpack the dist archives it downloads and falls back
# to cloning every package from source.
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Deliberately no `COPY . .` and no `composer install` at build time.
#
# There is no service to ship. The mapper is a pure transformation with no HTTP
# surface and no database, so this image is a toolbox rather than a build
# artifact: it exists to lend a PHP binary to a bind-mounted working copy on a
# host that has none. Baking the source in would mean rebuilding on every edit
# in exchange for nothing, and it would make `docker compose run` ambiguous
# about which copy of the code it just analysed.
CMD ["php", "-v"]
