# ローカル検証専用。公開・XServerへの適用用ではない。
FROM php:8.3-cli-bookworm@sha256:232331112d3a7c5b98edeeddc3b85f610172f99352c0635fe6b3e166e47062cb
RUN --mount=type=secret,id=system_ca,required=true \
    apt-get -o Acquire::https::CaInfo=/run/secrets/system_ca update \
    && apt-get -o Acquire::https::CaInfo=/run/secrets/system_ca install -y --no-install-recommends libonig-dev \
    && docker-php-ext-install -j2 pdo_mysql mbstring \
    && rm -rf /var/lib/apt/lists/*
RUN echo 'pdo_mysql.default_socket=/runtime/mysql/mysql.sock' > /usr/local/etc/php/conf.d/hayanex-local.ini
WORKDIR /source
