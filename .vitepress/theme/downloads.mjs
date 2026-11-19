// The desktop packages, and which one fits the visitor's system.
// Kept free of the browser so `node --test` can hold it to the release's file names.

export const LATEST = 'https://github.com/janalis/porta/releases/latest/download/'

/** The Download page's main link, before it knows the visitor. */
export const DOWNLOAD = 'https://github.com/janalis/porta/releases/latest#download'

/** Every package a release attaches, by the name the release workflow gives it. */
export const PACKAGES = {
  'macos-arm64': { file: 'porta-desktop-macos-arm64.dmg', label: 'macOS, Apple silicon' },
  'macos-x86_64': { file: 'porta-desktop-macos-x86_64.dmg', label: 'macOS, Intel' },
  'windows': { file: 'porta-desktop-windows.exe', label: 'Windows' },
  'deb-x86_64': { file: 'porta-desktop-linux-x86_64.deb', label: 'Debian, Ubuntu (x86-64)' },
  'deb-aarch64': { file: 'porta-desktop-linux-aarch64.deb', label: 'Debian, Ubuntu (arm64)' },
  'rpm-x86_64': { file: 'porta-desktop-linux-x86_64.rpm', label: 'Fedora, RHEL (x86-64)' },
  'rpm-aarch64': { file: 'porta-desktop-linux-aarch64.rpm', label: 'Fedora, RHEL (arm64)' },
  'tar-x86_64': { file: 'porta-desktop-linux-x86_64.tar.gz', label: 'Other Linux (x86-64)' },
  'tar-aarch64': { file: 'porta-desktop-linux-aarch64.tar.gz', label: 'Other Linux (arm64)' },
}

/**
 * The package for a visitor, or null when nothing can be told.
 *
 * @param {{platform?: string, architecture?: string, userAgent?: string}} visitor what the browser says
 */
export function pick({ platform = '', architecture = '', userAgent = '' }) {
  const said = `${platform} ${userAgent}`.toLowerCase()
  const arm = /arm|aarch64/.test(architecture.toLowerCase()) || /aarch64|arm64/.test(said)
  if (/android|iphone|ipad/.test(said)) {
    return null
  }
  if (/mac/.test(said)) {
    // Safari never says which Mac it runs on, and every Mac sold since 2023 is Apple silicon.
    return /x86|intel/.test(architecture.toLowerCase()) ? 'macos-x86_64' : 'macos-arm64'
  }
  if (/win/.test(said)) {
    return 'windows'
  }
  if (/linux|x11/.test(said)) {
    const suffix = arm ? 'aarch64' : 'x86_64'
    if (/fedora|red hat|rhel|centos|suse/.test(said)) {
      return `rpm-${suffix}`
    }
    return /ubuntu|debian|mint/.test(said) ? `deb-${suffix}` : `tar-${suffix}`
  }
  return null
}

export function url(key) {
  return LATEST + PACKAGES[key].file
}
