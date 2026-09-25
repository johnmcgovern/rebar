# Dev image: PHP (matching production, server) + Rust toolchain for building
# ext-php-rs extensions. Trixie because Drupal 11 needs SQLite >= 3.45.
ARG PHP_VERSION=8.5
FROM php:${PHP_VERSION}-cli-trixie

RUN apt-get update && apt-get install -y --no-install-recommends \
      git unzip curl ca-certificates build-essential clang libclang-dev \
      libpng-dev libjpeg-dev libfreetype6-dev libzip-dev libsqlite3-dev sqlite3 \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd zip $(php -m | grep -qi opcache || echo opcache) \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV RUSTUP_HOME=/usr/local/rustup CARGO_HOME=/usr/local/cargo PATH=/usr/local/cargo/bin:$PATH
RUN curl -sSf https://sh.rustup.rs | sh -s -- -y --profile minimal --default-toolchain stable

RUN { echo 'memory_limit=512M'; echo 'opcache.enable_cli=1'; } > /usr/local/etc/php/conf.d/rebar.ini

WORKDIR /work
