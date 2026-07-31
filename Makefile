COMPOSE ?= docker compose
# Externally exposed web port; kept in sync with docker-compose.yml.
HTTP_PORT := 8086

.PHONY: up down restart logs build ps test frontend migrate smoke-migrations

up:
	$(COMPOSE) up -d

down:
	$(COMPOSE) down

restart:
	$(COMPOSE) restart

logs:
	$(COMPOSE) logs -f

build:
	$(COMPOSE) build

ps:
	$(COMPOSE) ps

test:
	$(COMPOSE) run --rm app php artisan test

frontend:
	$(COMPOSE) run --rm node npm ci
	$(COMPOSE) run --rm node npm run build

migrate:
	$(COMPOSE) run --rm app php artisan migrate

smoke-migrations:
	$(COMPOSE) up -d db
	$(COMPOSE) run --rm app php artisan migrate:fresh --force
	$(COMPOSE) run --rm app php artisan migrate:status
