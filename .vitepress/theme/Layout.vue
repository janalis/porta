<script setup>
import DefaultTheme from 'vitepress/theme'
import { useData } from 'vitepress'
import DownloadHero from './DownloadHero.vue'

const { page } = useData()
</script>

<template>
  <DefaultTheme.Layout :class="{ 'porta-download-page': page.relativePath === 'download.md' }">
    <template #doc-before>
      <DownloadHero v-if="page.relativePath === 'download.md'" />
    </template>
  </DefaultTheme.Layout>
</template>

<style>
/* The page's own title and plain link stay for Porta's help viewer; here the hero says them. */
.porta-download-page .vp-doc h1#download,
.porta-download-page .vp-doc h1#download + p,
.porta-download-page .vp-doc h1#download + p + p {
  display: none;
}
</style>
