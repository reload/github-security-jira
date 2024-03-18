# -----------------
FROM composer:2.7.2@sha256:63c0f08ca413700adcec721aa425e1247304c98314ed0bc2e5fc3699424e2364 AS build-env

COPY . /opt/ghsec-jira/

WORKDIR /opt/ghsec-jira

RUN composer install --prefer-dist --no-dev

# -----------------
FROM php:8.3.4-alpine3.18@sha256:9c334e1fa29715eb6640ee98233ff25130803dc975f187550990c5198c4f8cf3

COPY --from=build-env /opt/ghsec-jira/ /opt/ghsec-jira/

ENTRYPOINT ["/opt/ghsec-jira/bin/ghsec-jira", "sync", "-vvv"]
