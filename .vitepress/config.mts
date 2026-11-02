import { execFileSync } from 'node:child_process'
import { readdirSync, readFileSync, existsSync } from 'node:fs'
import { dirname, join, posix, relative, resolve, sep } from 'node:path'
import { fileURLToPath } from 'node:url'
import { defineConfig } from 'vitepress'

// The site is built from the Markdown the F1 browser renders, where it lies:
// docs/ and each plugin's plugins/<name>/docs/. The sources keep their
// relative links between those trees; the link rule below maps them onto the
// site's URLs, and sends a link to anything the site does not publish
// (docs/internals/, source files) to GitHub.

const repo = 'https://github.com/janalis/porta'
const base = '/porta/'
const site = 'https://janalis.github.io' + base
const root = resolve(dirname(fileURLToPath(import.meta.url)), '..')

// Latest release tag, shown in the nav ("dev" outside a git checkout).
function version(): string {
  try {
    return execFileSync('git', ['describe', '--tags', '--abbrev=0'], { stdio: ['ignore', 'pipe', 'ignore'] }).toString().trim()
  } catch {
    return 'dev'
  }
}

// Bundled plugins, titled by their guide's first heading.
const plugins = readdirSync(join(root, 'plugins'))
  .filter((name) => existsSync(join(root, 'plugins', name, 'docs/README.md')))
  .map((name) => {
    const heading = readFileSync(join(root, 'plugins', name, 'docs/README.md'), 'utf8').match(/^# (.+)$/m)
    return { text: heading ? heading[1] : name, link: '/plugins/' + name + '/' }
  })
  .sort((a, b) => a.text.localeCompare(b.text))

// A source path (from the repository root) to its page URL, or null when the
// site does not publish it.
function pageUrl(source: string): string | null {
  if (source === 'CHANGELOG.md') return '/changelog'
  if (source === 'docs/README.md') return '/'
  let match = source.match(/^docs\/([^/]+)\.md$/)
  if (match) return '/' + match[1]
  match = source.match(/^plugins\/([^/]+)\/docs\/README\.md$/)
  if (match) return '/plugins/' + match[1] + '/'
  return null
}

export default defineConfig({
  title: 'Porta',
  description: 'A terminal IDE for PHP: LSP, debugger, git, live collaboration, agents and plugins.',
  lang: 'en-US',
  base,
  cleanUrls: true,
  lastUpdated: true,
  srcExclude: [
    '**/vendor/**',
    '**/tests/**',
    '.*/**',
    'build/**',
    'config/**',
    'coverage/**',
    'docker/**',
    'src/**',
    'translations/**',
    'docs/internals/**',
    'plugins/*/README.md',
    'AGENTS.md',
    'CLAUDE.md',
    'README.md',
    'TASKS.md',
  ],
  rewrites: {
    'docs/README.md': 'index.md',
    'docs/:page.md': ':page.md',
    'plugins/:plugin/docs/README.md': 'plugins/:plugin/index.md',
    'CHANGELOG.md': 'changelog.md',
  },
  vite: { publicDir: join(root, 'docs/public') },
  sitemap: { hostname: site },
  head: [
    ['link', { rel: 'icon', type: 'image/png', sizes: '32x32', href: base + 'favicon-32.png' }],
    ['link', { rel: 'apple-touch-icon', href: base + 'apple-touch-icon.png' }],
    ['meta', { name: 'theme-color', content: '#1e1c5b' }],
    ['meta', { property: 'og:type', content: 'website' }],
    ['meta', { property: 'og:site_name', content: 'Porta' }],
    ['meta', { property: 'og:image', content: site + 'og.png' }],
    ['meta', { name: 'twitter:card', content: 'summary_large_image' }],
  ],
  markdown: {
    // The pages name placeholders as <name> in running text, and the F1
    // browser draws none of their HTML either.
    html: false,
    config(md) {
      md.core.ruler.push('porta_links', (state) => {
        // The source's own folder: relativePath is the page's REWRITTEN one.
        const from = posix.dirname(relative(root, state.env.realPath ?? state.env.path ?? '').split(sep).join('/'))
        for (const block of state.tokens) {
          for (const token of block.children ?? []) {
            const href = token.type === 'link_open' ? token.attrGet('href') : null
            if (!href || /^[a-z][a-z+.-]*:|^#|^\//i.test(href)) continue
            const [path, hash] = href.split('#', 2)
            const source = posix.normalize(posix.join(from, path))
            const url = pageUrl(source)
            token.attrSet('href', (url ? url : repo + '/blob/main/' + source) + (hash ? '#' + hash : ''))
          }
        }
      })
      // `{{expr}}` in the configuration reference is text, not Vue.
      const render = md.render.bind(md)
      md.render = (src, env) => '<div v-pre>' + render(src, env) + '</div>'
    },
  },
  themeConfig: {
    logo: { src: '/logo.png', alt: 'Porta' },
    nav: [
      { text: 'Guide', link: '/getting-started', activeMatch: '^/(?!plugins/|changelog)' },
      { text: 'Plugins', link: '/plugins/git/', activeMatch: '/plugins/' },
      { text: 'Plugin API', link: '/plugins' },
      {
        text: version(),
        items: [
          { text: 'Changelog', link: '/changelog' },
          { text: 'Releases', link: repo + '/releases' },
          { text: 'Contributing', link: repo + '/blob/main/docs/internals/README.md' },
        ],
      },
    ],
    sidebar: {
      '/plugins/': [{ text: 'Bundled plugins', items: plugins }],
      '/': [
        {
          text: 'Using Porta',
          items: [
            { text: 'Introduction', link: '/' },
            { text: 'Getting started', link: '/getting-started' },
            { text: 'Editor', link: '/editor' },
            { text: 'Keybindings', link: '/keybindings' },
            { text: 'Search & navigation', link: '/search-navigation' },
            { text: 'Run & terminal', link: '/run-and-terminal' },
            { text: 'Debugger', link: '/debugger' },
            { text: 'Configuration', link: '/configuration' },
            { text: 'Troubleshooting', link: '/troubleshooting' },
          ],
        },
        {
          text: 'Extending',
          items: [
            { text: 'Plugins', link: '/plugins' },
            { text: 'Events', link: '/events' },
            { text: 'Architecture', link: '/architecture' },
            { text: 'Bundled plugins', link: '/plugins/git/' },
          ],
        },
      ],
    },
    search: { provider: 'local' },
    editLink: {
      // Serialized into the client bundle: no closure variables. The page's
      // source path, before the rewrites.
      pattern: ({ filePath }) => 'https://github.com/janalis/porta/edit/main/' + filePath,
      text: 'Edit this page on GitHub',
    },
    socialLinks: [{ icon: 'github', link: repo }],
    outline: { level: [2, 3] },
    footer: {
      message: 'Released under the MIT License.',
    },
  },
})
