# -----------------
FROM composer:2.6.6@sha256:7f42b1495c62246a92c8271dcc9352afe58440b518225366e44563892fba122c AS build-env

COPY . /opt/ghsec-jira/

WORKDIR /opt/ghsec-jira

RUN composer install --prefer-dist --no-dev

# -----------------
FROM php:8.3.1-alpine3.18@sha256:87cd6345439dcd87a936a9483111859b81bc418cdbb0dc14e9fff448037480ff

COPY --from=build-env /opt/ghsec-jira/ /opt/ghsec-jira/

ENTRYPOINT ["/opt/ghsec-jira/bin/ghsec-jira", "sync", "-vvv"]
