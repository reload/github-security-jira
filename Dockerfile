# -----------------
FROM composer:2.6.4@sha256:fd0b4f28a5070d4361d5cbfe7f7807a10bf2efe9396e42009f9e63f7fa307e38 AS build-env

COPY . /opt/ghsec-jira/

WORKDIR /opt/ghsec-jira

RUN composer install --prefer-dist --no-dev

# -----------------
FROM php:8.2.10-alpine3.18@sha256:a8f070674c651f09562514ba2a49091bfd0208cafcd5a3619167edc9756439af

COPY --from=build-env /opt/ghsec-jira/ /opt/ghsec-jira/

ENTRYPOINT ["/opt/ghsec-jira/bin/ghsec-jira", "sync"]
