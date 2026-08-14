COMPOSE ?= docker compose
TEST_COMPOSE = $(COMPOSE) -f docker-compose.test.yml
# Externally exposed web port; kept in sync with docker-compose.yml.
HTTP_PORT := 8086

.PHONY: up down restart logs build ps backup backup-check backup-verify-restore test-backup-safety test test-postgresql test-auth test-compose-isolation test-fx test-valuation test-fx-persistence test-market-data-persistence test-portfolio-persistence test-portfolio-valuation test-portfolio-dashboard test-scheduler test-xtb-import test-xtb-manual-import frontend migrate smoke-migrations spike-yfinance spike-nbp-fx test-nbp-fx-spike

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

# Production-safe: streams a custom-format archive to /opt/data/backups, outside the DB volume.
backup:
	./scripts/backup-postgres.sh

# Fails if the live DB is unhealthy or the newest verified archive is missing/stale.
backup-check:
	./scripts/check-postgres-backup.sh

# Restores only into a new, disposable Compose database and removes it afterward.
backup-verify-restore:
	./scripts/verify-postgres-backup-restore.sh

# Regression coverage: rejects unsafe or identity-overridden backup requests before writes.
test-backup-safety:
	./tests/scripts/backup-postgres-safety-test.sh

test: test-postgresql

test-auth: test-compose-isolation
	@set -eu; \
	project="felio-auth-test-$$$$"; \
	cleanup() { \
		test_status="$$?"; cleanup_status=0; \
		if FELIO_COMPOSE_PROJECT="$$project" $(TEST_COMPOSE) down -v --remove-orphans; then :; else cleanup_status="$$?"; fi; \
		if [ "$$cleanup_status" -eq 0 ] && { docker ps -aq --filter "label=com.docker.compose.project=$$project" | grep -q . || docker network inspect "$${project}_felio_test_net" >/dev/null 2>&1 || docker volume inspect "$${project}_postgres_data" >/dev/null 2>&1; }; then \
			echo "Disposable test Compose resources remain for $$project" >&2; cleanup_status=1; \
		fi; \
		trap - EXIT; \
		if [ "$$test_status" -ne 0 ]; then exit "$$test_status"; fi; \
		if [ "$$cleanup_status" -ne 0 ]; then exit "$$cleanup_status"; fi; \
	}; \
	trap cleanup EXIT; \
	FELIO_COMPOSE_PROJECT="$$project" $(TEST_COMPOSE) up -d --wait db; \
	FELIO_COMPOSE_PROJECT="$$project" $(TEST_COMPOSE) build app; \
	FELIO_COMPOSE_PROJECT="$$project" $(TEST_COMPOSE) run --rm --no-deps app php artisan migrate:fresh --force; \
	FELIO_COMPOSE_PROJECT="$$project" $(TEST_COMPOSE) run --rm --no-deps app php vendor/bin/pest --configuration=phpunit.pgsql.xml tests/Feature/Auth

test-compose-isolation:
	@set -eu; \
	$(TEST_COMPOSE) config --format json | python3 -c 'import json, sys; config = json.load(sys.stdin); forbidden = "cloudflare_tunnel"; networks = config.get("networks", {}); services = config.get("services", {}); assert forbidden not in networks, f"test Compose declares forbidden network: {forbidden}"; assert all(forbidden not in (service.get("networks") or {}) for service in services.values()), f"test Compose attaches a service to forbidden network: {forbidden}"; assert networks and all(not network.get("external", False) for network in networks.values()), "test Compose must use only private networks"'

test-postgresql: test-compose-isolation
	@set -eu; \
	project="felio-postgresql-test-$$$$"; \
	cleanup() { \
		test_status="$$?"; cleanup_status=0; \
		if FELIO_COMPOSE_PROJECT="$$project" $(TEST_COMPOSE) down -v --remove-orphans; then :; else cleanup_status="$$?"; fi; \
		if [ "$$cleanup_status" -eq 0 ] && { docker ps -aq --filter "label=com.docker.compose.project=$$project" | grep -q . || docker network inspect "$${project}_felio_test_net" >/dev/null 2>&1 || docker volume inspect "$${project}_postgres_data" >/dev/null 2>&1; }; then \
			echo "Disposable test Compose resources remain for $$project" >&2; cleanup_status=1; \
		fi; \
		trap - EXIT; \
		if [ "$$test_status" -ne 0 ]; then exit "$$test_status"; fi; \
		if [ "$$cleanup_status" -ne 0 ]; then exit "$$cleanup_status"; fi; \
	}; \
	trap cleanup EXIT; \
	FELIO_COMPOSE_PROJECT="$$project" $(TEST_COMPOSE) up -d --wait db; \
	FELIO_COMPOSE_PROJECT="$$project" $(TEST_COMPOSE) build app; \
	FELIO_COMPOSE_PROJECT="$$project" $(TEST_COMPOSE) run --rm --no-deps app php artisan migrate:fresh --force; \
	FELIO_COMPOSE_PROJECT="$$project" $(TEST_COMPOSE) run --rm --no-deps app php vendor/bin/pest --configuration=phpunit.pgsql.xml

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

test-portfolio-valuation:
	$(COMPOSE) up -d db
	$(COMPOSE) run --rm --no-deps app php artisan migrate:fresh --force
	$(COMPOSE) run --rm --no-deps app php vendor/bin/pest tests/Feature/Portfolio/PortfolioValuationReadModelTest.php

test-portfolio-dashboard:
	$(COMPOSE) up -d db
	$(COMPOSE) run --rm --no-deps app php artisan migrate:fresh --force
	$(COMPOSE) run --rm --no-deps app php vendor/bin/pest tests/Feature/Portfolio/PortfolioValuationDashboardTest.php

test-scheduler: test-compose-isolation
	@set -eu; \
	project="felio-scheduler-test-$$$$"; subnet="10.89.3.0/24"; \
	cleanup() { test_status="$$?"; cleanup_status=0; if FELIO_COMPOSE_PROJECT="$$project" FELIO_TEST_SUBNET="$$subnet" $(TEST_COMPOSE) down -v --remove-orphans; then :; else cleanup_status="$$?"; fi; trap - EXIT; if [ "$$test_status" -ne 0 ]; then exit "$$test_status"; fi; exit "$$cleanup_status"; }; \
	trap cleanup EXIT; \
	FELIO_COMPOSE_PROJECT="$$project" FELIO_TEST_SUBNET="$$subnet" $(TEST_COMPOSE) up -d --wait db; \
	FELIO_COMPOSE_PROJECT="$$project" FELIO_TEST_SUBNET="$$subnet" $(TEST_COMPOSE) build app; \
	FELIO_COMPOSE_PROJECT="$$project" FELIO_TEST_SUBNET="$$subnet" $(TEST_COMPOSE) run --rm --no-deps app php artisan migrate:fresh --force; \
	FELIO_COMPOSE_PROJECT="$$project" FELIO_TEST_SUBNET="$$subnet" $(TEST_COMPOSE) run --rm --no-deps app php vendor/bin/pest --configuration=phpunit.pgsql.xml tests/Feature/Scheduling/MarketDataSchedulerTest.php tests/Feature/MarketData/LaravelYfinanceGatewayTest.php

test-xtb-import:
	$(COMPOSE) up -d db
	$(COMPOSE) run --rm --no-deps app php artisan migrate:fresh --force
	$(COMPOSE) run --rm --no-deps app php vendor/bin/pest tests/Feature/Portfolio/XtbXlsxImportAdapterTest.php

test-xtb-manual-import: test-compose-isolation
	@set -eu; \
	project="felio-xtb-manual-import-test-$$$$"; \
	cleanup() { \
		test_status="$$?"; cleanup_status=0; \
		if FELIO_COMPOSE_PROJECT="$$project" $(TEST_COMPOSE) down -v --remove-orphans; then :; else cleanup_status="$$?"; fi; \
		if [ "$$cleanup_status" -eq 0 ] && { docker ps -aq --filter "label=com.docker.compose.project=$$project" | grep -q . || docker network inspect "$${project}_felio_test_net" >/dev/null 2>&1 || docker volume inspect "$${project}_postgres_data" >/dev/null 2>&1; }; then \
			echo "Disposable test Compose resources remain for $$project" >&2; cleanup_status=1; \
		fi; \
		trap - EXIT; \
		if [ "$$test_status" -ne 0 ]; then exit "$$test_status"; fi; \
		if [ "$$cleanup_status" -ne 0 ]; then exit "$$cleanup_status"; fi; \
	}; \
	trap cleanup EXIT; \
	FELIO_COMPOSE_PROJECT="$$project" $(TEST_COMPOSE) up -d --wait db; \
	FELIO_COMPOSE_PROJECT="$$project" $(TEST_COMPOSE) build app; \
	FELIO_COMPOSE_PROJECT="$$project" $(TEST_COMPOSE) run --rm --no-deps app php artisan migrate:fresh --force; \
	FELIO_COMPOSE_PROJECT="$$project" $(TEST_COMPOSE) run --rm --no-deps app php vendor/bin/pest --configuration=phpunit.pgsql.xml tests/Feature/Portfolio/XtbImportDatabaseStateTest.php tests/Feature/Portfolio/XtbXlsxImportAdapterTest.php tests/Feature/Portfolio/XtbSanitizedFixturesTest.php tests/Feature/Portfolio/XtbManualImportWorkflowTest.php

frontend:
	$(COMPOSE) build app

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
	docker cp "$$container_name:/app/results/xtb-sample.json" "$$tmp_results/xtb-sample.json"; \
	docker cp "$$container_name:/app/results/xtb-sample.md" "$$tmp_results/xtb-sample.md"; \
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
