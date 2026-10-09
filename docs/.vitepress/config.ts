import { defineConfig } from 'vitepress'

// Available at build time (Node) without pulling in @types/node.
declare const process: { env: Record<string, string | undefined> }

// ─── Shared sidebar factories (EN) ───────────────────────────────────────────

function introSidebar(base = '') {
  const p = (s: string) => `${base}${s}`
  return [
    {
      text: 'Introduction',
      items: [
        { text: 'What is AzGuard?',   link: p('/introduction/why-azguard') },
        { text: 'Prerequisites',      link: p('/introduction/prerequisites') },
        { text: 'Installation',       link: p('/introduction/installation') },
        { text: 'Quick Start',        link: p('/introduction/quick-start') },
        { text: 'Headless Quick Start', link: p('/introduction/headless-quick-start') },
        { text: 'Upgrading',          link: p('/introduction/upgrading') },
        { text: 'Versioning',          link: p('/introduction/versioning') },
        { text: 'Known Limitations',   link: p('/introduction/known-limitations') },
        { text: 'Questions & Issues', link: p('/introduction/questions-issues') },
        { text: 'Changelog',          link: p('/introduction/changelog') },
      ],
    },
  ]
}

function basicUsageSidebar(base = '') {
  const p = (s: string) => `${base}${s}`
  return [
    {
      text: 'Basic Usage',
      items: [
        { text: 'Overview',                 link: p('/basic-usage/basic-usage') },
        { text: 'Permissions',              link: p('/basic-usage/permissions') },
        { text: 'Roles',                    link: p('/basic-usage/roles') },
        { text: 'Direct Grants',            link: p('/basic-usage/direct-grants') },
        { text: 'Blade Directives',         link: p('/basic-usage/blade-directives') },
        { text: 'Defining a Super-Admin',   link: p('/basic-usage/super-admin') },
        { text: 'Multiple Guards',          link: p('/basic-usage/multiple-guards') },
        { text: 'HTTP Access & Middleware', link: p('/basic-usage/http-access') },
        { text: 'Artisan Commands',         link: p('/basic-usage/artisan-commands') },
        { text: 'Filament Integration',     link: p('/basic-usage/filament') },
        { text: 'Frontend Abilities',       link: p('/basic-usage/abilities-frontend') },
      ],
    },
  ]
}

function bestPracticesSidebar(base = '') {
  const p = (s: string) => `${base}${s}`
  return [
    {
      text: 'Best Practices',
      items: [
        { text: 'Roles vs Permissions',   link: p('/best-practices/best-practices') },
        { text: 'Model Policies & Gates', link: p('/best-practices/policies-and-gates') },
        { text: 'Permission Catalog',     link: p('/best-practices/permission-catalog') },
        { text: 'Performance Tips',       link: p('/best-practices/performance-tips') },
      ],
    },
  ]
}

function advancedSidebar(base = '') {
  const p = (s: string) => `${base}${s}`
  return [
    {
      text: 'Advanced',
      items: [
        { text: 'Testing',          link: p('/advanced/testing') },
        { text: 'Database Seeding', link: p('/advanced/seeding') },
        { text: 'Exceptions',       link: p('/advanced/exceptions') },
        { text: 'Extending',        link: p('/advanced/extending') },
        { text: 'Cache',            link: p('/advanced/cache') },
        { text: 'Events',           link: p('/advanced/events') },
        { text: 'Context (opt-in)', link: p('/advanced/context') },
        { text: 'Entity Scopes',    link: p('/advanced/entity-scopes') },
        { text: 'Panels',           link: p('/advanced/panels') },
        { text: 'UUID / ULID',      link: p('/advanced/uuid-ulid') },
        { text: 'PhpStorm',         link: p('/advanced/phpstorm') },
      ],
    },
    {
      text: 'Recipes',
      collapsed: true,
      items: [
        { text: 'Soft Role Override',    link: p('/recipes/soft-role-override') },
        { text: 'Super-admin Wildcard',  link: p('/recipes/super-admin-wildcard') },
        { text: 'Temp Access via Grant', link: p('/recipes/temp-access-via-grant') },
        { text: 'Multi-Tenant Roles',    link: p('/recipes/multi-tenant') },
        { text: 'Inertia Permissions',   link: p('/recipes/inertia-permissions') },
        { text: 'Policy Integration',    link: p('/recipes/policy-integration') },
        { text: 'Integration & Testing', link: p('/recipes/integration') },
      ],
    },
  ]
}

// ─── Sidebar map helper ───────────────────────────────────────────────────────

function makeSidebarMap(base: string, factory: (b: string) => any[], pages: string[]) {
  return Object.fromEntries(pages.map(p => [`${base}${p}`, factory(base)]))
}

const introPages = [
  '/introduction/why-azguard', '/introduction/prerequisites', '/introduction/installation',
  '/introduction/quick-start', '/introduction/headless-quick-start', '/introduction/upgrading', '/introduction/versioning', '/introduction/known-limitations',
  '/introduction/questions-issues', '/introduction/changelog', '/introduction',
]
const basicPages = [
  '/basic-usage/basic-usage', '/basic-usage/permissions', '/basic-usage/roles',
  '/basic-usage/direct-grants', '/basic-usage/blade-directives', '/basic-usage/super-admin',
  '/basic-usage/multiple-guards', '/basic-usage/http-access', '/basic-usage/artisan-commands',
  '/basic-usage/filament', '/basic-usage/abilities-frontend',
]
const bestPages = [
  '/best-practices/best-practices', '/best-practices/policies-and-gates',
  '/best-practices/permission-catalog', '/best-practices/performance-tips',
]
const advancedPages = [
  '/advanced/testing', '/advanced/seeding', '/advanced/exceptions', '/advanced/extending',
  '/advanced/cache', '/advanced/events', '/advanced/context', '/advanced/entity-scopes',
  '/advanced/panels', '/advanced/uuid-ulid', '/advanced/phpstorm', '/recipes',
]

// ─── Config ───────────────────────────────────────────────────────────────────

// GitHub Pages serves a project site under /<repo>/. CI sets VITEPRESS_BASE to
// the repo name (so it works for azguard-private now and azguard after rename);
// falls back to /azguard/ for local builds, or set VITEPRESS_BASE=/ for a custom domain.
const base = process.env.VITEPRESS_BASE || '/azguard/'

export default defineConfig({
  lang: 'en-US',
  title: 'AzGuard',
  description: 'Code-first RBAC for Laravel — roles as PHP classes, permissions in Git.',

  base,

  head: [
    ['link', { rel: 'icon', type: 'image/svg+xml', href: `${base}favicon.svg` }],
  ],

  themeConfig: {
    logo: '/logo.svg',
    siteTitle: 'AzGuard',

    nav: [
      {
        text: 'Introduction',
        link: '/introduction/why-azguard',
        activeMatch: '/introduction/',
      },
      {
        text: 'Basic Usage',
        link: '/basic-usage/basic-usage',
        activeMatch: '/basic-usage/',
      },
      {
        text: 'Best Practices',
        link: '/best-practices/best-practices',
        activeMatch: '/best-practices/',
      },
      {
        text: 'Advanced',
        link: '/advanced/testing',
        activeMatch: '/(advanced|recipes)/',
      },
      { text: 'Changelog', link: '/introduction/changelog' },
      { text: 'GitHub', link: 'https://github.com/axioma-studio/azguard' },
    ],

    sidebar: {
      ...makeSidebarMap('', introSidebar, introPages),
      ...makeSidebarMap('', basicUsageSidebar, basicPages),
      ...makeSidebarMap('', bestPracticesSidebar, bestPages),
      ...makeSidebarMap('', advancedSidebar, advancedPages),
    },

    socialLinks: [
      { icon: 'github', link: 'https://github.com/axioma-studio/azguard' },
    ],

    footer: {
      message: 'Released under the MIT License.',
      copyright: 'Copyright © 2025-present Axioma Studio',
    },

    search: {
      provider: 'local',
    },

    editLink: {
      pattern: 'https://github.com/axioma-studio/azguard/edit/main/docs/:path',
      text: 'Edit this page on GitHub',
    },
  },
})
