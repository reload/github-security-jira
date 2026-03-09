.PHONY: check phpstan phpcs phpunit markdownlint

check: phpstan phpcs phpunit markdownlint

phpstan:
	-vendor/bin/phpstan analyse .

phpcs:
	-vendor/bin/phpcs -s bin/ src/

phpunit:
	-vendor/bin/phpunit

# gem install mdl
markdownlint:
	-mdl *.md
