#!/bin/sh
set -e

if [ "$1" = 'frankenphp' ] || [ "$1" = 'php' ] || [ "$1" = 'bin/console' ]; then
	if [ -z "$(ls -A 'vendor/' 2>/dev/null)" ]; then
		composer install --prefer-dist --no-progress --no-interaction
	fi

	php bin/console -V

	# Fail early and in plain words rather than deep inside a page render.
	if [ "${APP_ENV:-prod}" = "prod" ]; then
		missing=''
		[ -z "${APP_SECRET:-}" ] && missing="$missing APP_SECRET"
		[ -z "${APP_PASSWORD:-}" ] && missing="$missing APP_PASSWORD"
		[ -z "${DATABASE_URL:-}" ] && missing="$missing DATABASE_URL"
		if [ -n "$missing" ]; then
			echo "Configuration incomplète. Variables manquantes :$missing"
			echo 'Renseignez-les dans .env.local, puis relancez la stack.'
			exit 1
		fi
	fi

	echo 'Waiting for database to be ready...'
	ATTEMPTS_LEFT_TO_REACH_DATABASE=60
	until [ $ATTEMPTS_LEFT_TO_REACH_DATABASE -eq 0 ] || DATABASE_ERROR=$(php bin/console dbal:run-sql -q "SELECT 1" 2>&1); do
		if [ $? -eq 255 ]; then
			ATTEMPTS_LEFT_TO_REACH_DATABASE=0
			break
		fi
		sleep 1
		ATTEMPTS_LEFT_TO_REACH_DATABASE=$((ATTEMPTS_LEFT_TO_REACH_DATABASE - 1))
		echo "Still waiting for database to be ready... $ATTEMPTS_LEFT_TO_REACH_DATABASE attempts left."
	done

	if [ $ATTEMPTS_LEFT_TO_REACH_DATABASE -eq 0 ]; then
		echo 'The database is not up or not reachable:'
		echo "$DATABASE_ERROR"
		exit 1
	fi
	echo 'The database is now ready and reachable'

	# Only the web container touches the schema: several workers racing on
	# migrations would serialise on an advisory lock and fail confusingly.
	if [ "${RUN_MIGRATIONS:-1}" = "1" ]; then
		if find ./migrations -iname '*.php' -print -quit | grep --quiet .; then
			php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing
		fi
		php bin/console messenger:setup-transports
	fi

	# Named volumes are mounted here and must exist before anything writes to them.
	mkdir -p var/cache var/log var/share

	if [ ! -d "var/cache/${APP_ENV:-prod}" ]; then
		php bin/console cache:warmup
	fi

	echo 'YoutubeBoost is ready.'
fi

exec docker-php-entrypoint "$@"
