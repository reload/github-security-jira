# Build arguments for version flexibility
ARG PHP_VERSION=8.3
ARG COMPOSER_VERSION=2

# -----------------
# Get Composer binary from official image
FROM public.ecr.aws/docker/library/composer:${COMPOSER_VERSION} AS composer

# -----------------
# Build stage: install dependencies using matching PHP version
FROM public.ecr.aws/docker/library/php:${PHP_VERSION}-alpine AS build-env

COPY --from=composer /usr/bin/composer /usr/bin/composer

COPY . /opt/ghsec-jira/

WORKDIR /opt/ghsec-jira

RUN composer install --prefer-dist --no-dev

# -----------------
# Runtime stage
FROM public.ecr.aws/docker/library/php:${PHP_VERSION}-alpine

COPY --from=build-env /opt/ghsec-jira/ /opt/ghsec-jira/

ENTRYPOINT ["/opt/ghsec-jira/bin/ghsec-jira", "sync", "-vvv"]
