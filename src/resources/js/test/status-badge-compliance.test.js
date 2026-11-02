import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const salesPagesDir = path.resolve(__dirname, '../Pages/Sales');
const grShowFile = path.resolve(__dirname, '../Pages/Purchasing/GoodsReceipts/Show.vue');

export function checkStatusBadgeAttrs(tagAttrs) {
    // Mewajibkan :status="..." (atau :status='...')
    const hasStatus = /(?:^|\s):status\s*=/i.test(tagAttrs);
    // Mendeteksi label="..." atau :label="..."
    const hasLabel = /(?:^|\s):?label\s*=/i.test(tagAttrs);
    // Mendeteksi variant="..." atau :variant="..."
    const hasVariant = /(?:^|\s):?variant\s*=/i.test(tagAttrs);

    return {
        isValid: hasStatus && !hasLabel && !hasVariant,
        hasStatus,
        hasLabel,
        hasVariant,
    };
}

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

describe('StatusBadge Static Compliance (Butir 3)', () => {
    it('detects violations in prohibited attribute examples (detector self-test)', () => {
        // Missing :status, has static label
        const r1 = checkStatusBadgeAttrs('label="Draf"');
        expect(r1.isValid).toBe(false);
        expect(r1.hasLabel).toBe(true);
        expect(r1.hasStatus).toBe(false);

        // Has :label and :variant alongside :status
        const r2 = checkStatusBadgeAttrs(':status="status" :label="label" :variant="variant"');
        expect(r2.isValid).toBe(false);
        expect(r2.hasStatus).toBe(true);
        expect(r2.hasLabel).toBe(true);
        expect(r2.hasVariant).toBe(true);

        // Static label and variant
        const r3 = checkStatusBadgeAttrs(':status="status" label="Draf" variant="neutral"');
        expect(r3.isValid).toBe(false);
        expect(r3.hasLabel).toBe(true);
        expect(r3.hasVariant).toBe(true);

        // Unbound status attribute (status="..." without colon)
        const r4 = checkStatusBadgeAttrs('status="draft"');
        expect(r4.isValid).toBe(false);
        expect(r4.hasStatus).toBe(false);

        // Valid compliant usage
        const compliant = checkStatusBadgeAttrs(':status="order.status" size="sm"');
        expect(compliant.isValid).toBe(true);
        expect(compliant.hasStatus).toBe(true);
        expect(compliant.hasLabel).toBe(false);
        expect(compliant.hasVariant).toBe(false);
    });

    it('requires StatusBadge in Pages/Sales to use :status and prohibits :label and :variant', () => {
        const vueFiles = getVueFiles(salesPagesDir);
        const violations = [];

        const badgeTagRegex = /<StatusBadge\b([^>]*)(?:\/>|>[\s\S]*?<\/StatusBadge>)/g;

        for (const file of vueFiles) {
            const content = fs.readFileSync(file, 'utf-8');
            let match;
            while ((match = badgeTagRegex.exec(content)) !== null) {
                const tagAttrs = match[1];
                const relativePath = path.relative(salesPagesDir, file);
                const check = checkStatusBadgeAttrs(tagAttrs);

                if (!check.isValid) {
                    violations.push({
                        file: relativePath,
                        ...check,
                        snippet: match[0].trim(),
                    });
                }
            }
        }

        expect(violations).toEqual([]);
    });

    it('requires StatusBadge in Pages/Purchasing/GoodsReceipts/Show.vue to use :status without :label and :variant', () => {
        const content = fs.readFileSync(grShowFile, 'utf-8');
        const violations = [];
        const badgeTagRegex = /<StatusBadge\b([^>]*)(?:\/>|>[\s\S]*?<\/StatusBadge>)/g;

        let match;
        while ((match = badgeTagRegex.exec(content)) !== null) {
            const tagAttrs = match[1];
            const check = checkStatusBadgeAttrs(tagAttrs);

            if (!check.isValid) {
                violations.push({
                    file: 'Pages/Purchasing/GoodsReceipts/Show.vue',
                    ...check,
                    snippet: match[0].trim(),
                });
            }
        }

        expect(violations).toEqual([]);
    });
});
