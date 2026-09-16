FROM composer:2.10.3@sha256:aaeab4b6b031e0a88efb907f0f26b563532a644fc2f4ea0d000ecf8658f7a2b8 AS composer
FROM php:8.3.7-alpine3.18@sha256:3da837b84db645187ae2f24ca664da3faee7c546f0e8d930950b12d24f0d8fa0

COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY . /opt/ghsec-jira/

WORKDIR /opt/ghsec-jira

RUN composer install --prefer-dist --no-dev

ENTRYPOINT ["/opt/ghsec-jira/bin/ghsec-jira", "sync", "-vvv"]
