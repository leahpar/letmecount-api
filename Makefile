PHP				= php
PHP_MAX			= php -d memory_limit=1024M
SYMFONY         = $(PHP) bin/console
SYMFONY_BIN     = symfony
COMPOSER        = composer
GIT             = git

sfstart: sfstop ## Start local Symfony webserver
	symfony server:start --allow-all-ip

sfstop: ## Stop local Symfony webserver
	symfony server:stop

tests: ## Lance l'ensemble des tests
	rm -rf var/cache/test/*
	APP_ENV=test $(SYMFONY) doctrine:schema:update --dump-sql --force
	XDEBUG_MODE=off $(PHP) ./bin/phpunit --colors=always --testdox

stan: ## Analyse statique du code
	XDEBUG_MODE=off $(PHP_MAX) ./vendor/bin/phpstan analyse --no-progress --no-interaction

doc: ## Génération de la documentation
	$(PHP) ./bin/console api:openapi:export -o doc/openapi.json
	# if ../front exists, copy the OpenAPI file there
	if [ -d ../front ]; then \
		cp -f doc/openapi.json ../front/openapi.json; \
	fi

.PHONY: sfstart sfstop tests stan doc
