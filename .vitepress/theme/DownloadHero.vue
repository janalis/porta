<script setup>
import { computed, onMounted, ref } from 'vue'
import { withBase } from 'vitepress'
import { PACKAGES, pick, url } from './downloads.mjs'

const GROUPS = [
  { name: 'macOS', note: 'macOS 13 or later', keys: ['macos-arm64', 'macos-x86_64'] },
  { name: 'Windows', note: 'Windows 10 or 11, x64 or Arm, with WSL', keys: ['windows'] },
  { name: 'Linux', note: 'GTK 4.10 and VTE 0.70', keys: ['deb-x86_64', 'rpm-x86_64', 'tar-x86_64', 'deb-aarch64', 'rpm-aarch64', 'tar-aarch64'] },
]
const SHORT = {
  'macos-arm64': 'Apple silicon', 'macos-x86_64': 'Intel', windows: 'Installer',
  'deb-x86_64': '.deb · x86-64', 'rpm-x86_64': '.rpm · x86-64', 'tar-x86_64': '.tar.gz · x86-64',
  'deb-aarch64': '.deb · arm64', 'rpm-aarch64': '.rpm · arm64', 'tar-aarch64': '.tar.gz · arm64',
}
const COMMAND = 'brew install janalis/tap/porta'

const detected = ref(null)
const version = ref('')
const copied = ref(false)
const group = computed(() => GROUPS.find((entry) => entry.keys.includes(detected.value))?.name ?? null)

onMounted(async () => {
  const hints = navigator.userAgentData
  let architecture = ''
  try {
    architecture = hints ? (await hints.getHighEntropyValues(['architecture'])).architecture ?? '' : ''
  } catch {
    // The user agent string is all there is.
  }
  detected.value = pick({ platform: hints?.platform ?? navigator.platform ?? '', architecture, userAgent: navigator.userAgent })
  try {
    const release = await fetch('https://api.github.com/repos/janalis/porta/releases/latest')
    version.value = release.ok ? (await release.json()).tag_name ?? '' : ''
  } catch {
    // Offline, or rate limited: the page reads the same without a number.
  }
})

async function copy() {
  try {
    await navigator.clipboard.writeText(COMMAND)
    copied.value = true
    setTimeout(() => (copied.value = false), 1600)
  } catch {
    // Without clipboard access the command is still there to select.
  }
}
</script>

<template>
  <section class="porta-download">
    <img class="icon" :src="withBase('/app-icon.png')" alt="" width="128" height="128" />
    <h1>Download Porta</h1>
    <p class="tagline">An IDE for PHP projects, in a window of its own. PHP included.</p>

    <a v-if="detected" class="primary" :href="url(detected)">
      <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v12M6 10l6 6 6-6M5 20h14" /></svg>
      Download for {{ PACKAGES[detected].label }}
    </a>
    <a v-else class="primary" href="#all">Choose your system below</a>
    <p class="meta">
      <span v-if="version">{{ version }} · </span>Free and open source · MIT
    </p>

    <div id="all" class="cards">
      <article v-for="entry in GROUPS" :key="entry.name" class="card" :class="{ yours: entry.name === group }">
        <header>
          <h2>{{ entry.name }}</h2>
          <span v-if="entry.name === group" class="badge">Your system</span>
        </header>
        <p class="note">{{ entry.note }}</p>
        <ul>
          <li v-for="key in entry.keys" :key="key">
            <a :href="url(key)" :class="{ match: key === detected }">{{ SHORT[key] }}</a>
          </li>
        </ul>
      </article>
    </div>

    <div class="terminal">
      <span class="label">In a terminal instead</span>
      <code>{{ COMMAND }}</code>
      <button type="button" @click="copy">{{ copied ? 'Copied' : 'Copy' }}</button>
    </div>
  </section>
</template>

<style scoped>
.porta-download {
  text-align: center;
  padding: 24px 0 8px;
}
.icon {
  display: block;
  margin: 0 auto;
  width: 128px;
  height: 128px;
  border-radius: 28px;
  box-shadow: 0 12px 32px rgba(30, 28, 91, 0.35);
}
h1 {
  margin: 24px 0 8px;
  font-size: 40px;
  line-height: 1.15;
  font-weight: 700;
  letter-spacing: -0.02em;
  border: none;
}
.tagline {
  margin: 0 auto 28px;
  max-width: 34em;
  color: var(--vp-c-text-2);
  font-size: 18px;
}
.primary {
  display: inline-flex;
  gap: 10px;
  align-items: center;
  padding: 14px 28px;
  border-radius: 999px;
  background: var(--vp-button-brand-bg);
  color: var(--vp-button-brand-text);
  font-size: 17px;
  font-weight: 600;
  text-decoration: none;
  transition: background 0.2s, transform 0.2s;
}
.primary:hover {
  background: var(--vp-button-brand-hover-bg);
  transform: translateY(-1px);
}
.meta {
  margin: 12px 0 40px;
  color: var(--vp-c-text-3);
  font-size: 14px;
}
.cards {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 16px;
  text-align: left;
  scroll-margin-top: 80px;
}
.card {
  padding: 20px;
  border: 1px solid var(--vp-c-divider);
  border-radius: 14px;
  background: var(--vp-c-bg-soft);
}
.card.yours {
  border-color: var(--vp-c-brand-1);
  box-shadow: 0 0 0 1px var(--vp-c-brand-1);
}
.card header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}
.card h2 {
  margin: 0;
  padding: 0;
  border: none;
  font-size: 20px;
  font-weight: 600;
}
.badge {
  padding: 2px 8px;
  border-radius: 999px;
  background: var(--vp-c-brand-soft);
  color: var(--vp-c-brand-1);
  font-size: 12px;
  font-weight: 600;
  white-space: nowrap;
}
.note {
  margin: 6px 0 12px;
  color: var(--vp-c-text-2);
  font-size: 13px;
  line-height: 1.5;
}
.card ul {
  margin: 0;
  padding: 0;
  list-style: none;
}
.card li + li {
  margin-top: 6px;
}
.card li a {
  font-weight: 500;
  text-decoration: none;
}
.card li a.match {
  font-weight: 700;
}
.terminal {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: center;
  justify-content: center;
  margin: 32px 0 8px;
  color: var(--vp-c-text-2);
  font-size: 14px;
}
.terminal code {
  padding: 6px 12px;
  border-radius: 8px;
  background: var(--vp-c-bg-alt);
  font-size: 14px;
}
.terminal button {
  padding: 4px 12px;
  border: 1px solid var(--vp-c-divider);
  border-radius: 8px;
  color: var(--vp-c-text-1);
  font-size: 13px;
}
.terminal button:hover {
  border-color: var(--vp-c-brand-1);
}
@media (max-width: 640px) {
  h1 {
    font-size: 30px;
  }
  .primary {
    padding: 12px 20px;
    font-size: 15px;
  }
}
</style>
