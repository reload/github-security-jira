# -----------------
FROM composer:2.6.6@sha256:d07bd4ed939140ab9ef6e9d862da242cc8b27f3ef14701ca0f739bd287f2452e AS build-env

COPY . /opt/ghsec-jira/

WORKDIR /opt/ghsec-jira

RUN composer install --prefer-dist --no-dev

# -----------------
FROM php:8.3.2-alpine3.18@sha256:d918540a24839984131d044619aa40964d55f970e47f39a455e3aa3fa6fc9fc0

COPY --from=build-env /opt/ghsec-jira/ /opt/ghsec-jira/

ENTRYPOINT ["/opt/ghsec-jira/bin/ghsec-jira", "sync", "-vvv"]
