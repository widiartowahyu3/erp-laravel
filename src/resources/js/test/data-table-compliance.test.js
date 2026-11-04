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

describe('DataTable and FilterBar contract in every page', () => {
    const pages = getVueFiles(path.resolve(__dirname, '../Pages')).filter((file) => !file.includes('/Dev/'));

    it('binds DataTable rows through :data', () => {
        const violations = [];
        for (const file of pages) {
            const content = fs.readFileSync(file, 'utf-8');
            for (const match of content.matchAll(/<DataTable\b([^>]*)>/g)) {
                if (!/:data\s*=/.test(match[1]) || /:rows\s*=|:empty\s*=/.test(match[1])) {
                    violations.push(path.relative(path.resolve(__dirname, '../Pages'), file));
                }
            }
        }
        expect(violations).toEqual([]);
    });

    it('puts FilterBar filters in its default slot and names its placeholder prop correctly', () => {
        const violations = [];
        for (const file of pages) {
            const content = fs.readFileSync(file, 'utf-8');
            for (const match of content.matchAll(/<FilterBar\b([^>]*)>([\s\S]*?)<\/FilterBar>/g)) {
                if (/\splaceholder\s*=/.test(match[1]) || /<template #filters>/.test(match[2])) {
                    violations.push(path.relative(path.resolve(__dirname, '../Pages'), file));
                }
            }
        }
        expect(violations).toEqual([]);
    });
});
