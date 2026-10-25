.PHONY: up down build logs ps shell db composer npm verify fresh help

help:
	@echo "Available commands:"
	@echo "  make up        - Start the Docker stack"
	@echo "  make down      - Stop the Docker stack"
	@echo "  make build     - Build/rebuild Docker images"
	@echo "  make logs      - View logs from all services"
	@echo "  make ps        - View status of services"
	@echo "  make shell     - Access shell inside PHP container"
	@echo "  make db        - Open MariaDB client shell"
	@echo "  make composer  - Run Composer inside PHP container (e.g., make composer cmd='--version')"
	@echo "  make npm       - Run NPM inside Node container (e.g., make npm cmd='--version')"
	@echo "  make verify    - Run the hardening verification script"
	@echo "  make fresh     - Destroy containers and volumes (requires confirmation)"

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

verify:
	bash scripts/verify-hardening.sh

fresh:
	@echo "WARNING: This will destroy all containers and volumes (database data will be lost)!"
	@read -p "Are you sure you want to proceed? [y/N]: " confirm && [ "$$confirm" = "y" ] || [ "$$confirm" = "Y" ] || (echo "Aborted." && exit 1)
	docker compose down -v
