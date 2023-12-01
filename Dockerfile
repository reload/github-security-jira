# -----------------
FROM composer:2.6.5@sha256:f4c4dff6df0c7f514357924f982a19316f2ce10cabb90816eafd07ef04c605d3 AS build-env

COPY . /opt/ghsec-jira/

WORKDIR /opt/ghsec-jira

RUN composer install --prefer-dist --no-dev

# -----------------
FROM php:8.3.0-alpine3.18@sha256:72ce7e0cf01d9325c30f1d5c4a588fa794c2e1620dd7c087f5a682cc9c27ff39

COPY --from=build-env /opt/ghsec-jira/ /opt/ghsec-jira/

ENTRYPOINT ["/opt/ghsec-jira/bin/ghsec-jira", "sync"]
