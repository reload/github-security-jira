FROM composer:2.11.1@sha256:537fb03057ed901ae06bde7ef25af348903485ae1acc7fc789b80d8bac666f35 AS composer
FROM php:8.4.27-alpine3.24@sha256:b58f9a66fe9734250fc42818edc77d679a071a83cefdcda87b21b668a91094b1

COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY . /opt/ghsec-jira/

WORKDIR /opt/ghsec-jira

RUN composer install --prefer-dist --no-dev

ENTRYPOINT ["/opt/ghsec-jira/bin/ghsec-jira", "sync", "-vvv"]
