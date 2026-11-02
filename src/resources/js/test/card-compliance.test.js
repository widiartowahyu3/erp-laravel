import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const pagesDir = path.resolve(__dirname, '../Pages');

export function checkCardContent(content) {
    // 1. Remove PageHeader blocks since PageHeader legitimately has #title and #description slots
    const strippedPageHeaders = content.replace(/<PageHeader\b[\s\S]*?<\/PageHeader>/g, '');

    // 2. Find all <Card ... > ... </Card> blocks
    const cardRegex = /<Card\b[\s\S]*?<\/Card>/g;
    const matches = strippedPageHeaders.match(cardRegex) || [];

    const violations = [];
    for (const cardBlock of matches) {
        const hasTitleSlot = /<template\s+(?:#[^\s>]*title|v-slot:[^\s>]*title)\b/i.test(cardBlock);
        const hasDescriptionSlot = /<template\s+(?:#[^\s>]*description|v-slot:[^\s>]*description)\b/i.test(cardBlock);

        if (hasTitleSlot || hasDescriptionSlot) {
            violations.push({
                hasTitleSlot,
                hasDescriptionSlot,
                snippet: cardBlock.slice(0, 150).replace(/\s+/g, ' '),
            });
        }
    }

    return {
        isValid: violations.length === 0,
        violations,
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

describe('Card Static Compliance in Pages (Butir 2)', () => {
    it('detects violations when Card uses template #title or #description (detector self-test)', () => {
        // Legitimate Card with #header slot
        const validHeaderCard = `
            <Card>
                <template #header>
                    <h3>Valid Header</h3>
                    <p>Valid description</p>
                </template>
                <div>Card body</div>
            </Card>
        `;
        expect(checkCardContent(validHeaderCard).isValid).toBe(true);

        // Legitimate PageHeader with #title and #description outside Card
        const validPageHeader = `
            <PageHeader title="Test">
                <template #title>Custom Title</template>
                <template #description>Custom Description</template>
            </PageHeader>
            <Card><div>Body</div></Card>
        `;
        expect(checkCardContent(validPageHeader).isValid).toBe(true);

        // Violating Card with #title
        const invalidTitleCard = `
            <Card>
                <template #title>Card Title</template>
                <div>Body</div>
            </Card>
        `;
        const resTitle = checkCardContent(invalidTitleCard);
        expect(resTitle.isValid).toBe(false);
        expect(resTitle.violations[0].hasTitleSlot).toBe(true);

        // Violating Card with #description
        const invalidDescCard = `
            <Card>
                <template #description>Card Description</template>
                <div>Body</div>
            </Card>
        `;
        const resDesc = checkCardContent(invalidDescCard);
        expect(resDesc.isValid).toBe(false);
        expect(resDesc.violations[0].hasDescriptionSlot).toBe(true);

        // Violating Card with v-slot:title syntax
        const invalidVSlotCard = `
            <Card>
                <template v-slot:title>Card Title</template>
                <div>Body</div>
            </Card>
        `;
        expect(checkCardContent(invalidVSlotCard).isValid).toBe(false);
    });

    it('requires all Card components across Pages/** to avoid #title and #description slots', () => {
        const vueFiles = getVueFiles(pagesDir);
        const fileViolations = [];

        for (const file of vueFiles) {
            const content = fs.readFileSync(file, 'utf-8');
            const result = checkCardContent(content);
            if (!result.isValid) {
                fileViolations.push({
                    file: path.relative(pagesDir, file),
                    count: result.violations.length,
                    violations: result.violations,
                });
            }
        }

        expect(fileViolations).toEqual([]);
    });
});
