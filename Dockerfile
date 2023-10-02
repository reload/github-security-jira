# -----------------
FROM composer:2.6.4@sha256:fd0b4f28a5070d4361d5cbfe7f7807a10bf2efe9396e42009f9e63f7fa307e38 AS build-env

COPY . /opt/ghsec-jira/

WORKDIR /opt/ghsec-jira

RUN composer install --prefer-dist --no-dev

# -----------------
FROM php:8.2.11-alpine3.18@sha256:671c309315113b73eba316bb175e130f376d3ba5e1a930794909ef5a1cb10fbc

COPY --from=build-env /opt/ghsec-jira/ /opt/ghsec-jira/

ENTRYPOINT ["/opt/ghsec-jira/bin/ghsec-jira", "sync"]
