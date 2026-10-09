# Load bench of AzGuard. Docker is needed only for the server drivers and redis (the repository docker-compose.yml).
COMPOSE := docker compose -f $(CURDIR)/../docker-compose.yml
TIER ?= mini
DRIVER ?= sqlite
CACHE ?= none
PROFILE ?= all
REPS ?=
OUT ?=
PHP := php -d memory_limit=2G -d opcache.enable_cli=1 -d opcache.jit_buffer_size=0
RUN := $(PHP) $(CURDIR)/bin/run.php --tier=$(TIER) --driver=$(DRIVER) --cache=$(CACHE) --profile=$(PROFILE) $(if $(REPS),--reps=$(REPS)) $(if $(OUT),--out=$(OUT))

.PHONY: help up down run matrix smoke compare
help: ## list targets
	@grep -E '^[a-z-]+:.*##' $(MAKEFILE_LIST) | sed 's/:.*##/ —/'
up: ## start postgres, mysql and redis of the test suite (waits until healthy)
	$(COMPOSE) up -d --wait postgres mysql redis
down: ## stop them
	$(COMPOSE) stop postgres mysql redis
run: ## one run: TIER=mini DRIVER=sqlite CACHE=none PROFILE=all [REPS=] [OUT=]
	$(RUN)
smoke: ## seconds-long harness check (the test suite runs it too)
	$(PHP) $(CURDIR)/bin/run.php --tier=smoke --out=$(CURDIR)/.runs/smoke
matrix: ## every driver and cache of the published results for TIER (needs `make up`)
	$(MAKE) run DRIVER=sqlite CACHE=none
	$(MAKE) run DRIVER=sqlite CACHE=array
	$(MAKE) run DRIVER=pgsql CACHE=none
	$(MAKE) run DRIVER=pgsql CACHE=redis
	$(MAKE) run DRIVER=mysql CACHE=none
compare: ## BASE=a.json HEAD=b.json: regressions of p50 (ratio >= 1.20 and >= 200 µs)
	$(PHP) $(CURDIR)/bin/compare.php $(BASE) $(HEAD)
