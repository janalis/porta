# Porta — developer entry points. `make verify` is the definition of done.
#
# Thin over the composer scripts, which stay the source of truth (and what CI
# calls). The parallel gates take half the cores by default; on a box that is
# yours, `make verify PORTA_TEST_PROCESSES=auto`.

PORTA_TEST_PROCESSES  ?=
PORTA_SHARD_PROCESSES ?=
export PORTA_TEST_PROCESSES PORTA_SHARD_PROCESSES

.PHONY: install build test check fix verify docs docs-dev docs-screenshots clean

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
# with live reload. Both draw the screenshots first, from the IDE as it is.
docs: docs-screenshots
	npm ci
	npm run docs:build

docs-dev: docs-screenshots
	npm install
	npm run docs:dev

docs-screenshots:
	composer docs:screenshots

clean:
	rm -rf build/porta.phar .vitepress/dist .vitepress/cache coverage docs/public/screenshots

# Desktop applications, end to end: each drives the built application of one
# system the way the system does (see docs/desktop.md, Packaging). Unit tests of
# the hosts first, then the application itself; CI runs these same targets.
DESKTOP_APP     ?=
DESKTOP_RUNTIME ?=
DESKTOP_PROJECT ?=
DESKTOP_INSTALLER ?=

.PHONY: desktop-test-macos desktop-test-linux desktop-test-windows desktop-e2e-macos desktop-e2e-linux desktop-e2e-windows desktop-e2e-windows-installed

desktop-test-macos:
	cd desktop/macos && swift test

desktop-test-linux:
	test -d build/desktop-linux || meson setup build/desktop-linux desktop/linux -Dapp=false
	meson test -C build/desktop-linux --print-errorlogs

desktop-test-windows:
	dotnet test desktop/windows/Tests --configuration Release

# DESKTOP_APP: a built Porta.app (desktop/macos/build-app.sh).
desktop-e2e-macos:
	@test -n "$(DESKTOP_APP)" || { echo 'DESKTOP_APP=<Porta.app> is needed' >&2; exit 2; }
	desktop/macos/smoke.sh "$(DESKTOP_APP)"

# DESKTOP_APP: the folder holding porta-desktop and runtime/ (the tarball's lib/porta-desktop).
desktop-e2e-linux:
	@test -n "$(DESKTOP_APP)" || { echo 'DESKTOP_APP=<lib/porta-desktop> is needed' >&2; exit 2; }
	dbus-run-session -- xvfb-run -a desktop/linux/smoke.sh "$(DESKTOP_APP)"
	dbus-run-session -- desktop/linux/smoke-session.sh wayland "$(DESKTOP_APP)"
	dbus-run-session -- xvfb-run -a desktop/linux/smoke-session.sh menu "$(DESKTOP_APP)"

# DESKTOP_RUNTIME: the runtime folder Porta runs from inside WSL; DESKTOP_PROJECT: a
# Windows folder for it to open; DESKTOP_INSTALLER: the built installer, installed for real.
desktop-e2e-windows:
	@test -n "$(DESKTOP_RUNTIME)" -a -n "$(DESKTOP_PROJECT)" -a -n "$(DESKTOP_INSTALLER)" || { echo 'DESKTOP_RUNTIME, DESKTOP_PROJECT and DESKTOP_INSTALLER are needed' >&2; exit 2; }
	dotnet run --project desktop/windows/Smoke --configuration Release -- "$(DESKTOP_RUNTIME)" "$(DESKTOP_PROJECT)"
	$(MAKE) desktop-e2e-windows-installed

# The installer alone, as on Windows on Arm, where WSL cannot run.
desktop-e2e-windows-installed:
	@test -n "$(DESKTOP_PROJECT)" -a -n "$(DESKTOP_INSTALLER)" || { echo 'DESKTOP_PROJECT and DESKTOP_INSTALLER are needed' >&2; exit 2; }
	pwsh -NoProfile -File desktop/windows/Smoke/installed.ps1 -Installer "$(DESKTOP_INSTALLER)" -Project "$(DESKTOP_PROJECT)"
