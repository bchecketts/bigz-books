test: phpstan phpcs

phpstan:
	./vendor/bin/phpstan analyze -c etc/phpstan.neon --no-progress

phpcs:
	./vendor/bin/phpcs app/ bin/ maint/ --standard=etc/phpcs-ruleset.xml -s

phpcbf:
	./vendor/bin/phpcbf app/ bin/ maint/ --standard=etc/phpcs-ruleset.xml

install-hooks:
	git config core.hooksPath .githooks
	@echo "Hooks installed. .githooks/pre-commit will run on commit."

