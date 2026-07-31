.PHONY: up down restart logs build ps test frontend migrate

HTTP_PORT := 8087

up:
	docker compose up -d

down:
	docker compose down

restart:
	docker compose restart

logs:
	docker compose logs -f

build:
	docker compose build

ps:
	docker compose ps

test:
	docker compose run --rm app php artisan test

frontend:
	docker compose run --rm node npm ci
	docker compose run --rm node npm run build

migrate:
	docker compose run --rm app php artisan migrate
