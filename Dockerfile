# -----------------
FROM composer:2.6.3@sha256:1ac7a547cb88acb0de62663b70f2b3d80ad27355288245159404b6ae40cd9ca3 AS build-env

COPY . /opt/ghsec-jira/

WORKDIR /opt/ghsec-jira

RUN composer install --prefer-dist --no-dev

# -----------------
FROM php:8.2.10-alpine3.18@sha256:b5884ca8bf409cf571b321143ff30cfea16b9abab7245b9742343d3eee4abf3b

COPY --from=build-env /opt/ghsec-jira/ /opt/ghsec-jira/

ENTRYPOINT ["/opt/ghsec-jira/bin/ghsec-jira", "sync"]
