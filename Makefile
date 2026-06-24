.PHONY: lint test

test: lint phpunit

lint:
	find . -name \*.php -not -path "./vendor/*" -print0 | xargs -0 -n 1 -P 4 php -d display_errors=stderr -l > /dev/null

# Runs the fast, WordPress-free unit suite. For the integration suite use
# `composer test:integration` (requires wp-env).
phpunit:
	./vendor/bin/phpunit --testsuite unit