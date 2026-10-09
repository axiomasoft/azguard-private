import { defineConfig } from 'vitepress'

// Available at build time (Node) without pulling in @types/node.
declare const process: { env: Record<string, string | undefined> }

// GitHub Pages serves a project site under /<repo>/. CI sets VITEPRESS_BASE to the repository name;
// local builds fall back to /azguard/, and VITEPRESS_BASE=/ serves a custom domain.
const base = process.env.VITEPRESS_BASE || '/azguard/'
const repo = 'https://github.com/axiomasoft/azguard-private'

const sidebar = [
  {
    text: 'Getting started',
    items: [
      { text: 'Introduction', link: '/getting-started/introduction' },
      { text: 'Installation', link: '/getting-started/installation' },
      { text: 'Quick start', link: '/getting-started/quick-start' },
    ],
  },
  {
    text: 'Concepts',
    items: [
      { text: 'Panels', link: '/concepts/panels' },
      { text: 'Permissions', link: '/concepts/permissions' },
      { text: 'Roles', link: '/concepts/roles' },
      { text: 'Policies', link: '/concepts/policies' },
      { text: 'Decisions', link: '/concepts/decisions' },
    ],
  },
  {
    text: 'Guides',
    items: [
      { text: 'Checking access', link: '/guides/checking-access' },
      { text: 'Granting access', link: '/guides/granting-access' },
      { text: 'Tenants and scopes', link: '/guides/tenants-and-scopes' },
      { text: 'Filament', link: '/guides/filament' },
      { text: 'Testing', link: '/guides/testing' },
    ],
  },
  {
    text: 'Reference',
    items: [
      { text: 'Configuration', link: '/reference/configuration' },
      { text: 'Console commands', link: '/reference/commands' },
      { text: 'Events', link: '/reference/events' },
      { text: 'Exceptions', link: '/reference/exceptions' },
    ],
  },
  {
    text: 'Advanced',
    items: [
      { text: 'Sources', link: '/advanced/sources' },
      { text: 'Hooks and plugins', link: '/advanced/hooks-and-plugins' },
      { text: 'Performance', link: '/advanced/performance' },
      { text: 'Operations', link: '/advanced/operations' },
    ],
  },
  {
    text: 'More',
    items: [
      { text: 'Upgrading', link: '/upgrade' },
      { text: 'Comparison', link: '/comparison' },
    ],
  },
]

export default defineConfig({
  lang: 'en-US',
  title: 'AzGuard',
  description: 'Code-first authorization for Laravel: roles as classes, permissions as enums, one decision pipeline.',
  base,
  srcExclude: ['adr/**', '05_AI/**'],
  head: [['link', { rel: 'icon', type: 'image/png', href: `${base}favicon.png` }]],
  themeConfig: {
    logo: '/logo.png',
    siteTitle: 'AzGuard',
    nav: [
      { text: 'Guide', link: '/getting-started/introduction', activeMatch: '/(getting-started|concepts|guides)/' },
      { text: 'Reference', link: '/reference/configuration', activeMatch: '/reference/' },
      { text: 'Advanced', link: '/advanced/sources', activeMatch: '/advanced/' },
      { text: 'Changelog', link: `${repo}/blob/main/CHANGELOG.md` },
    ],
    sidebar,
    socialLinks: [{ icon: 'github', link: repo }],
    footer: { message: 'Released under the MIT License.', copyright: 'Copyright © 2026-present Axioma Studio' },
    search: { provider: 'local' },
    editLink: { pattern: `${repo}/edit/main/docs/:path`, text: 'Edit this page on GitHub' },
  },
})
