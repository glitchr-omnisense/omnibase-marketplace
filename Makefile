.PHONY: assets deploy linter phpcs phpstan tests clean

ROOT_DIR := $(abspath ../../../)

APP_ENV_BAK   := $(APP_ENV)
APP_DEBUG_BAK := $(APP_DEBUG)
ifneq (,$(wildcard $(ROOT_DIR)/.env))
	include $(ROOT_DIR)/.env
endif
ifneq (,$(wildcard $(ROOT_DIR)/.env.$(APP_ENV)))
	include $(ROOT_DIR)/.env.$(APP_ENV)
endif
ifneq ($(strip $(APP_ENV_BAK)),)
	APP_ENV := $(APP_ENV_BAK)
endif
ifneq ($(strip $(APP_DEBUG_BAK)),)
	APP_DEBUG := $(APP_DEBUG_BAK)
endif
export APP_ENV APP_DEBUG

assets:
ifeq ($(APP_DEBUG),1)
	@cd assets && yarn install
	@cd assets && yarn run watch
else
	@cd assets && yarn install
	@cd assets && yarn run prod
endif

deploy:
	@composer update
	@yarn install

linter: phpstan phpcs

phpcs:
	../../../bin/php-cs-fixer fix src
phpstan:
	../../vendor/bin/phpstan analyse

tests:
	@if [ -x vendor/bin/phpunit ]; then vendor/bin/phpunit; else $(ROOT_DIR)/bin/phpunit -c phpunit.xml.dist; fi

clean:
	@$(RM) -rf composer.lock vendor assets/build assets/package-lock.json assets/yarn.lock
