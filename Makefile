.PHONY: up down build logs ps shell db composer npm artisan migrate migrate-test seed test lint analyse check dev verify test-hooks fresh hooks help

help:
	@echo "Available commands:"
	@echo "  make hooks        - Configure local git repository to use .githooks"
	@echo "  make test-hooks   - Run functional tests on Git hooks"
	@echo "  make up           - Start the Docker stack"
	@echo "  make down         - Stop the Docker stack"
	@echo "  make build        - Build/rebuild Docker images"
	@echo "  make logs         - View logs from all services"
	@echo "  make ps           - View status of services"
	@echo "  make shell        - Access shell inside PHP container"
	@echo "  make db           - Open MariaDB client shell"
	@echo "  make composer     - Run Composer inside PHP container (e.g., make composer cmd='--version')"
	@echo "  make npm          - Run NPM inside Node container (e.g., make npm cmd='--version')"
	@echo "  make artisan      - Run Artisan inside PHP container (e.g., make artisan cmd='migrate:status')"
	@echo "  make migrate      - Run DB migrations using mariadb_migrator connection"
	@echo "  make migrate-test - Run DB migrations on erp_trading_test using mariadb_migrator"
	@echo "  make seed         - Run DB seeders (--force)"
	@echo "  make test         - Run PHP test suite (auto migrates test database)"
	@echo "  make lint         - Run code style checks (Pint)"
	@echo "  make analyse      - Run static analysis (PHPStan/Larastan)"
	@echo "  make check        - Run all quality checks (lint, analyse, test)"
	@echo "  make dev          - Start development environment"
	@echo "  make verify       - Run the hardening verification script"
	@echo "  make fresh        - Destroy containers and volumes (requires confirmation)"

hooks:
	git config core.hooksPath .githooks
	@echo "Git hooks configured successfully to .githooks"

up:
	docker compose up -d

down:
	docker compose down

build:
	docker compose build

logs:
	docker compose logs -f

ps:
	docker compose ps

shell:
	docker compose exec php sh

db:
	docker compose exec db sh -c 'MYSQL_PWD="$$(cat /run/secrets/db_root_password)" mariadb -u root'

composer:
	docker compose run --rm php composer $(cmd)

npm:
	docker compose --profile tools run --rm node npm $(cmd)

artisan:
	docker compose run --rm php php artisan $(cmd)

migrate:
	@export $$(grep -v '^#' .env | grep -E '^(DB_MIGRATOR_USERNAME|DB_MIGRATOR_PASSWORD)=' | xargs) && \
	docker compose run --rm -e DB_MIGRATOR_USERNAME -e DB_MIGRATOR_PASSWORD php php artisan migrate --database=mariadb_migrator

migrate-test:
	@export $$(grep -v '^#' .env | grep -E '^(DB_MIGRATOR_USERNAME|DB_MIGRATOR_PASSWORD)=' | xargs) && \
	docker compose run --rm -e DB_MIGRATOR_USERNAME -e DB_MIGRATOR_PASSWORD -e DB_DATABASE=erp_trading_test php php artisan migrate --database=mariadb_migrator

seed:
	docker compose run --rm php php artisan db:seed --force

test: migrate-test
	@export $$(grep -v '^#' .env | grep -E '^(DB_MIGRATOR_USERNAME|DB_MIGRATOR_PASSWORD)=' | xargs) && \
	docker compose run --rm -e DB_MIGRATOR_USERNAME -e DB_MIGRATOR_PASSWORD php ./vendor/bin/pest

lint:
	docker compose run --rm php ./vendor/bin/pint --test

analyse:
	docker compose run --rm php ./vendor/bin/phpstan analyse

check: lint analyse test

dev:
	docker compose up

verify:
	bash scripts/verify-hardening.sh

test-hooks:
	bash scripts/test-hooks.sh

fresh:
	@echo "WARNING: This will destroy all containers and volumes (database data will be lost)!"
	@read -p "Are you sure you want to proceed? [y/N]: " confirm && [ "$$confirm" = "y" ] || [ "$$confirm" = "Y" ] || (echo "Aborted." && exit 1)
	docker compose down -v
