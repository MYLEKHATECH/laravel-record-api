import { defineConfig } from 'vitepress'
import { repoUrl, sidebar } from '../pages.mjs'

export default defineConfig({
  title: 'Record API',
  description: 'Config-driven REST API for Laravel: CRUD, bulk, upsert, RPC, audit logging and OpenAPI from table configuration.',
  lang: 'en-US',
  cleanUrls: true,
  sitemap: { hostname: 'https://record-api-docs.vercel.app' },
  head: [
    ['link', { rel: 'icon', type: 'image/svg+xml', href: '/logo.svg' }],
    ['meta', { property: 'og:title', content: 'Record API' }],
    ['meta', { property: 'og:description', content: 'Config-driven REST API for Laravel.' }],
  ],
  // Example URLs such as http://localhost:8000/api/v1/docs are not site pages.
  ignoreDeadLinks: [/^https?:\/\/localhost/],
  markdown: {
    // Off so placeholders such as {id} at the end of a line stay as text.
    attrs: { disable: true },
    languageAlias: { env: 'ini' },
  },
  // The API Reference page is one long document; its chunk loads only on that page.
  vite: { build: { chunkSizeWarningLimit: 1024 } },
  themeConfig: {
    logo: '/logo.svg',
    nav: [
      { text: 'Guide', link: '/guide/getting-started', activeMatch: '/guide/' },
      { text: 'API Reference', link: '/reference/api', activeMatch: '/reference/' },
      { text: 'AI Agents', link: '/ai/backend', activeMatch: '/ai/' },
      { text: 'Changelog', link: '/reference/changelog' },
    ],
    sidebar,
    outline: { level: [2, 3] },
    search: { provider: 'local' },
    socialLinks: [{ icon: 'github', link: repoUrl }],
    footer: {
      message: 'Released under the MIT License.',
      copyright: 'Copyright © MYLEKHA',
    },
  },
})
