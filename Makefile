.PHONY: up down build logs ps shell db composer npm artisan migrate migrate-test test-db-reset seed seed-demo seed-demo-tx seed-demo-catalog backup restore-check prod-smoke test test-js lint lint-js analyse check dev verify test-hooks fresh hooks help

help:
	@echo "Available commands:"
	@echo "  make hooks         - Configure local git repository to use .githooks"
	@echo "  make test-hooks    - Run functional tests on Git hooks"
	@echo "  make up            - Start the Docker stack"
	@echo "  make down          - Stop the Docker stack"
	@echo "  make build         - Build/rebuild Docker images"
	@echo "  make logs          - View logs from all services"
	@echo "  make ps            - View status of services"
	@echo "  make shell         - Access shell inside PHP container"
	@echo "  make db            - Open MariaDB client shell"
	@echo "  make composer      - Run Composer inside PHP container (e.g., make composer cmd='--version')"
	@echo "  make npm           - Run NPM inside Node container (e.g., make npm cmd='--version')"
	@echo "  make artisan       - Run Artisan inside PHP container (e.g., make artisan cmd='migrate:status')"
	@echo "  make migrate       - Run DB migrations using mariadb_migrator connection"
	@echo "  make migrate-test  - Run DB migrations on erp_trading_test using mariadb_migrator"
	@echo "  make seed          - Run DB seeders (--force)"
	@echo "  make seed-demo     - Run demo master data seeder (non-production only)"
	@echo "  make seed-demo-tx  - Run demo transactions seeder (non-production only)"
	@echo "  make seed-demo-catalog - Run Indonesian goods catalog seeder (non-production only)"
	@echo "  make backup        - Backup erp_trading database to backups/ directory"
	@echo "  make restore-check - Verify latest backup integrity against temporary database"
	@echo "  make test          - Run PHP test suite (auto migrates test database)"
	@echo "  make test-js       - Run frontend unit and component tests (Vitest)"
	@echo "  make lint          - Run backend code style checks (Pint)"
	@echo "  make lint-js       - Run frontend code style checks (ESLint)"
	@echo "  make analyse       - Run static analysis (PHPStan/Larastan)"
	@echo "  make check         - Run all quality checks (lint, analyse, test, test-js, lint-js)"
	@echo "  make dev           - Start development environment"
	@echo "  make verify        - Run the hardening verification script"
	@echo "  make prod-smoke    - Build production images and smoke test them on a throwaway stack"
	@echo "  make fresh         - Destroy containers and volumes (requires confirmation)"

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
	docker compose run --rm -T php composer $(cmd) < /dev/null

npm:
	docker compose --profile tools run --rm -T node npm $(cmd) < /dev/null

artisan:
	docker compose run --rm -T php php artisan $(cmd) < /dev/null

migrate:
	@export $$(grep -v '^#' .env | grep -E '^(DB_MIGRATOR_USERNAME|DB_MIGRATOR_PASSWORD)=' | xargs) && \
	docker compose run --rm -T -e DB_MIGRATOR_USERNAME -e DB_MIGRATOR_PASSWORD php php artisan migrate --database=mariadb_migrator < /dev/null

migrate-test:
	@export $$(grep -v '^#' .env | grep -E '^(DB_MIGRATOR_USERNAME|DB_MIGRATOR_PASSWORD)=' | xargs) && \
	docker compose run --rm -T -e DB_MIGRATOR_USERNAME -e DB_MIGRATOR_PASSWORD -e DB_DATABASE=erp_trading_test php php artisan migrate --database=mariadb_migrator < /dev/null

test-db-reset:
	@TARGET_DB=$${TEST_DB_NAME:-erp_trading_test}; \
	case "$$TARGET_DB" in \
		*_test) ;; \
		*) echo "ERROR: Refusing to reset database '$$TARGET_DB'; target database name must end in '_test'!" >&2; exit 1 ;; \
	esac; \
	export $$(grep -v '^#' .env | grep -E '^(DB_MIGRATOR_USERNAME|DB_MIGRATOR_PASSWORD)=' | xargs) && \
	docker compose run --rm -T -e DB_MIGRATOR_USERNAME -e DB_MIGRATOR_PASSWORD -e DB_DATABASE="$$TARGET_DB" php php artisan migrate:fresh --database=mariadb_migrator --seed < /dev/null

seed:
	docker compose run --rm -T php php artisan db:seed --force < /dev/null

seed-demo:
	docker compose run --rm -T php php artisan db:seed --class=Database\\Seeders\\DemoMasterDataSeeder --force < /dev/null

seed-demo-tx:
	docker compose run --rm -T php php artisan db:seed --class=Database\\Seeders\\DemoTransactionSeeder --force < /dev/null

seed-demo-catalog:
	docker compose run --rm -T php php artisan db:seed --class=Database\\Seeders\\DemoCatalogSeeder --force < /dev/null

backup:
	bash scripts/db-backup.sh

restore-check:
	bash scripts/db-restore-check.sh

test: test-db-reset
	@export $$(grep -v '^#' .env | grep -E '^(DB_MIGRATOR_USERNAME|DB_MIGRATOR_PASSWORD)=' | xargs) && \
	docker compose run --rm -T -e DB_MIGRATOR_USERNAME -e DB_MIGRATOR_PASSWORD php sh -c './vendor/bin/pest --exclude-filter=ZGuardIntegrityTest && ./vendor/bin/pest tests/Feature/ZGuardIntegrityTest.php' < /dev/null

test-js:
	docker compose --profile tools run --rm -T node npm run test < /dev/null

lint:
	docker compose run --rm -T php ./vendor/bin/pint --test < /dev/null

lint-js:
	docker compose --profile tools run --rm -T node npm run lint < /dev/null

analyse:
	docker compose run --rm -T php ./vendor/bin/phpstan analyse < /dev/null

check: lint analyse test test-js lint-js

dev:
	docker compose up

verify:
	bash scripts/verify-hardening.sh

test-hooks:
	bash scripts/test-hooks.sh

prod-smoke:
	bash scripts/prod-smoke.sh

fresh:
	@echo "WARNING: This will destroy all containers and volumes (database data will be lost)!"
	@read -p "Are you sure you want to proceed? [y/N]: " confirm && [ "$$confirm" = "y" ] || [ "$$confirm" = "Y" ] || (echo "Aborted." && exit 1)
	docker compose down -v
