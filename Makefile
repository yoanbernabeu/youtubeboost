# YoutubeBoost — raccourcis de développement.
#
# Tout passe par Docker Compose : `make up` démarre la stack, les autres cibles
# s'exécutent dans le conteneur applicatif.

DC      = docker compose
DC_PROD = $(DC) -f compose.yaml -f compose.prod.yaml
EXEC    = $(DC) exec -T app
CONSOLE = $(EXEC) php bin/console

.DEFAULT_GOAL := help
.PHONY: help start stop restart status update backup trust-cert build up down reload prod-reload logs sh install cc db db-test migration migrate test test-unit test-cov check qa lint lsp cs cs-fix stan assets worker-logs prod-build prod-up

help: ## Affiche cette aide
	@awk 'BEGIN{FS=":.*?## "} \
		/^## ---/ { sub(/^## -+ /,""); sub(/ -+$$/,""); printf "\n\033[1m%s\033[0m\n", $$0; next } \
		/^[a-zA-Z_-]+:.*?## / { printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2 }' $(MAKEFILE_LIST)
	@echo ""

## --- Faire tourner l'instance ---

start: ## Démarre YoutubeBoost (construit l'image si besoin)
	@test -f .env.local || { \
		echo ""; \
		echo "  Il manque .env.local. Créez-le puis recommencez :"; \
		echo "      cp .env.example .env.local"; \
		echo ""; \
		exit 1; \
	}
	$(DC_PROD) up -d --build --wait
	@echo ""
	@echo "  YoutubeBoost écoute sur https://$$(grep -E '^SERVER_NAME=' .env.local | cut -d= -f2)"
	@echo "  Certificat refusé par le navigateur ? Lancez : make trust-cert"
	@echo ""

stop: ## Arrête l'instance
	$(DC_PROD) stop

restart: ## Redémarre en relisant .env.local (un simple restart ne le relit pas)
	$(DC_PROD) stop
	$(DC_PROD) up -d --force-recreate --wait

status: ## Affiche l'état des conteneurs
	$(DC) ps

update: ## Récupère la dernière version et redémarre
	git pull
	$(DC_PROD) up -d --build --wait

backup: ## Sauvegarde la base et les images dans ./backups
	@mkdir -p backups
	@stamp=$$(date +%Y%m%d-%H%M%S); \
	$(DC) exec -T database pg_dump -U app app | gzip > backups/database-$$stamp.sql.gz && \
	$(DC) exec -T app tar czf - -C /app/var/share --exclude='./test' --exclude='./*/pools' . > backups/images-$$stamp.tar.gz
	@echo ""
	@echo "  Sauvegardes écrites dans ./backups — gardez les deux fichiers ensemble."
	@echo "  Sans les images, un retour arrière devient impossible."
	@echo ""

trust-cert: ## Fait accepter par le système le certificat local de Caddy
	@# Sur localhost, aucune autorité publique ne peut signer : Caddy fabrique la
	@# sienne dans le conteneur. Cette cible en sort la partie publique et
	@# l'installe dans le magasin de confiance du système.
	$(DC) cp app:/data/caddy/pki/authorities/local/root.crt /tmp/youtubeboost-root.crt
	@case "$$(uname -s)" in \
		Darwin) \
			sudo security add-trusted-cert -d -r trustRoot \
				-k /Library/Keychains/System.keychain /tmp/youtubeboost-root.crt ;; \
		Linux) \
			sudo cp /tmp/youtubeboost-root.crt /usr/local/share/ca-certificates/youtubeboost-root.crt && \
			sudo update-ca-certificates ;; \
		*) \
			echo "Système non reconnu. Importez /tmp/youtubeboost-root.crt à la main."; \
			exit 1 ;; \
	esac
	@echo ""
	@echo "  Certificat approuvé. Redémarrez le navigateur pour qu'il le relise."
	@echo ""

## --- Developpement ---

build: ## Construit les images
	$(DC) build --pull

up: ## Démarre la stack de développement
	$(DC) up -d --wait

down: ## Arrête la stack
	$(DC) down --remove-orphans

reload: ## Recharge .env.local (un simple restart ne relit pas le fichier)
	$(DC) stop
	$(DC) up -d --force-recreate --wait

logs: ## Suit les logs de l'application
	$(DC) logs -f app

worker-logs: ## Suit les logs du worker Messenger
	$(DC) logs -f worker

sh: ## Ouvre un shell dans le conteneur applicatif
	$(DC) exec app bash

install: ## Installe les dépendances
	$(EXEC) composer install

cc: ## Vide et réchauffe le cache
	$(CONSOLE) cache:clear

db: ## Applique les migrations
	$(CONSOLE) doctrine:migrations:migrate --no-interaction

migration: ## Génère une migration à partir des entités
	$(CONSOLE) doctrine:migrations:diff --no-interaction

db-test: ## (Re)crée le schéma de la base de test
	$(CONSOLE) --env=test doctrine:database:create --if-not-exists
	@# Cette cible efface tout le schéma. `dbname_suffix` dans doctrine.yaml
	@# envoie l'environnement de test sur `app_test`, mais une variable
	@# DATABASE_URL posée dans l'environnement bat les fichiers .env : on vérifie
	@# donc où l'on est vraiment avant de supprimer quoi que ce soit.
	@$(CONSOLE) --env=test dbal:run-sql "SELECT current_database()" \
		| grep -qE '[[:space:]][A-Za-z0-9_]+_test[[:space:]]' || { \
			echo ""; \
			echo "  REFUS : la connexion de test ne pointe pas sur une base dont le nom finit par _test."; \
			echo "  Rien n'a été supprimé. Vérifiez DATABASE_URL et dbname_suffix avant de réessayer."; \
			echo ""; \
			exit 1; \
		}
	$(CONSOLE) --env=test doctrine:schema:drop --force --full-database
	$(CONSOLE) --env=test doctrine:schema:create

test: db-test ## Lance toute la suite de tests
	$(EXEC) vendor/bin/phpunit

test-unit: ## Lance les tests unitaires (sans base de données)
	$(EXEC) vendor/bin/phpunit --testsuite=unit

test-cov: db-test ## Lance les tests avec couverture
	$(EXEC) env XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-html var/coverage

cs: ## Vérifie le style de code
	$(EXEC) vendor/bin/php-cs-fixer check --diff

cs-fix: ## Corrige le style de code
	$(EXEC) vendor/bin/php-cs-fixer fix

stan: ## Analyse statique
	$(CONSOLE) cache:warmup --env=dev
	$(EXEC) vendor/bin/phpstan analyse --memory-limit=1G

lint: ## Valide gabarits, configuration et conteneur de services
	$(CONSOLE) lint:twig templates/
	$(CONSOLE) lint:yaml config/ translations/
	$(CONSOLE) lint:container

lsp: ## Symfony Language Tools : routes, services, gabarits, clés de traduction
	@command -v symfony >/dev/null 2>&1 || { \
		echo "Symfony CLI absent. Installation : https://symfony.com/download"; \
		exit 1; \
	}
	symfony lsp:check

qa: cs stan ## Style + analyse statique

check: cs stan lint lsp test ## Tout vérifier — à lancer avant de dire qu'un travail est fini
	@echo ""
	@echo "  Style, types, gabarits, Language Tools et suite de tests : tout est vert."

assets: ## Recompile Tailwind et la carte des assets
	$(CONSOLE) tailwind:build --minify
	$(CONSOLE) asset-map:compile

prod-build: ## Construit les images de production
	$(DC_PROD) build --pull

prod-up: ## Démarre la stack de production
	$(DC_PROD) up -d --wait

prod-reload: ## Recharge .env.local en production (alias de `restart`)
	$(MAKE) restart
