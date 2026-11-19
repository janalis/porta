# Desktop application

The desktop application is Porta in a window of its own: an icon to click, an
entry in the dock or taskbar, and PHP included. It runs the same Porta as the
`porta` command, with the same settings, plugins and keys, and the two can be
used side by side.

## Install

Get the package for your system from the [Download](download.md) page.

Installing registers the application with the system. It does not put `porta`
on your `PATH`: **Settings → Command line** does that, if you want it to.

## Launch

- **Click the icon.** Porta asks which project to open, offering the recent ones.
- **Open a folder with Porta** from the file manager, or drop one on the dock icon.
- **On Linux**, `porta-desktop ~/Sites/shop` from a shell does the same.

Each project gets one window. Opening a project that is already open brings its
window forward, and opening another project opens another window.

A project you open this way is still asked whether it may be trusted, exactly
as it is in a terminal.

## Windows and closing

Minimize, maximize, resize, full screen and restore are the system's own. Size
and text zoom are remembered.

Closing a window with unsaved changes keeps it open and says so. Close it again
within three seconds to discard them. If Porta does not answer, the application
offers to force quit.

## Menus

Where the operating system shows application menus itself, Porta's menus move
there and the window gives up the row it used for them.

| System | Porta's menus |
| --- | --- |
| macOS | In the system menu bar |
| Linux, on a desktop with a global menu | In the desktop's menu bar |
| Linux, on other desktops (GNOME, for one) | In the window |
| Windows | In the window |

## Configuration

The application reads the same `.porta.json` and global settings as the
command. It also takes your login shell's environment when it starts, so it
finds the same tools a terminal does; a project's **Run current file** uses the
`php` on that `PATH`, not the PHP bundled with the application.

### Font

`desktop.json` sets the window's font. It lives in `~/.porta/` on macOS and
Linux, and in `%LOCALAPPDATA%\Porta\` on Windows:

```json
{
    "fontFamily": "JetBrains Mono",
    "fontSize": 14
}
```

Both keys are optional, the size runs from 6 to 72, and a new window reads the
file when it opens. Without it each system's own monospaced font is used.

## Updating

Install the new package over the old one. `porta --self-update` does not replace
the application: it says where to download the new version.

## Limitations

| | macOS | Linux | Windows |
| --- | --- | --- | --- |
| Runs Porta | natively | natively | inside WSL |
| Needs | macOS 13 | GTK 4.10, VTE 0.70 | WSL with a Linux distribution |
| Architectures | arm64, x86-64 | x86-64, arm64 | x86-64, and Arm through Windows' x64 emulation |

- **Windows needs WSL.** Porta depends on POSIX terminals, signals and process
  control that Windows does not offer. A project under `/mnt/c` works, but is
  slower than one inside the Linux file system.
- **Unsigned builds.** A release built without signing certificates shows a
  Gatekeeper or SmartScreen warning the first time. On macOS, right-click the
  application and choose **Open**.
- **Images** depend on the terminal engine of each host. Where an engine has no
  pixel graphics, Porta falls back to drawing them with half-blocks.
- **Linux packages use the system's GTK and VTE.** There is no Flatpak: its
  sandbox would stop the IDE running your tools.
- **Unsaved work survives the application being killed**, a force quit or a
  logout, and comes back the next time the project opens. It cannot survive
  Porta itself being killed outright (`kill -9`) or a power cut.

## Packaging

The hosts live in `desktop/`, one per platform, and `desktop/contract/` is the
protocol every side is tested against.

| Step | Command |
| --- | --- |
| The static PHP | `desktop/build-php.sh <output>` |
| macOS | `desktop/macos/build-app.sh --php <php> --phar <phar> --version <x.y.z> --out <dir>` |
| Linux | `desktop/linux/package.sh --php <php> --phar <phar> --version <x.y.z> --out <dir>` |
| Windows | `dotnet build desktop/windows/App`, then `desktop/windows/Installer/porta.iss` with Inno Setup |

The release workflow signs when these secrets exist, and ad hoc otherwise:
`APPLE_CERTIFICATE`, `APPLE_CERTIFICATE_PASSWORD`, `APPLE_SIGN_IDENTITY`,
`APPLE_ID`, `APPLE_TEAM_ID`, `APPLE_APP_PASSWORD`, `WINDOWS_CERTIFICATE` and
`WINDOWS_CERTIFICATE_PASSWORD`.
