import { readFileSync, writeFileSync } from 'node:fs';

const root = new URL('../', import.meta.url);
const metadata = JSON.parse(readFileSync(new URL('docs/source/product-metadata.json', root), 'utf8'));
const check = process.argv.includes('--check');

function deliver(relativePath: string, content: string): void {
  const path = new URL(relativePath, root);
  if (check) {
    if (readFileSync(path, 'utf8') !== content) {
      throw new Error(`Generated documentation drift: ${relativePath}`);
    }
    return;
  }
  writeFileSync(path, content);
}

for (const edition of ['free', 'pro'] as const) {
  const { slug, description } = metadata[edition];
  const folder = `plugins/${slug}`;
  const title = edition === 'free' ? metadata.product : 'WPEssential Bank Transfer Matcher Pro for WooCommerce';
  const header = readFileSync(new URL(`${folder}/${slug}.php`, root), 'utf8');
  const fields = {
    'Plugin Name': title, Version: metadata.version, 'Requires at least': metadata.requires_wp,
    'Requires PHP': metadata.requires_php, License: metadata.license, 'Text Domain': slug,
  };
  for (const [key, value] of Object.entries(fields)) {
    if (!header.split('\n').some((line) => line.trim() === `* ${key}: ${value}`)) {
      throw new Error(`Plugin header metadata drift: ${slug} / ${key}`);
    }
  }
  const installation = edition === 'free'
    ? 'Activate WooCommerce, then install and activate the Free ZIP.'
    : 'Activate WooCommerce and the compatible Free edition, then install and activate the Pro ZIP.';
  deliver(`${folder}/README.md`, `# ${title}\n\n${description}\n\n## Development installation\n\n${installation}\n`);
  if (edition === 'free') {
    deliver(`${folder}/readme.txt`, `=== ${title} ===\nRequires at least: ${metadata.requires_wp}\nRequires PHP: ${metadata.requires_php}\nStable tag: ${metadata.version}\nLicense: ${metadata.license}\n\n${description}\n\n== Description ==\n\n${description}\n\n== Installation ==\n\n${installation}\n`);
  }
}
