# Runs backend tooling inside a throwaway container (the `dev` stage of
# backend/Dockerfile) with ./backend mounted, so no local PHP/composer is needed.

DEV_IMAGE ?= ecommerce-backend-dev
RUN       = docker run --rm -u $(shell id -u):$(shell id -g) -e COMPOSER_HOME=/tmp/composer \
            -v $(CURDIR)/backend:/app -w /app $(DEV_IMAGE)
PHP       = $(RUN) php

.PHONY: help build install test stan cs cs-fix check shell

help:
	@echo "make build    build the dev image"
	@echo "make install  composer install (with dev deps)"
	@echo "make test     run phpunit (ARGS='--filter Foo' to narrow)"
	@echo "make stan     run phpstan"
	@echo "make cs       run phpcs"
	@echo "make cs-fix   run phpcbf"
	@echo "make check    cs + stan + test"
	@echo "make shell    open a shell in the dev container"

build:
	docker build -q --target dev -t $(DEV_IMAGE) backend

install: build
	$(RUN) composer install --no-interaction

test: build
	$(PHP) vendor/phpunit/phpunit/phpunit $(ARGS)

stan: build
	$(PHP) vendor/bin/phpstan analyse --memory-limit=512M --no-progress

cs: build
	$(PHP) vendor/bin/phpcs

cs-fix: build
	$(PHP) vendor/bin/phpcbf

check: cs stan test

shell: build
	docker run --rm -it -u $(shell id -u):$(shell id -g) -e COMPOSER_HOME=/tmp/composer \
		-v $(CURDIR)/backend:/app -w /app $(DEV_IMAGE) bash
