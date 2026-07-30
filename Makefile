.PHONY: up down restart logs build ps test frontend

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
	docker compose run --rm node npm run build
