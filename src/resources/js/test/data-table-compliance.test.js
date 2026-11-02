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

describe('DataTable Static Compliance in Sales Pages (Butir 1)', () => {
    it('requires DataTable in Pages/Sales to use :data, prohibits :items and empty-text, and prohibits { item } on #cell slots', () => {
        const vueFiles = getVueFiles(salesPagesDir);
        const violations = [];

        // Match <DataTable ... > ... </DataTable> or <DataTable ... />
        const dataTableTagRegex = /<DataTable\b([\s\S]*?)<\/DataTable>/g;

        for (const file of vueFiles) {
            const content = fs.readFileSync(file, 'utf-8');
            let match;
            while ((match = dataTableTagRegex.exec(content)) !== null) {
                const fullBlock = match[0];
                const openingTagMatch = fullBlock.match(/<DataTable\b([^>]*)>/);
                const openingTagAttrs = openingTagMatch ? openingTagMatch[1] : '';
                const relativePath = path.relative(salesPagesDir, file);

                // 1. Must have :data=
                const hasDataProp = /:data\s*=/i.test(openingTagAttrs);
                // 2. Must not have :items= or items=
                const hasItemsProp = /:?items\s*=/i.test(openingTagAttrs);
                // 3. Must not have empty-text=
                const hasEmptyText = /empty-text\s*=/i.test(openingTagAttrs);
                // 4. Must not have { item } on #cell( slot
                const hasCellItemSlot = /#cell\([^)]*\)\s*=\s*['"]\s*\{\s*item\b/i.test(fullBlock);

                if (!hasDataProp || hasItemsProp || hasEmptyText || hasCellItemSlot) {
                    violations.push({
                        file: relativePath,
                        hasDataProp,
                        hasItemsProp,
                        hasEmptyText,
                        hasCellItemSlot,
                    });
                }
            }
        }

        expect(violations).toEqual([]);
    });
});
