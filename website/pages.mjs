// Single map from the repository's Markdown sources to site pages.
// scripts/sync.mjs builds the pages from it and .vitepress/config.mts builds
// the sidebar from it, so the two never disagree.

export const repoUrl = 'https://github.com/MYLEKHATECH/laravel-record-api'

// README.md `## ` sections, grouped into pages. Every README section must be
// listed here or in `skippedReadmeSections`; the sync fails on an unknown one
// so new README content is never silently left off the site.
export const readmePages = [
  {
    path: 'guide/getting-started',
    title: 'Getting Started',
    sections: ['Quick Start', 'Features', 'Requirements', 'Dependencies and Optional Integrations'],
  },
  {
    path: 'guide/installation',
    title: 'Installation',
    sections: ['Installation & Setup', 'Database-Specific Instructions'],
  },
  {
    path: 'guide/configuration',
    title: 'Configuration',
    sections: ['Configuration Guide', 'Configuration', 'Advanced Configuration'],
  },
  {
    path: 'guide/usage',
    title: 'Usage',
    sections: ['Usage Examples', 'API Endpoints', 'Usage', 'Example Controller', 'QueryHelpers Trait'],
  },
  {
    path: 'guide/services',
    title: 'Services & Middleware',
    sections: ['Services', 'Middleware'],
  },
  {
    path: 'guide/cli',
    title: 'CLI Commands',
    sections: ['CLI Commands', 'Audit Log Cleanup'],
  },
  {
    path: 'guide/performance',
    title: 'Performance',
    sections: ['Performance Optimization'],
  },
  {
    path: 'guide/testing',
    title: 'Testing & Development',
    sections: ['Testing Instructions', 'Development Setup'],
  },
  {
    path: 'guide/troubleshooting',
    title: 'Troubleshooting',
    sections: ['Troubleshooting'],
  },
]

// README sections that only make sense on GitHub (link lists, licence footer).
export const skippedReadmeSections = ['Documentation', 'Notes', 'License']

// Whole files copied as one page each.
export const filePages = [
  { path: 'reference/api', title: 'API Reference', source: 'docs/api-documentation.md' },
  { path: 'reference/audit-logging', title: 'Audit Logging', source: 'docs/audit-interface.md' },
  { path: 'reference/changelog', title: 'Changelog', source: 'CHANGELOG.md' },
  { path: 'ai/backend', title: 'Backend Agent Context', source: 'resources/boost/skills/record-api/SKILL.md' },
  { path: 'ai/frontend', title: 'Frontend Agent Context', source: 'resources/boost/skills/record-api-frontend/SKILL.md' },
]

export const sidebar = [
  { text: 'Guide', items: readmePages.map((p) => ({ text: p.title, link: `/${p.path}` })) },
  { text: 'Reference', items: filePages.filter((p) => p.path.startsWith('reference/')).map((p) => ({ text: p.title, link: `/${p.path}` })) },
  { text: 'AI Agents', items: filePages.filter((p) => p.path.startsWith('ai/')).map((p) => ({ text: p.title, link: `/${p.path}` })) },
]
