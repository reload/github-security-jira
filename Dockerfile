# -----------------
FROM composer:2.6.3@sha256:e7367fba703ba9a33ce2c360084efde06ce5031649650f1f4372ae6b018a0317 AS build-env

COPY . /opt/ghsec-jira/

WORKDIR /opt/ghsec-jira

RUN composer install --prefer-dist --no-dev

# -----------------
FROM php:8.2.10-alpine3.18@sha256:a8f070674c651f09562514ba2a49091bfd0208cafcd5a3619167edc9756439af

COPY --from=build-env /opt/ghsec-jira/ /opt/ghsec-jira/

ENTRYPOINT ["/opt/ghsec-jira/bin/ghsec-jira", "sync"]
