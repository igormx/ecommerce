# Runs backend tooling inside a throwaway container (the `dev` stage of
# backend/Dockerfile) with ./backend mounted, so no local PHP/composer is needed.

DEV_IMAGE ?= ecommerce-backend-dev
RUN       = docker run --rm -u $(shell id -u):$(shell id -g) -e COMPOSER_HOME=/tmp/composer \
            -v $(CURDIR)/backend:/app -w /app $(DEV_IMAGE)
PHP       = $(RUN) php

.PHONY: help build install test test-db-up test-db-down test-concurrency stan cs cs-fix check shell

help:
	@echo "make build    build the dev image"
	@echo "make install  composer install (with dev deps)"
	@echo "make test     run phpunit (ARGS='--filter Foo' to narrow)"
	@echo "make test-concurrency  run the MySQL-backed concurrency/locking tests against a throwaway DB"
	@echo "make test-db-down     remove that throwaway DB"
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

# Throwaway MySQL for the tests that need real parallelism (tests/Concurrency, *MySqlTest).
# Separate container, network and (tmpfs) storage from the dev stack; also published on
# 127.0.0.1:3310 for running phpunit from the host with TEST_DB_HOST=127.0.0.1 TEST_DB_PORT=3310.
TEST_DB_NAME_C ?= ecommerce-test-db
TEST_NET       ?= ecommerce-test-net

test-db-up:
	@docker network inspect $(TEST_NET) >/dev/null 2>&1 || docker network create $(TEST_NET) >/dev/null
	@docker inspect $(TEST_DB_NAME_C) >/dev/null 2>&1 || docker run -d --name $(TEST_DB_NAME_C) --network $(TEST_NET) \
		-p 127.0.0.1:3310:3306 --tmpfs /var/lib/mysql -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=ecommerce_test \
		mysql:8.4 --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci --innodb-ft-min-token-size=2 >/dev/null
	@echo "waiting for test MySQL..."; \
	until docker exec $(TEST_DB_NAME_C) mysql -uroot -proot -h127.0.0.1 -e 'SELECT 1' ecommerce_test >/dev/null 2>&1; do sleep 1; done

test-db-down:
	-docker rm -f $(TEST_DB_NAME_C)
	-docker network rm $(TEST_NET)

test-concurrency: build test-db-up
	docker run --rm -u $(shell id -u):$(shell id -g) -e COMPOSER_HOME=/tmp/composer --network $(TEST_NET) \
		-e TEST_DB_HOST=$(TEST_DB_NAME_C) -e TEST_DB_NAME=ecommerce_test -e TEST_DB_USER=root -e TEST_DB_PASSWORD=root \
		-v $(CURDIR)/backend:/app -w /app $(DEV_IMAGE) \
		php vendor/phpunit/phpunit/phpunit --filter "PurchaseConcurrencyTest|ProductOptimisticLockMySqlTest" $(ARGS)

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
