.PHONY: check phpstan phpcs markdownlint

check: phpstan phpcs markdownlint

phpstan:
	-vendor/bin/phpstan analyse .

phpcs:
	-vendor/bin/phpcs -s bin/ src/

# gem install mdl
markdownlint:
	-mdl *.md
