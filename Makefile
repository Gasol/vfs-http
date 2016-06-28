PHPUNIT_CMD=vendor/bin/phpunit
all: test

test:
	${PHPUNIT_CMD} --stop-on-failure --no-coverage

ci:
	${PHPUNIT_CMD} --testdox
