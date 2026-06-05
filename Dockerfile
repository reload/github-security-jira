FROM composer:2.10.1@sha256:26cb27d1f66af16f8f4d83d522f79ce9d1ea6d80e6bc2d3926af01b000f102ba AS composer
FROM php:8.3.7-alpine3.18@sha256:3da837b84db645187ae2f24ca664da3faee7c546f0e8d930950b12d24f0d8fa0

COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY . /opt/ghsec-jira/

WORKDIR /opt/ghsec-jira

RUN composer install --prefer-dist --no-dev

ENTRYPOINT ["/opt/ghsec-jira/bin/ghsec-jira", "sync", "-vvv"]
