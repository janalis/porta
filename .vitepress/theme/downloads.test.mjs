import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { DOWNLOAD, PACKAGES, pick, url } from './downloads.mjs'

const mac = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15'
const windows = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36'
const ubuntu = 'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:132.0) Gecko/20100101 Firefox/132.0'
const fedoraArm = 'Mozilla/5.0 (X11; Fedora; Linux aarch64; rv:132.0) Gecko/20100101 Firefox/132.0'
const linux = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36'

test('each system gets its own package', () => {
  assert.equal(pick({ userAgent: mac }), 'macos-arm64')
  assert.equal(pick({ platform: 'macOS', architecture: 'x86', userAgent: mac }), 'macos-x86_64')
  assert.equal(pick({ platform: 'Windows', architecture: 'arm', userAgent: windows }), 'windows')
  assert.equal(pick({ userAgent: ubuntu }), 'deb-x86_64')
  assert.equal(pick({ userAgent: fedoraArm }), 'rpm-aarch64')
  assert.equal(pick({ platform: 'Linux', architecture: 'arm', userAgent: linux }), 'tar-aarch64')
  assert.equal(pick({ userAgent: linux }), 'tar-x86_64')
})

test('a phone gets no package', () => {
  assert.equal(pick({ userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)' }), null)
  assert.equal(pick({ userAgent: 'Mozilla/5.0 (Linux; Android 15)' }), null)
  assert.equal(pick({}), null)
})

test('the names are the ones the release attaches, and the page links each one', () => {
  const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8')
  const workflow = read('.github/workflows/desktop.yml')
  assert.ok(workflow.includes('porta-desktop-${{ matrix.name }}.dmg'))
  assert.ok(workflow.includes('name: macos-arm64') && workflow.includes('name: macos-x86_64'))
  assert.ok(read('desktop/linux/package.sh').includes('porta-desktop-linux-$(uname -m).$format'))
  assert.ok(read('desktop/linux/package.sh').includes('porta-desktop-linux-$(uname -m).tar.gz'))
  assert.ok(read('desktop/windows/Installer/porta.iss').includes('OutputBaseFilename=porta-desktop-windows'))
  const page = read('docs/download.md')
  assert.ok(page.includes(`(${DOWNLOAD})`), 'the page has the link the theme points')
  for (const key of Object.keys(PACKAGES)) {
    assert.ok(page.includes(url(key)), `the page does not link ${PACKAGES[key].file}`)
  }
})
