# -----------------
FROM composer:2.6.5@sha256:0ec8a8f72dbd9bdc7e51ba5c0be475520fda1bbef8b9df521fd18ddb1d7d6216 AS build-env

COPY . /opt/ghsec-jira/

WORKDIR /opt/ghsec-jira

RUN composer install --prefer-dist --no-dev

# -----------------
FROM php:8.3.0-alpine3.18@sha256:e0bf4d280c5210f9e970700dfbe153fa6688385b4d6375902fc957d81a04df1b

COPY --from=build-env /opt/ghsec-jira/ /opt/ghsec-jira/

ENTRYPOINT ["/opt/ghsec-jira/bin/ghsec-jira", "sync"]
