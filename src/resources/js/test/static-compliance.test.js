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

    // Heuristic: it reads one file at a time, so a page whose h2 comes from a child component is not understood.
    it('requires card header titles in Pages/** to start at h2, not h3', () => {
        const violations = [];
        const pageFiles = getAllFiles(path.resolve(jsRoot, 'Pages'), ['.vue']);
        for (const file of pageFiles) {
            const content = fs.readFileSync(file, 'utf-8');
            for (const match of content.matchAll(/<template #header>([\s\S]*?)<\/template>/g)) {
                const hasH3 = /<h3\b/.test(match[1]);
                const h2Before = /<h2\b/.test(content.slice(0, match.index));
                if (hasH3 && !h2Before) {
                    violations.push(path.relative(jsRoot, file));
                }
            }
        }
        console.info(`card header h3 violations: ${violations.length}`);
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

    it('ensures navigation config has available flag and no phase badges', async () => {
        const { navigationGroups } = await import('../config/navigation.js');
        const items = navigationGroups.flatMap((g) => g.items);

        for (const item of items) {
            expect(typeof item.available).toBe('boolean');
            if (item.badge) {
                expect(item.badge).not.toMatch(/Fase/i);
                expect(item.badge).not.toMatch(/Belum Aktif/i);
            }
        }

        const availableItems = items.filter((i) => i.available);
        const availableNames = availableItems.map((i) => i.name);
        expect(availableNames).toContain('Dashboard');
        expect(availableNames).toContain('Produk');
        expect(availableNames).toContain('Kategori Produk');
        expect(availableNames).toContain('Satuan Ukuran');
        expect(availableNames).toContain('Pemasok');
        expect(availableNames).toContain('Pelanggan');
        expect(availableNames).toContain('Gudang');
        expect(availableNames).toContain('Tarif Pajak');
        expect(availableNames).toContain('Syarat Pembayaran');
        expect(availableNames).toContain('Saldo Stok');
        expect(availableNames).toContain('Mutasi Stok');
        expect(availableNames).toContain('Kartu Stok');
        expect(availableNames).toContain('Penyesuaian Stok');
        expect(availableNames).toContain('Bagan Akun (COA)');
        expect(availableNames).toContain('Jurnal Akuntansi');
        expect(availableNames).toContain('Pesanan Pembelian (PO)');
        expect(availableNames).toContain('Penerimaan Barang (GR)');
        expect(availableNames).toContain('Pesanan Penjualan (SO)');
        expect(availableNames).toContain('Surat Jalan / Kirim (DO)');
        expect(availableNames).toContain('Faktur Penjualan (SI)');
        expect(availableNames).toContain('Pengguna');
        expect(availableNames).toContain('Peran & Hak Akses');
        expect(availableNames).toContain('Log Audit');
        expect(availableNames).not.toContain('Pengaturan Sistem');
        expect(availableItems.length).toBe(23);
    });

    it('ensures PurchaseOrders/Form.vue renders discount_amount error per item row', () => {
        const formPath = path.resolve(jsRoot, 'Pages/Purchasing/PurchaseOrders/Form.vue');
        const content = fs.readFileSync(formPath, 'utf-8');
        expect(content).toContain('form.errors[`items.${index}.discount_amount`]');
    });

    it('ensures every page in Pages/** that mutates data via router or form uses useSubmitLock', () => {
        const pagesDir = path.resolve(jsRoot, 'Pages');
        const pageFiles = getAllFiles(pagesDir, ['.vue']);
        const mutationRegex = /\b(?:router\.(?:post|put|delete|patch)|form\.(?:post|put|delete|patch))\s*\(/;

        const mutatingPages = pageFiles.filter((file) => {
            const content = fs.readFileSync(file, 'utf-8');
            return mutationRegex.test(content);
        });

        // Ensure we discovered all mutating pages (at least 24 pages)
        expect(mutatingPages.length).toBeGreaterThanOrEqual(24);

        const violations = [];
        for (const file of mutatingPages) {
            const content = fs.readFileSync(file, 'utf-8');
            const hasImport = content.includes('useSubmitLock');
            const hasInvoke = /useSubmitLock\s*\(/.test(content);
            if (!hasImport || !hasInvoke) {
                violations.push(path.relative(jsRoot, file));
            }
        }

        expect(violations).toEqual([]);
    });

    it('ensures no files in Pages/** contain goods-receipts/create?po_id', () => {
        const pagesDir = path.resolve(jsRoot, 'Pages');
        const pageFiles = getAllFiles(pagesDir, ['.vue', '.js']);
        const violations = [];
        for (const file of pageFiles) {
            const content = fs.readFileSync(file, 'utf-8');
            if (content.includes('goods-receipts/create?po_id')) {
                violations.push(path.relative(jsRoot, file));
            }
        }
        expect(violations).toEqual([]);
    });

    it('ensures forbidden UI terminology does not appear across Pages/**', () => {
        const pagesDir = path.resolve(jsRoot, 'Pages');
        const pageFiles = getAllFiles(pagesDir, ['.vue', '.js']);
        const forbiddenPatterns = [
            /mitra\s+pemasok/i,
            /fasilitas\s+gudang/i,
            /barang\s+dagang/i,
            /gudang\s+tujuan/i,
            /produk\s+dagang/i,
        ];

        const violations = [];
        for (const file of pageFiles) {
            const content = fs.readFileSync(file, 'utf-8');
            for (const pattern of forbiddenPatterns) {
                if (pattern.test(content)) {
                    violations.push(`${path.relative(jsRoot, file)} matches ${pattern}`);
                }
            }
        }
        expect(violations).toEqual([]);
    });
});
