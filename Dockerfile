# -----------------
FROM composer:2.6.6@sha256:7f42b1495c62246a92c8271dcc9352afe58440b518225366e44563892fba122c AS build-env

COPY . /opt/ghsec-jira/

WORKDIR /opt/ghsec-jira

RUN composer install --prefer-dist --no-dev

# -----------------
FROM php:8.3.0-alpine3.18@sha256:46683dbf1c4cc89973745335dd1d8dff844b83cb7dd171f5f656f3dc22ebd2c9

COPY --from=build-env /opt/ghsec-jira/ /opt/ghsec-jira/

ENTRYPOINT ["/opt/ghsec-jira/bin/ghsec-jira", "sync"]
