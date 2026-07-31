# Felio

Felio is a Laravel application scaffold. This bootstrap intentionally contains no portfolio-domain logic.

## Stack

- PHP 8.3+ (the application image uses PHP 8.3)
- Laravel 13.23.0
- Vue 3.5.40, Inertia Vue 3.6.1, Vite 8.2.0
- Inertia Laravel 3.2.1
- Pest 4.7.5 with Pest Laravel plugin 4.1.0
- Nginx 1.27
- PostgreSQL 17
- Node.js 22 (containerized build tooling)

Exact Composer and npm dependency versions are locked in `composer.lock` and `package-lock.json`.

## Bootstrap commands

The initial skeleton was generated in the project Composer container:

```sh
docker compose run --rm composer composer create-project --prefer-dist laravel/laravel /var/www/html '^13.0'
```

The frontend and testing dependencies are installed with:

```sh
docker compose run --rm composer composer require inertiajs/inertia-laravel
docker compose run --rm composer composer require --dev pestphp/pest:^4.7.5 pestphp/pest-plugin-laravel --with-all-dependencies
docker compose run --rm composer composer config platform.php 8.3.0
docker compose run --rm composer composer update --with-all-dependencies
docker compose run --rm node npm install vue @vitejs/plugin-vue @inertiajs/vue3
```

## Run the application

The project uses its own Docker Compose network, `felio_felio_net`. The web service is exposed on host port **8087**. This task selected 8087 after inspecting the active Docker host ports; it was free in the preferred 8081–8099 range.

```sh
make build
make up
curl -i http://localhost:8087/
curl -i http://localhost:8087/health
```

Both `/` and `/health` return HTTP 200. `/health` is a minimal liveness endpoint.

## Commands

```sh
make up       # start app and web services
make down     # stop services
make restart  # restart services
make logs     # follow service logs
make build    # rebuild application image
make ps       # show service status
make test     # run the Pest suite
make frontend # build Vue assets with Vite
make migrate  # apply Laravel migrations to PostgreSQL
```

All dependencies run in containers; PHP, Composer, Node.js, and PostgreSQL are not required on the host. PostgreSQL data, Composer dependencies, and Node modules use the isolated `felio_postgres_data`, `felio_vendor`, and `felio_node_modules` Docker volumes.
