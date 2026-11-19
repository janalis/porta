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
window forward, and opening another project opens another window. A file opened
with Porta or dropped on its window, or a folder inside a
project that is open, goes to that project's window.

Projects you open join the system's recent items: **Window → Open Recent** and
the Dock on macOS, the recent files of the desktop on Linux, and Windows'
recent items.

A project you open this way is still asked whether it may be trusted, exactly
as it is in a terminal.

## Windows and closing

Minimize, maximize, resize, full screen and restore are the system's own. Size
and text zoom are remembered.

A window with unsaved changes shows it: the dot in the close button on macOS,
a `•` before the title elsewhere. Closing it keeps it open and says so. Close it again
within three seconds to discard them. If Porta does not answer, the application
offers to force quit.

When a run in the terminal panel ends while you are in another application or
window, the system tells you: a notification on macOS and Linux (clicking it
brings the window back), the taskbar button flashing on Windows. macOS asks
once whether Porta may notify; if you say no, the Dock icon bounces instead.

If Porta stops on its own, the window says so and offers to reopen it in
place, show the project's `.porta/error.log`, or close.

## Light and dark

Until you choose a theme in **Settings**, the application follows the system:
light when the system is light, dark when it is dark, switching as it does.
On Linux this needs the desktop's settings portal, which GNOME and KDE provide.

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

**Help → Check for Updates…** asks for a newer release now, and Porta also
checks on its own (**Settings → Command line** sets how often). Updating there,
or `porta --self-update`, installs the new version the way the application was
installed:

| Installed from | The update |
| --- | --- |
| The macOS disk image | downloads the new one, checks its signature, and replaces the application |
| The Linux tarball | downloads it and replaces the folder in `~/.local` |
| A `.deb` or `.rpm` | downloads it and installs it with the package manager, which asks for your password |
| The Windows installer | downloads it and runs it quietly: the application closes and opens again |

Every download is checked against the release's checksums before anything is
replaced. Porta restarts on the new version; the window itself is updated the
next time the application starts.

## Limitations

| | macOS | Linux | Windows |
| --- | --- | --- | --- |
| Runs Porta | natively | natively | inside WSL |
| Needs | macOS 13 | GTK 4.10, VTE 0.70 | WSL with a Linux distribution |
| Architectures | arm64, x86-64 | x86-64, arm64 | x86-64, and Arm through Windows' x64 emulation |

On Windows on Arm the application runs under Windows' x64 emulation and runs
Porta inside an arm64 WSL distribution; that last part is not yet covered by
the automated tests.

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
- **Unsaved work survives the application being killed**, a force quit, a
  logout, a crash or a power cut, and comes back the next time the project
  opens. It is copied every 30 seconds, so a crash can lose what was typed in
  the last half minute. Work you discard on purpose does not come back.

## Packaging

The hosts live in `desktop/`, one per platform, and `desktop/contract/` is the
protocol every side is tested against.

| Step | Command |
| --- | --- |
| The static PHP | `desktop/build-php.sh <output>` |
| macOS disk image | `desktop/macos/build-dmg.sh <Porta.app> <output.dmg>` (needs `pip install dmgbuild`) |
| macOS | `desktop/macos/build-app.sh --php <php> --phar <phar> --version <x.y.z> --out <dir>` |
| Linux | `desktop/linux/package.sh --php <php> --phar <phar> --version <x.y.z> --out <dir>` |
| Windows | `dotnet build desktop/windows/App`, then `desktop/windows/Installer/porta.iss` with Inno Setup |

The release workflow signs when these secrets exist, and ad hoc otherwise:
`APPLE_CERTIFICATE`, `APPLE_CERTIFICATE_PASSWORD`, `APPLE_SIGN_IDENTITY`,
`APPLE_ID`, `APPLE_TEAM_ID`, `APPLE_APP_PASSWORD`, `WINDOWS_CERTIFICATE` and
`WINDOWS_CERTIFICATE_PASSWORD`.
