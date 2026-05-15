FROM composer:2.9.8@sha256:2e558bf87f17c847d9f14e72ecedbeee0f8564efee0a3ba93d24a72cb59593b5 AS composer
FROM php:8.3.7-alpine3.18@sha256:3da837b84db645187ae2f24ca664da3faee7c546f0e8d930950b12d24f0d8fa0

COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY . /opt/ghsec-jira/

WORKDIR /opt/ghsec-jira

RUN composer install --prefer-dist --no-dev

ENTRYPOINT ["/opt/ghsec-jira/bin/ghsec-jira", "sync", "-vvv"]
