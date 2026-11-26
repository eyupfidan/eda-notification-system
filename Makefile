.DEFAULT_GOAL := help
COMPOSE := docker compose
MOCK_URL := http://localhost:8090

.PHONY: help up up-mock mock-stats mock-reset down build restart logs ps shell migrate fresh test coverage

help: ## List available commands
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2}'

up: ## Build and start all services in the background
	$(COMPOSE) up -d --build

up-mock: ## Start everything with the local mock provider instead of webhook.site
	NOTIFICATION_PROVIDER_URL=http://mock-provider:8000/notify $(COMPOSE) --profile mock up -d --build

mock-stats: ## Show what the mock provider received
	@curl -s $(MOCK_URL)/stats; echo

mock-reset: ## Reset the mock provider counters
	@curl -s -X POST $(MOCK_URL)/reset; echo

down: ## Stop all services
	$(COMPOSE) --profile mock down

build: ## Rebuild the application image
	$(COMPOSE) build

restart: ## Restart app, workers and scheduler (picks up code changes after build)
	$(COMPOSE) up -d --build app worker scheduler

logs: ## Follow logs of all services
	$(COMPOSE) logs -f

ps: ## Show service status
	$(COMPOSE) ps

shell: ## Open a shell in the app container
	$(COMPOSE) exec app sh

migrate: ## Run database migrations
	$(COMPOSE) exec app php artisan migrate

fresh: ## Drop all tables and re-run migrations
	$(COMPOSE) exec app php artisan migrate:fresh

test: ## Run the test suite
	$(COMPOSE) exec app php artisan test

coverage: ## Run the test suite with a code coverage report
	$(COMPOSE) exec app php -d pcov.enabled=1 vendor/bin/phpunit --coverage-text --only-summary-for-coverage-text
