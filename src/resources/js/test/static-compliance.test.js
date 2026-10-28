import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const jsRoot = path.resolve(__dirname, '..');

function getAllFiles(dir, extensions = ['.vue', '.js']) {
    let results = [];
    const list = fs.readdirSync(dir, { withFileTypes: true });
    for (const item of list) {
        const fullPath = path.join(dir, item.name);
        if (item.isDirectory()) {
            results = results.concat(getAllFiles(fullPath, extensions));
        } else if (extensions.some((ext) => item.name.endsWith(ext))) {
            results.push(fullPath);
        }
    }
    return results;
}

const allFiles = getAllFiles(jsRoot);
const vueFiles = allFiles.filter((f) => f.endsWith('.vue'));
const nonTestFiles = allFiles.filter((f) => !f.includes('/test/') && !f.includes('__tests__'));

describe('Frontend Static Compliance & Security Audits', () => {
    it('prohibits v-html across all Vue SFC files (XSS prevention)', () => {
        const violations = [];
        for (const file of vueFiles) {
            const content = fs.readFileSync(file, 'utf-8');
            if (/\bv-html\b/.test(content)) {
                violations.push(path.relative(jsRoot, file));
            }
        }
        expect(violations).toEqual([]);
    });

    it('prohibits static style attributes in all Vue SFC files (strict CSP compliance)', () => {
        const violations = [];
        for (const file of vueFiles) {
            const content = fs.readFileSync(file, 'utf-8');
            // Matches style="..." but not :style="..." or v-bind:style="..."
            if (/(?<![:\w-])style\s*=\s*["'][^"']*["']/i.test(content)) {
                violations.push(path.relative(jsRoot, file));
            }
        }
        expect(violations).toEqual([]);
    });

    it('prohibits SFC <style> tags (strictly utility classes & global theme)', () => {
        const violations = [];
        for (const file of vueFiles) {
            const content = fs.readFileSync(file, 'utf-8');
            if (/<\s*style\b/i.test(content)) {
                violations.push(path.relative(jsRoot, file));
            }
        }
        expect(violations).toEqual([]);
    });

    it('prohibits external URLs in application template and code', () => {
        const violations = [];
        // Matches http(s):// unless pointing to localhost, 127.0.0.1, RFC 2606 example.com, or W3C SVG xmlns
        const externalUrlRegex = /https?:\/\/(?!(?:localhost|127\.0\.0\.1|example\.com|www\.w3\.org)[^\s"'`]*)[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}[^\s"'`]*/g;

        for (const file of nonTestFiles) {
            const content = fs.readFileSync(file, 'utf-8');
            const lines = content.split('\n');
            lines.forEach((line, idx) => {
                // Ignore comments
                const trimmed = line.trim();
                if (trimmed.startsWith('//') || trimmed.startsWith('/*') || trimmed.startsWith('*')) {
                    return;
                }
                const match = line.match(externalUrlRegex);
                if (match) {
                    violations.push(`${path.relative(jsRoot, file)}:${idx + 1} -> ${match[0]}`);
                }
            });
        }
        expect(violations).toEqual([]);
    });

    it('prohibits imports from third-party CDN providers', () => {
        const violations = [];
        const cdnRegex = /(?:from|import)\s+['"][^'"]*(?:unpkg|cdnjs|jsdelivr|esm\.sh|skypack|cdn\.)/i;

        for (const file of allFiles) {
            const content = fs.readFileSync(file, 'utf-8');
            if (cdnRegex.test(content)) {
                violations.push(path.relative(jsRoot, file));
            }
        }
        expect(violations).toEqual([]);
    });
});
