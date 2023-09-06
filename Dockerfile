# -----------------
FROM composer:2.6.2@sha256:a8847e8317196aac538d532866a7ea67155aff42af729743c1394e3ed894a39f AS build-env

COPY . /opt/ghsec-jira/

WORKDIR /opt/ghsec-jira

RUN composer install --prefer-dist --no-dev

# -----------------
FROM php:8.2.10-alpine3.18@sha256:b5884ca8bf409cf571b321143ff30cfea16b9abab7245b9742343d3eee4abf3b

COPY --from=build-env /opt/ghsec-jira/ /opt/ghsec-jira/

ENTRYPOINT ["/opt/ghsec-jira/bin/ghsec-jira", "sync"]
