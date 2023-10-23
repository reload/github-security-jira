# -----------------
FROM composer:2.6.5@sha256:403855481b9b080ee79c29b301b8d1817b7ad183d477dd2c1de243831a9256d3 AS build-env

COPY . /opt/ghsec-jira/

WORKDIR /opt/ghsec-jira

RUN composer install --prefer-dist --no-dev

# -----------------
FROM php:8.2.11-alpine3.18@sha256:36a956d9e6ef3b47787c002737c5b1c8cd1147731b7f537f4c22faf77a4fd174

COPY --from=build-env /opt/ghsec-jira/ /opt/ghsec-jira/

ENTRYPOINT ["/opt/ghsec-jira/bin/ghsec-jira", "sync"]
