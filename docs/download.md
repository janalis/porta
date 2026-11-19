# Download

[**Download Porta**](https://github.com/janalis/porta/releases/latest#download)

The link picks the desktop application for your system. Porta is free and open
source, under the MIT license.

## Desktop application

Porta in a window of its own, with PHP included. See
[Desktop application](desktop.md) for what it does on each system.

| System | Package | Install |
| --- | --- | --- |
| macOS, Apple silicon | [porta-desktop-macos-arm64.dmg](https://github.com/janalis/porta/releases/latest/download/porta-desktop-macos-arm64.dmg) | Open it and drag Porta to Applications |
| macOS, Intel | [porta-desktop-macos-x86_64.dmg](https://github.com/janalis/porta/releases/latest/download/porta-desktop-macos-x86_64.dmg) | Open it and drag Porta to Applications |
| Windows 10 2004+ or 11, x64 or Arm | [porta-desktop-windows.exe](https://github.com/janalis/porta/releases/latest/download/porta-desktop-windows.exe) | Run it; no administrator rights are needed |
| Debian, Ubuntu (x86-64) | [porta-desktop-linux-x86_64.deb](https://github.com/janalis/porta/releases/latest/download/porta-desktop-linux-x86_64.deb) | `sudo apt install ./porta-desktop-linux-x86_64.deb` |
| Debian, Ubuntu (arm64) | [porta-desktop-linux-aarch64.deb](https://github.com/janalis/porta/releases/latest/download/porta-desktop-linux-aarch64.deb) | `sudo apt install ./porta-desktop-linux-aarch64.deb` |
| Fedora, RHEL (x86-64) | [porta-desktop-linux-x86_64.rpm](https://github.com/janalis/porta/releases/latest/download/porta-desktop-linux-x86_64.rpm) | `sudo dnf install ./porta-desktop-linux-x86_64.rpm` |
| Fedora, RHEL (arm64) | [porta-desktop-linux-aarch64.rpm](https://github.com/janalis/porta/releases/latest/download/porta-desktop-linux-aarch64.rpm) | `sudo dnf install ./porta-desktop-linux-aarch64.rpm` |
| Other Linux (x86-64) | [porta-desktop-linux-x86_64.tar.gz](https://github.com/janalis/porta/releases/latest/download/porta-desktop-linux-x86_64.tar.gz) | Unpack it and run `./install.sh`, which writes only to `~/.local` |
| Other Linux (arm64) | [porta-desktop-linux-aarch64.tar.gz](https://github.com/janalis/porta/releases/latest/download/porta-desktop-linux-aarch64.tar.gz) | Unpack it and run `./install.sh`, which writes only to `~/.local` |


`porta-desktop.sha256`, beside the packages, holds each one's checksum. Older
versions are on the [releases page](https://github.com/janalis/porta/releases).

## The `porta` command

Porta in your terminal needs PHP 8.5 or later.

| Way | Command |
| --- | --- |
| Homebrew (macOS, Linux) | `brew install janalis/tap/porta` |
| The phar | [porta.phar](https://github.com/janalis/porta/releases/latest/download/porta.phar), then `install -m 755 porta.phar ~/.local/bin/porta` |
| Composer | `composer global require janalis/porta` |

[Getting started](getting-started.md) covers each of them, and updating.
