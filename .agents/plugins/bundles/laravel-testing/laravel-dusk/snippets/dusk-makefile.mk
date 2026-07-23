# Source: anonymized production Laravel project

# Dusk-in-Docker: ChromeDriver в app-образ не устанавливается.
# Dusk внутри контейнера подключается к ChromeDriver на ХОСТЕ
# через host.docker.internal:9515.
#
# DUSK_ENV_MODE:
#   test (default) — изолированная БД app_test, migrate:fresh, guard-проверки.
#   current        — текущее окружение/URL без сброса данных (смоук).
#
# Переопределение: make test-browser DUSK_DRIVER_PORT=9516 DUSK_HEADLESS=false

DOCKER_COMPOSE_DEV ?= docker compose
DUSK_DRIVER_HOST ?= host.docker.internal
DUSK_DRIVER_PORT ?= 9515
CHROMEDRIVER_BIN ?= ./vendor/laravel/dusk/bin/chromedriver-linux
DUSK_HEADLESS ?= true
DUSK_ENV_MODE ?= test

.PHONY: dusk-driver-up test-browser

# Поднять host ChromeDriver, если ещё не запущен.
# --allowed-ips= и --allowed-origins='*' обязательны,
# иначе ChromeDriver отбросит соединения из контейнера.
dusk-driver-up:
	@if ss -ltn | awk '$$4 ~ /:$(DUSK_DRIVER_PORT)$$/ { found=1 } END { exit(found ? 0 : 1) }'; then \
		echo "ChromeDriver уже запущен на $(DUSK_DRIVER_PORT)"; \
	else \
		echo "Запускаю ChromeDriver на хосте ($(DUSK_DRIVER_PORT))..."; \
		nohup "$(CHROMEDRIVER_BIN)" --port=$(DUSK_DRIVER_PORT) --allowed-ips= --allowed-origins='*' >/tmp/app-chromedriver.log 2>&1 & \
		sleep 1; \
	fi

# Запуск Dusk-тестов в контейнере app.
# DUSK_START_CHROMEDRIVER=false — драйвер уже работает на хосте.
# В режиме test переключаем DB_HOST на сервис БД compose-сети.
# Пример: make test-browser FILE=tests/Browser/DocumentWorkflowTest.php
test-browser:
	@$(MAKE) dusk-driver-up
	$(DOCKER_COMPOSE_DEV) exec app sh -lc '\
		if [ "$(DUSK_ENV_MODE)" = "test" ]; then export DB_HOST=pgsql DB_PORT=5432; fi; \
		DUSK_ENV_MODE=$(DUSK_ENV_MODE) \
		DUSK_HEADLESS=$(DUSK_HEADLESS) \
		DUSK_START_CHROMEDRIVER=false \
		DUSK_DRIVER_URL=http://$(DUSK_DRIVER_HOST):$(DUSK_DRIVER_PORT) \
		php artisan dusk $(FILE)'
