# Porta — developer entry points. `make verify` is the definition of done.
#
# Thin over the composer scripts, which stay the source of truth (and what CI
# calls). The parallel gates take half the cores by default; on a box that is
# yours, `make verify PORTA_TEST_PROCESSES=auto`.

PORTA_TEST_PROCESSES  ?=
PORTA_SHARD_PROCESSES ?=
export PORTA_TEST_PROCESSES PORTA_SHARD_PROCESSES

.PHONY: install build test check fix verify docs docs-dev clean

install:
	composer install
	npm ci

# build/porta.phar, stamped with `git describe --tags`.
build:
	composer build:phar

test:
	composer test

# Every quick gate (format, lint, analyze, rector, arch, duplication, custos),
# not stopping at the first red.
check:
	composer check

# Rector, then the formatter.
fix:
	composer fix

# The quick gates, then 100% line coverage over src/ and every plugin.
verify:
	composer verify

# Documentation site (VitePress, Node 22+): build to .vitepress/dist, or serve
# with live reload.
docs:
	npm ci
	npm run docs:build

docs-dev:
	npm install
	npm run docs:dev

clean:
	rm -rf build/porta.phar .vitepress/dist .vitepress/cache coverage
