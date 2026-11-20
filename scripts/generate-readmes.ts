import { existsSync, readFileSync, writeFileSync } from 'node:fs';

const root = new URL('../', import.meta.url);
const source = 'docs/source/';
const read = (path: string): string => readFileSync(new URL(path, root), 'utf8').replaceAll('\r\n', '\n');
const json = (path: string) => JSON.parse(read(path));
const metadata = json(source + 'product-metadata.json');
const features = json(source + 'feature-catalog.json');
const taskIds = new Map(json('.ai/tasks.json').map((task) => [task.verification, task.status]));
const check = process.argv.includes('--check');
const editions = ['free', 'pro'] as const;
const statuses = ['planned', 'implemented', 'verified', 'released'];

function requireValue(condition: unknown, message: string): asserts condition {
  if (!condition) throw new Error(message);
}

function deliver(path: string, content: string): void {
  if (check) {
    requireValue(read(path) === content, `Generated documentation drift: ${path}`);
  } else {
    writeFileSync(new URL(path, root), content);
  }
}

function validateVersion(value: unknown): boolean {
  return typeof value === 'string' && /^\d+(\.\d+){1,2}$/.test(value);
}

requireValue(statuses.includes(metadata.status), 'Invalid product status');
requireValue(metadata.status !== 'released', 'Release documentation requires P9 release metadata and validator evidence.');
requireValue(typeof metadata.product === 'string' && metadata.product === metadata.free?.title, 'Missing or inconsistent product name');
requireValue(typeof metadata.author === 'string' && metadata.author.trim(), 'Missing author');
requireValue(Array.isArray(metadata.wordpress_org_contributors), 'Invalid WordPress.org contributor metadata');
requireValue(
  Array.isArray(metadata.wordpress_org_tags) &&
  metadata.wordpress_org_tags.length >= 1 && metadata.wordpress_org_tags.length <= 5 &&
  metadata.wordpress_org_tags.every((tag: unknown) =>
    typeof tag === 'string' && /^[a-z0-9][a-z0-9 -]{1,19}$/.test(tag)
  ) && new Set(metadata.wordpress_org_tags).size === metadata.wordpress_org_tags.length,
  'Canonical WordPress.org tags must be 1–5 unique factual lowercase terms under 20 characters',
);
requireValue(
  metadata.wordpress_org_contributors.every((person: unknown) =>
    typeof person === 'string' && /^[A-Za-z0-9_-]{2,60}$/.test(person)
  ) && new Set(metadata.wordpress_org_contributors).size === metadata.wordpress_org_contributors.length,
  'WordPress.org contributor IDs must be verified usernames, not invented display names',
);
requireValue(metadata.license === 'GPL-2.0-or-later', 'Invalid product license');
requireValue(typeof metadata.repository === 'string' && /^https:\/\/github\.com\/[\w-]+\/[\w-]+$/.test(metadata.repository), 'Invalid repository URL');
for (const field of ['version', 'requires_wp', 'requires_php', 'requires_wc', 'tested_wp', 'tested_wc']) {
  requireValue(validateVersion(metadata[field]), `Invalid version metadata: ${field}`);
}
const versionParts = (value: string): number[] => value.split('.').map(Number);
const versionAtLeast = (actual: string, minimum: string): boolean => {
  const actualParts = versionParts(actual);
  const minimumParts = versionParts(minimum);
  for (let i = 0; i < Math.max(actualParts.length, minimumParts.length); i++) {
    if ((actualParts[i] ?? 0) > (minimumParts[i] ?? 0)) return true;
    if ((actualParts[i] ?? 0) < (minimumParts[i] ?? 0)) return false;
  }
  return true;
};
requireValue(
  versionAtLeast(metadata.tested_wp, metadata.requires_wp) &&
  versionAtLeast(metadata.tested_wc, metadata.requires_wc),
  'Tested WP/WooCommerce versions must not be older than minimum requirements.',
);
requireValue(Array.isArray(features), 'Feature catalog must be an array');
requireValue(new Set(features.map((feature) => feature.id)).size === features.length, 'Duplicate feature ID');
for (const feature of features) {
  requireValue(/^F-[A-Z0-9-]+$/.test(feature.id) && editions.includes(feature.edition) && statuses.includes(feature.status), `Invalid feature: ${feature.id}`);
  requireValue(typeof feature.summary === 'string' && feature.summary.trim() && taskIds.has(feature.test_id), `Missing summary or test ID: ${feature.id}`);
  if (['verified', 'released'].includes(feature.status)) {
    requireValue(taskIds.get(feature.test_id) === 'done' &&
      typeof feature.evidence === 'string' && /^playground\/evidence\/[\w.-]+\.log$/.test(feature.evidence) &&
      existsSync(new URL(feature.evidence, root)), `Missing verified evidence: ${feature.id}`);
  }
}

const visibleFeatures = features.filter((feature) => ['verified', 'released'].includes(feature.status));
const support = read(source + 'support-and-privacy.md').trim();
const installation = read(source + 'installation.md').trim();
const faq = read(source + 'faq.md').trim();
const changes = read(`${source}changelog/${metadata.version}.md`);
const upgrade = json(source + 'upgrade-notices.json')[metadata.version];
requireValue(typeof upgrade === 'string' && upgrade.trim(), 'Missing versioned upgrade notice');
requireValue(typeof metadata.pro.free_api === 'string' && /^>=\d+\.\d+\.\d+ <\d+\.\d+\.\d+$/.test(metadata.pro.free_api), 'Missing Pro Free API constraint');

const outputs = new Map<string, string>();
for (const edition of editions) {
  const { slug, title, description, dependencies } = metadata[edition];
  requireValue(typeof slug === 'string' && /^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(slug), `Invalid slug: ${edition}`);
  requireValue(typeof title === 'string' && title.trim() && typeof description === 'string' && description.trim() && !description.includes('\n') && [...description].length <= 150, `Invalid edition copy: ${edition}`);
  const expectedDependencies = edition === 'free' ? ['woocommerce'] : ['woocommerce', metadata.free.slug];
  requireValue(JSON.stringify(dependencies) === JSON.stringify(expectedDependencies), `Invalid dependencies: ${edition}`);

  const folder = `plugins/${slug}`;
  const header = read(`${folder}/${slug}.php`);
  const fields = {
    'Plugin Name': title, Version: metadata.version, 'Requires at least': metadata.requires_wp,
    'Requires PHP': metadata.requires_php, 'Tested up to': metadata.tested_wp,
    'WC requires at least': metadata.requires_wc, 'WC tested up to': metadata.tested_wc,
    'Requires Plugins': dependencies.join(', '), Author: metadata.author, License: metadata.license, 'Text Domain': slug,
  };
  for (const [key, value] of Object.entries(fields)) {
    requireValue(header.split('\n').some((line) => line.trim() === `* ${key}: ${value}`), `Plugin header metadata drift: ${slug} / ${key}`);
  }

  const requiredFree = edition === 'pro'
    ? ` Activate the Free edition before Pro. Pro requires Free API \`${metadata.pro.free_api}\`.`
    : '';
  const quickStart = installation + requiredFree;
  const scope = visibleFeatures.filter((feature) => feature.edition === edition).map((feature) => `- ${feature.summary}`).join('\n');
  const heading = edition === 'free' ? 'Free' : 'Pro';
  const history = changes.split(`## ${heading}\n`)[1]?.split('\n## ')[0]?.trim();
  requireValue(history, `Missing changelog for ${edition}`);

  outputs.set(`${folder}/README.md`, `# ${title}\n\n${description}\n\n## Requirements\n\nWordPress ${metadata.requires_wp}+, PHP ${metadata.requires_php}+, and WooCommerce ${metadata.requires_wc}+ are minimum requirements for the development build.\n\n## Development installation\n\n${quickStart}\n\n## Verified preview scope\n\n${scope}\n\n## FAQ\n\n${faq}\n\n## Support and privacy\n\n${support}\n\n## Changelog\n\n### ${metadata.version}\n\n${history}\n\n## Upgrade note\n\n${upgrade}\n`);
  if (edition === 'free') {
    const wpFaq = faq.replace(/^### (.+)$/gm, '= $1 =');
    outputs.set(`${folder}/readme.txt`, `=== ${title} ===\nTags: ${metadata.wordpress_org_tags.join(', ')}\n${metadata.wordpress_org_contributors.length ? `Contributors: ${metadata.wordpress_org_contributors.join(', ')}\n` : ''}Requires Plugins: ${dependencies.join(', ')}\nRequires at least: ${metadata.requires_wp}\nTested up to: ${metadata.tested_wp}\nRequires PHP: ${metadata.requires_php}\nWC requires at least: ${metadata.requires_wc}\nWC tested up to: ${metadata.tested_wc}\nStable tag: ${metadata.version}\nLicense: ${metadata.license}\n\n${description}\n\n== Description ==\n\n${scope}\n\n== Installation ==\n\n${quickStart}\n\n== Frequently Asked Questions ==\n\n${wpFaq}\n\n== Changelog ==\n\n= ${metadata.version} =\n\n${history}\n\n== Upgrade Notice ==\n\n= ${metadata.version} =\n\n${upgrade}\n`);
  }
  const channel = edition === 'free' ? 'wordpress-org' : 'woocommerce-marketplace';
  outputs.set(`docs/submission/${channel}/listing-copy.generated.md`, `# ${title}\n\nDevelopment draft. Submission and publication are pending release verification.\n\n${description}\n\n${scope}\n\n${quickStart}\n`);
}

outputs.set('README.md', `# ${metadata.product}\n\nDevelopment preview ${metadata.version}. The project builds a standalone [Free edition](plugins/${metadata.free.slug}/README.md) and a separate [Pro add-on](plugins/${metadata.pro.slug}/README.md).\n\nSee [Start here](START_HERE.md) for build and test commands, the [backlog](BACKLOG.md) for project status, and [documentation sources](docs/release/README-MAP.md) for generation instructions.\n`);
outputs.set('docs/release/metadata-sync-report.json', JSON.stringify({
  version: metadata.version,
  status: metadata.status,
  checked_headers: editions.map((edition) => metadata[edition].slug),
  verified_preview_features: visibleFeatures.map(({ id, edition, test_id, evidence }) => ({ id, edition, test_id, evidence })),
  generated_files: [...outputs.keys()],
  release_checks_pending: [
    'Official WordPress.org readme validator',
    'Verified WordPress.org contributor usernames and tested-version metadata',
    'Marketplace, screenshots, and production package validation',
  ],
}, null, 2) + '\n');

for (const [path, content] of outputs) deliver(path, content);
console.log(`PASS ${check ? 'verified' : 'generated'} ${outputs.size} documentation outputs for ${metadata.version}`);
