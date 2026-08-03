COMPOSE ?= docker compose
# Externally exposed web port; kept in sync with docker-compose.yml.
HTTP_PORT := 8086

.PHONY: up down restart logs build ps test test-fx test-valuation test-fx-persistence test-market-data-persistence test-portfolio-persistence frontend migrate smoke-migrations spike-yfinance spike-nbp-fx test-nbp-fx-spike

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

test-fx:
	$(COMPOSE) run --rm app php vendor/bin/pest tests/Unit/Fx

test-valuation:
	$(COMPOSE) run --rm app php vendor/bin/pest tests/Unit/Valuation

test-fx-persistence:
	$(COMPOSE) run --rm app php vendor/bin/pest tests/Feature/Fx/FxRatePersistenceTest.php

test-market-data-persistence:
	$(COMPOSE) run --rm app php vendor/bin/pest tests/Feature/MarketData/MarketDataPersistenceTest.php

test-portfolio-persistence:
	$(COMPOSE) up -d db
	$(COMPOSE) run --rm --no-deps app php artisan migrate:fresh --force
	$(COMPOSE) run --rm --no-deps app php vendor/bin/pest tests/Feature/Portfolio/PortfolioPositionPersistenceTest.php

frontend:
	$(COMPOSE) run --rm node npm ci
	$(COMPOSE) run --rm node npm run build

migrate:
	$(COMPOSE) run --rm app php artisan migrate

smoke-migrations:
	$(COMPOSE) up -d db
	$(COMPOSE) run --rm app php artisan migrate:fresh --force
	$(COMPOSE) run --rm app php artisan migrate:status

spike-yfinance:
	@set -eu; \
	tmp_results="$$(mktemp -d)"; \
	container_name="felio-yfinance-spike-probe-$$$$"; \
	cleanup() { \
		original_status="$$?"; cleanup_status=0; \
		docker rm -f "$$container_name" >/dev/null || cleanup_status="$$?"; \
		rm -rf "$$tmp_results" || cleanup_status="$$?"; \
		trap - EXIT; \
		if [ "$$original_status" -ne 0 ]; then exit "$$original_status"; fi; \
		exit "$$cleanup_status"; \
	}; \
	trap cleanup EXIT; \
	$(COMPOSE) run --no-deps --name "$$container_name" yfinance-spike; \
	docker cp "$$container_name:/workspace/spikes/yfinance-provider/results/xtb-sample.json" "$$tmp_results/xtb-sample.json"; \
	docker cp "$$container_name:/workspace/spikes/yfinance-provider/results/xtb-sample.md" "$$tmp_results/xtb-sample.md"; \
	test -s "$$tmp_results/xtb-sample.json"; \
	test -s "$$tmp_results/xtb-sample.md"; \
	mv "$$tmp_results/xtb-sample.json" spikes/yfinance-provider/results/xtb-sample.json; \
	mv "$$tmp_results/xtb-sample.md" spikes/yfinance-provider/results/xtb-sample.md

spike-nbp-fx:
	@set -eu; \
	tmp_results="$$(mktemp -d)"; \
	container_name="felio-nbp-fx-spike-probe-$$$$"; \
	cleanup() { \
		original_status="$$?"; cleanup_status=0; \
		docker rm -f "$$container_name" >/dev/null || cleanup_status="$$?"; \
		rm -rf "$$tmp_results" || cleanup_status="$$?"; \
		trap - EXIT; \
		if [ "$$original_status" -ne 0 ]; then exit "$$original_status"; fi; \
		exit "$$cleanup_status"; \
	}; \
	trap cleanup EXIT; \
	$(COMPOSE) run --no-deps --name "$$container_name" nbp-fx-spike; \
	docker cp "$$container_name:/workspace/spikes/nbp-fx-provider/results/nbp-fx.json" "$$tmp_results/nbp-fx.json"; \
	docker cp "$$container_name:/workspace/spikes/nbp-fx-provider/results/nbp-fx.md" "$$tmp_results/nbp-fx.md"; \
	test -s "$$tmp_results/nbp-fx.json"; \
	test -s "$$tmp_results/nbp-fx.md"; \
	mv "$$tmp_results/nbp-fx.json" spikes/nbp-fx-provider/results/nbp-fx.json; \
	mv "$$tmp_results/nbp-fx.md" spikes/nbp-fx-provider/results/nbp-fx.md

test-nbp-fx-spike:
	$(COMPOSE) run --rm --no-deps --entrypoint python nbp-fx-spike test_probe.py
