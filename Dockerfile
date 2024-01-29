# -----------------
FROM composer:2.6.6@sha256:6d19e1002b091908fc4654725d4055125f181c1b6d7610206b0ee31b8419a5fc AS build-env

COPY . /opt/ghsec-jira/

WORKDIR /opt/ghsec-jira

RUN composer install --prefer-dist --no-dev

# -----------------
FROM php:8.3.2-alpine3.18@sha256:eac969afaba4b30c9228f9e1421188ac9997105c06dc51203fc7c4cf739bd688

COPY --from=build-env /opt/ghsec-jira/ /opt/ghsec-jira/

ENTRYPOINT ["/opt/ghsec-jira/bin/ghsec-jira", "sync", "-vvv"]
