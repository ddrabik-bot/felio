# Felio

Felio is a Laravel application scaffold. This bootstrap intentionally contains no portfolio-domain logic.

## Stack

- PHP 8.3+ (the application image uses PHP 8.3 FPM)
- Laravel 13.23.0
- Vue 3, Inertia.js, and Vite (installed during this bootstrap)
- Pest (installed during this bootstrap)
- Nginx 1.27
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
docker compose run --rm composer composer require --dev pestphp/pest
# install Pest's Laravel integration
docker compose run --rm composer php artisan pest:install
docker compose run --rm node npm install
```

## Run the application

The project uses its own Docker Compose network, `felio_felio_net`. The web service is exposed on host port **8086**.

```sh
make build
make up
curl -i http://localhost:8086/
curl -i http://localhost:8086/health
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
```

All dependencies run in containers; PHP and Composer are not required on the host.
