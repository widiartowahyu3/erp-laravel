import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const salesPagesDir = path.resolve(__dirname, '../Pages/Sales');

function getVueFiles(dir) {
    let results = [];
    const list = fs.readdirSync(dir, { withFileTypes: true });
    for (const item of list) {
        const fullPath = path.join(dir, item.name);
        if (item.isDirectory()) {
            results = results.concat(getVueFiles(fullPath));
        } else if (item.name.endsWith('.vue')) {
            results.push(fullPath);
        }
    }
    return results;
}

describe('StatusBadge Static Compliance in Sales Pages (Butir 1)', () => {
    it('requires StatusBadge in Pages/Sales to use :status and prohibits :label and :variant', () => {
        const vueFiles = getVueFiles(salesPagesDir);
        const violations = [];

        // Match complete <StatusBadge ... /> or <StatusBadge ...>...</StatusBadge> tags across multiple lines
        const badgeTagRegex = /<StatusBadge\b([^>]*)(?:\/>|>[\s\S]*?<\/StatusBadge>)/g;

        for (const file of vueFiles) {
            const content = fs.readFileSync(file, 'utf-8');
            let match;
            while ((match = badgeTagRegex.exec(content)) !== null) {
                const tagAttrs = match[1];
                const relativePath = path.relative(salesPagesDir, file);

                // 1. Must have :status or status
                const hasStatus = /[:\b]status\s*=/i.test(tagAttrs);
                // 2. Must not have :label or label
                const hasLabel = /[:\b]label\s*=/i.test(tagAttrs);
                // 3. Must not have :variant or variant
                const hasVariant = /[:\b]variant\s*=/i.test(tagAttrs);

                if (!hasStatus || hasLabel || hasVariant) {
                    violations.push({
                        file: relativePath,
                        hasStatus,
                        hasLabel,
                        hasVariant,
                        snippet: match[0].trim(),
                    });
                }
            }
        }

        expect(violations).toEqual([]);
    });
});
