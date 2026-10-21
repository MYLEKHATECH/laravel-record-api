// Builds the site's Markdown pages from README.md and docs/ (see pages.mjs).
// The generated folders are git-ignored: edit the sources, not the output.

import { mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { dirname, join, posix, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { filePages, readmePages, repoUrl, skippedReadmeSections } from '../pages.mjs'

const website = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const repo = resolve(website, '..')

// Source file (repo-relative) → site page, for rewriting links between them.
const pageForSource = new Map([
  ['README.md', '/guide/getting-started'],
  ...filePages.map((p) => [p.source, `/${p.path}`]),
])

function stripEmoji(text) {
  return text.replace(/[\p{Extended_Pictographic}\u{FE0F}\u{200D}]/gu, '').trim()
}

// Rewrites a relative link found in `sourceFile`: to the matching site page
// when there is one, otherwise to the file on GitHub.
function rewriteLink(target, sourceFile) {
  if (/^([a-z]+:|#|\/)/i.test(target)) return target
  const [file, hash] = target.split('#')
  const repoPath = posix.normalize(posix.join(posix.dirname(sourceFile), file))
  const page = pageForSource.get(repoPath)
  if (page) return hash ? `${page}#${hash}` : page
  return `${repoUrl}/blob/main/${repoPath}${hash ? `#${hash}` : ''}`
}

function rewriteLinks(markdown, sourceFile) {
  let inFence = false
  return markdown
    .split('\n')
    .map((line) => {
      if (/^\s*(```|~~~)/.test(line)) inFence = !inFence
      if (inFence) return line
      return line.replace(/\]\(([^)\s]+)\)/g, (_, target) => `](${rewriteLink(target, sourceFile)})`)
    })
    .join('\n')
}

// Splits README.md into its `## ` sections, ignoring `##` inside code fences.
// Sections that share a name are merged in README order.
function readmeSections() {
  const sections = new Map()
  let current = null
  let inFence = false
  for (const line of readFileSync(join(repo, 'README.md'), 'utf8').split('\n')) {
    if (/^\s*(```|~~~)/.test(line)) inFence = !inFence
    const heading = !inFence && line.match(/^## (.+)$/)
    if (heading) {
      current = stripEmoji(heading[1])
      if (!sections.has(current)) sections.set(current, [`## ${current}`])
    } else if (current) {
      sections.get(current).push(line)
    }
  }
  return new Map([...sections].map(([name, lines]) => [name, lines.join('\n').trim()]))
}

function writePage(path, title, body) {
  const file = join(website, `${path}.md`)
  mkdirSync(dirname(file), { recursive: true })
  // v-pre: the sources show placeholders such as {{baseUrl}} in prose, which
  // Vue would otherwise try to evaluate.
  writeFileSync(file, `---\ntitle: ${JSON.stringify(title)}\n---\n\n<div v-pre>\n\n${body.trim()}\n\n</div>\n`)
}

for (const dir of new Set([...readmePages, ...filePages].map((p) => p.path.split('/')[0]))) {
  rmSync(join(website, dir), { recursive: true, force: true })
}

const sections = readmeSections()
const known = new Set([...readmePages.flatMap((p) => p.sections), ...skippedReadmeSections])
const unknown = [...sections.keys()].filter((name) => !known.has(name))
if (unknown.length) {
  throw new Error(`README.md sections missing from website/pages.mjs: ${unknown.join(', ')}`)
}

for (const page of readmePages) {
  const parts = page.sections.map((name) => {
    if (!sections.has(name)) throw new Error(`website/pages.mjs lists "${name}", which README.md does not have`)
    return sections.get(name)
  })
  writePage(page.path, page.title, `# ${page.title}\n\n${rewriteLinks(parts.join('\n\n'), 'README.md')}`)
}

for (const page of filePages) {
  const source = readFileSync(join(repo, page.source), 'utf8')
    .replace(/^---\n[\s\S]*?\n---\n/, '') // drop any front matter
    .replace(/^#!? .*\n/, '') // the file's own H1 is replaced by the page title
    .replace(/^.*record-api-docs\.vercel\.app.*\n/m, '') // "read this online" pointer to the site itself
  writePage(page.path, page.title, `# ${page.title}\n\n${rewriteLinks(source, page.source)}`)
}

console.log(`Synced ${readmePages.length + filePages.length} pages`)
