# -----------------
FROM composer:2.6.5@sha256:de2dcd353138a25e0b7aa062896619016ce386a7053edfe91f9d999e81961aca AS build-env

COPY . /opt/ghsec-jira/

WORKDIR /opt/ghsec-jira

RUN composer install --prefer-dist --no-dev

# -----------------
FROM php:8.2.11-alpine3.18@sha256:671c309315113b73eba316bb175e130f376d3ba5e1a930794909ef5a1cb10fbc

COPY --from=build-env /opt/ghsec-jira/ /opt/ghsec-jira/

ENTRYPOINT ["/opt/ghsec-jira/bin/ghsec-jira", "sync"]
