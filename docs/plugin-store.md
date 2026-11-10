# The plugin store

**Settings › Plugins** is the store: it lists every Porta plugin published on
[Packagist](https://packagist.org/search/?type=porta-plugin). Each plugin has
one button:

| Button | Meaning |
| --- | --- |
| **Get** | It is not installed. Pressing it installs it into the scope at the top of the Settings view (this project, or every project), and switches it on. |
| **Update** | A newer release that runs on this Porta is out. |
| **Open** | It is installed and up to date. Pressing it opens its Settings page. |
| **Built-in** | It ships with Porta. Pressing it opens its Settings page. |
| **Enable** | It is installed or built in, but switched off. |
| **Incompatible** | No release of it runs on this Porta: it targets another plugin API, or a PHP version or extension this machine lacks. |

## Using the store

The store has four tabs:

- **Discover** is the front page: the featured plugins in a banner, then a
  shelf per category. A shelf with more than it shows has **See all ›**,
  which opens the whole category.
- **Categories** lists every category with how many plugins it has; Enter
  opens one.
- **Updates** lists the installed plugins that have a newer release, under an
  **Update all** button.
- **Installed** lists every plugin installed here, from the store or not, with
  **Open** (its Settings page) or **Update**. Above them, **Save changes to**
  picks where a new plugin installs (this project, or every project), and
  **Install from URL or path** installs a package by name, a git URL or a local
  directory: press Enter to type into it, and Enter again to install (see
  [Installing, removing, disabling](plugins.md#installing-removing-disabling)).

A plugin's own page under **Settings → Plugins** has an **In the store** row
when the store lists it. **Open** there shows its page in the store.

Each plugin is a tile: its icon, its title, what it does, who made it, its
category, its version, when that version was released and how often it was
downloaded, with its button at the right. **Verified** marks a plugin whose
author registered it with Porta (see below). Tiles stand two to a row where
the pane is wide enough. In the banner, a row of featured plugins shows each
one's first screenshot over its tile, when every plugin in the row has one.

The icons and screenshots are pictures where the terminal can draw them
(kitty, Ghostty), and coloured half-blocks elsewhere. A
plugin with no icon shows its initials in a box. The store never draws an icon
for a plugin: it uses only what the plugin publishes (see
[Pictures](#pictures)).

| Key | Does |
| --- | --- |
| ↑ ↓ ← →, PgUp PgDn | Move through the tiles; ↑ ↓ stop on **See all** only when it stands over or under the selected tile |
| Enter | Open the plugin's page, or the category, or the field |
| Space | Press the plugin's button |
| `]` / `[` | Next / previous tab |
| `u` | Show Updates |
| `Shift+U` | Update every plugin listed under Updates |
| `/` or Ctrl+F | Search by name, description or author |
| Esc or Backspace | Leave a category |

A click on a tab, a tile, **See all** or a button does the same: a click selects
a tile, and a second click opens its page.

A plugin's **page** shows, in order:

- its icon and its button, then a strip with its downloads, category,
  developer, version and license;
- its screenshots side by side, all the same height (← → scrolls the strip);
- **What's New**: the newest release's section of its changelog;
- **About**: its README;
- **Information**: provider, package, category, version, license, downloads and source.

On a page, ↑ ↓ and PgUp PgDn scroll it. **Tab** and **Shift+Tab** move through
the page's links and its button, and Enter or Space follows the focused link
or presses the button. A click on a link follows it too. The links are those
of What's New and About, plus the package (its Packagist page) and the source.
A link to another file of the README opens that file's page on GitHub. Links
open in your browser. Esc or Backspace goes back to the shelf.

A plugin that is not verified asks before it installs: the first **Get** only
warns you, and a second one installs it. A plugin is PHP that runs inside
Porta with your permissions, so install one only from an author you trust.

The store keeps what it last fetched under `~/.porta/cache/store`. It opens
at once, even offline, and asks Packagist again when that copy is more than an
hour old.

### Updates

Porta checks for newer releases in the background, weekly by default
(`pluginUpdateCheck`: `daily`, `weekly` or `off`). It says once, in a toast,
what it found. With `pluginAutoUpdate` on, it installs the updates instead.
Both settings are on the **General** page.

An update only reaches a plugin you installed from Packagist. A plugin
installed from a local path or a git URL follows its own source. A built-in
plugin updates with Porta.

A plugin that is running reloads live with its new version: its menus,
commands and keys are swapped at once. A class it had already loaded cannot be
unloaded from PHP, so if it had any, the update says to restart Porta to
finish.

## Publishing a plugin

1. Write the plugin ([Writing a plugin](plugins.md#writing-a-plugin)). Give its
   `composer.json` the name `vendor/porta-<name>` and `"type": "porta-plugin"`.
   It installs as `<name>`.
2. Describe it for the store under `extra.porta.store`:

   ```json
   {
       "name": "acme/porta-rector",
       "description": "Rector refactorings on the current file",
       "type": "porta-plugin",
       "extra": {
           "porta": {
               "api": 1,
               "store": {
                   "title": "Rector",
                   "category": "linters",
                   "icon": "https://raw.githubusercontent.com/acme/porta-rector/main/docs/icon.png",
                   "screenshots": [
                       "https://raw.githubusercontent.com/acme/porta-rector/main/docs/screenshot.png"
                   ],
                   "changelog": "CHANGELOG.md",
                   "readme": "README.md"
               }
           }
       }
   }
   ```

   | Key | Default | What it is |
   | --- | --- | --- |
   | `title` | the name, capitalised | What the store calls it |
   | `category` | `other` | One of `languages`, `linters`, `testing`, `debugging`, `version-control`, `remote`, `viewers`, `ai`, `productivity`, `other` |
   | `icon` | from the README | An https address of a square picture, 2 MB at most. |
   | `screenshots` | from the README | Up to six https picture addresses, 2 MB each at most. About 16:9 reads best. |
   | `changelog` | `CHANGELOG.md` | A path in your repository. The store reads it at the commit the release was tagged on. |
   | `readme` | `README.md` | Likewise |

   The changelog follows [Keep a Changelog](https://keepachangelog.com/).
   **What's New** shows the first `## ` section that names a version, skipping
   `Unreleased`. Without a changelog the store lists the release history
   instead. The changelog and README are read from GitHub, so a package
   published from another host shows neither.
   Each picture can be a PNG, JPEG, GIF, WebP or SVG. An SVG is drawn by the
   image viewer plugin's converter when that plugin is on, and by
   `rsvg-convert` (librsvg) otherwise. A machine may have neither, so a PNG
   reaches everyone.

3. Tag a release and publish the package on Packagist. From then on it is in
   the store, unverified.
4. To be **verified**, open a pull request against
   [janalis/porta](https://github.com/janalis/porta) that adds your package to
   `store/plugins.json`, and nothing else:

   ```json
   {
       "acme/porta-rector": { "category": "linters" }
   }
   ```

   An entry takes `category`, which overrides the package's own, and
   `replaces` (see the next section). Only Porta's maintainers set `featured`.

The pull request's **Store** check (`bin/store-check`) verifies that:

- the package is on Packagist, and is a `porta-plugin`;
- it is published from a GitHub repository owned by you, or by an
  organisation you are a *public* member of.

A maintainer reviews the rest. Once the pull request is merged, the entry shows
in every Porta within the hour. No Porta release is needed.

### Pictures

A plugin that lists no `icon` or no `screenshots` gets them from its README,
read at the release's tag. The store looks at the first four pictures there:

- the first one is the icon, when it is square (up to 3:2 either way);
- every wide one is a screenshot, when the package lists none.

When neither README's first picture is square, the first picture is the icon
anyway, shrunk to fit with its shape kept. The tool's comes before the
plugin's own, so a wordmark like PHPUnit's is used before a screenshot.

Badges (build status, version, downloads, coverage), people's avatars, and
anything under a heading that thanks someone (Sponsors, Backers,
Contributors…) are left out. Only https pictures are used. Of a logo shown
in a light and a dark version (`#gh-light-mode-only` / `#gh-dark-mode-only`),
the dark one is used.

The store also reads the README of each tool the plugin drives
(`extra.porta.tools`). A tool published on GitHub, as a composer package or as
a Homebrew formula whose home page is on GitHub lends the pictures above its
README's first `## ` section, usually its logo.
These come after the plugin's own pictures, and the first picture of either
README may be the icon. That is how the bundled `phpstan` plugin shows
PHPStan's logo as its icon and its own screenshot beside it.

What was found is kept for a week under `~/.porta/cache/store`. If a README
or a picture couldn't be fetched, or a picture couldn't be drawn (say, an SVG
before the image viewer is switched on), nothing is kept, and the store looks
again next session. Switching the image viewer on or off makes the store look
again straight away.

## Taking over a bundled plugin

Several bundled plugins drive someone else's tool: `mago`, `phpstan`,
`symfony-lsp` and others. The owners of that tool can publish the plugin
themselves and replace Porta's copy.

1. Publish your plugin as `<vendor>/porta-<name>`, where `<name>` is the
   bundled plugin's name, for instance `carthage-software/porta-mago`.
   Installing it then shadows the bundled copy, and users keep their settings.
2. In the registration pull request, say so:

   ```json
   {
       "carthage-software/porta-mago": { "category": "linters", "replaces": "mago" }
   }
   ```

On top of the usual checks, CI verifies that:

- `plugins/<name>` exists;
- the package installs under that name;
- your package's owner also owns a repository that the bundled plugin lists in
  `extra.porta.tools`. For `mago`, that is the owner of
  `carthage-software/mago`, the tool's composer package.

A bundled plugin that drives no published tool, such as `todo`, cannot be
taken over.

Once the takeover is merged:

- your package takes the place of Porta's own split in the store, with
  **Get**, and its page says which built-in plugin it replaces;
- a later Porta release drops its bundled copy.
