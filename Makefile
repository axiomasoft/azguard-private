.PHONY: up down logs ps

up:
	docker compose up -d
	docker compose ps

down:
	docker compose down

logs:
	docker compose logs -f

ps:
	docker compose ps
