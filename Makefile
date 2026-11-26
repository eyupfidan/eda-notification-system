.DEFAULT_GOAL := help
COMPOSE := docker compose

.PHONY: help up down build restart logs ps shell migrate fresh test

help: ## List available commands
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2}'

up: ## Build and start all services in the background
	$(COMPOSE) up -d --build

down: ## Stop all services
	$(COMPOSE) down

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
