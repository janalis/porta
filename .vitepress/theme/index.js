import DefaultTheme from 'vitepress/theme'
import { DOWNLOAD, PACKAGES, pick, url } from './downloads.mjs'

// The Download page's link, pointed at the visitor's own package. Without
// JavaScript, or in Porta's own help viewer, it stays the releases page.
async function pointDownloads() {
  const link = document.querySelector(`a[href="${DOWNLOAD}"]`)
  if (!link) {
    return
  }
  const hints = navigator.userAgentData
  let architecture = ''
  try {
    architecture = hints ? (await hints.getHighEntropyValues(['architecture'])).architecture ?? '' : ''
  } catch {
    // Not every browser answers: the user agent string is all there is.
  }
  const key = pick({ platform: hints?.platform ?? navigator.platform ?? '', architecture, userAgent: navigator.userAgent })
  if (key) {
    link.href = url(key)
    link.querySelector('strong')?.replaceChildren(`Download for ${PACKAGES[key].label}`)
  }
}

export default {
  extends: DefaultTheme,
  enhanceApp({ router }) {
    if (typeof window === 'undefined') {
      return
    }
    const previous = router.onAfterRouteChanged
    router.onAfterRouteChanged = async (to) => {
      await previous?.(to)
      requestAnimationFrame(pointDownloads)
    }
    window.addEventListener('load', pointDownloads)
  },
}
