// @vitest-environment node
import { readFile, readdir } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import postcss, { type Root, type Rule } from 'postcss';
import tailwindcss from '@tailwindcss/postcss';
import { JSDOM } from 'jsdom';
import { beforeAll, describe, expect, it } from 'vitest';

const stylesheetPath = fileURLToPath(new URL('../css/app.css', import.meta.url));
let source: string;
let stylesheet: Root;

function rules(selector: string): Rule[] {
    const matches: Rule[] = [];
    stylesheet.walkRules((rule) => {
        if (postcss.list.comma(rule.selector).includes(selector)) matches.push(rule);
    });
    return matches;
}

function values(selector: string, property: string): string[] {
    return rules(selector).flatMap((rule) => rule.nodes.flatMap((node) => (
        node.type === 'decl' && node.prop === property ? [node.value] : []
    )));
}

async function vueSources(directory: URL): Promise<string[]> {
    const sources: string[] = [];
    for (const entry of await readdir(directory, { withFileTypes: true })) {
        const url = new URL(entry.name + (entry.isDirectory() ? '/' : ''), directory);
        if (entry.isDirectory()) sources.push(...await vueSources(url));
        else if (entry.name.endsWith('.vue')) sources.push(await readFile(url, 'utf8'));
    }
    return sources;
}

describe('native Tailwind 4 application stylesheet', () => {
    beforeAll(async () => {
        source = await readFile(stylesheetPath, 'utf8');
        const result = await postcss([tailwindcss({ optimize: { minify: false } })]).process(
            `${source}\n@source inline("shadow-xs shadow-sm rounded-xs rounded-sm blur-xs blur-sm backdrop-blur-xs backdrop-blur-sm drop-shadow-xs drop-shadow-sm ring outline-hidden text-white bg-slate-950 bg-white/80 hover:bg-slate-100 dark:bg-white/5 dark:hover:bg-white/10 gap-2 gap-3 gap-4");`,
            { from: stylesheetPath },
        );
        stylesheet = result.root;
    }, 30000);

    it('scans tracked application sources and excludes tests', async () => {
        expect(source).toContain('@import "tailwindcss" source(none)');
        expect(source).toContain('@source "../js/**/*.{js,ts,vue}"');
        expect(source).toContain('@source not "../js/**/*.test.ts"');
        expect(source).not.toContain('node_modules');
        expect(await readFile(new URL('./Pages/BackupGroups/Form.vue', import.meta.url), 'utf8')).toContain('grid-cols-2');
        expect(rules('.grid-cols-2').length).toBeGreaterThan(0);
        expect(rules('.p-\\[9876px\\]').length).toBe(0); // p-[9876px]
        expect(stylesheet.toString()).not.toMatch(/@apply\b|@source\b|@theme\b/);
    });

    it('defines only the application font without overriding native theme scales', () => {
        const theme = postcss.parse(source).nodes.find((node) => node.type === 'atrule' && node.name === 'theme');
        expect(theme?.type).toBe('atrule');
        if (theme?.type === 'atrule') {
            expect(theme.nodes?.filter((node) => node.type === 'decl').map((node) => node.prop)).toEqual(['--font-sans']);
        }
        expect(source).toContain('--font-sans: Figtree');
        expect(source).not.toMatch(/legacy-|--default-ring-|@utility space-|@layer utilities/);
    });

    it('uses the native shadow, radius and filter utility scales', () => {
        expect(values('.shadow-xs', '--tw-shadow').join(' ')).toContain('0 1px 2px 0');
        expect(values('.shadow-sm', '--tw-shadow').join(' ')).toContain('0 1px 3px 0');
        expect(values('.rounded-xs', 'border-radius')).toContain('var(--radius-xs)');
        expect(values('.rounded-sm', 'border-radius')).toContain('var(--radius-sm)');
        expect(values('.blur-xs', '--tw-blur')).toContain('blur(var(--blur-xs))');
        expect(values('.blur-sm', '--tw-blur')).toContain('blur(var(--blur-sm))');
        expect(values('.backdrop-blur-xs', '--tw-backdrop-blur')).toContain('blur(var(--blur-xs))');
        expect(values('.backdrop-blur-sm', '--tw-backdrop-blur')).toContain('blur(var(--blur-sm))');
        expect(values('.drop-shadow-xs', '--tw-drop-shadow-size').join(' ')).toContain('0 1px 1px');
        expect(values('.drop-shadow-sm', '--tw-drop-shadow-size').join(' ')).toContain('0 1px 2px');
    });

    it('leaves standard color utilities with their native meaning', () => {
        expect(values('.text-white', 'color')).toEqual(['var(--color-white)']);
        expect(values('.bg-slate-950', 'background-color')).toEqual(['var(--color-slate-950)']);
        expect(source).not.toMatch(/\.text-white\b|\.bg-slate-950\b|:where\(\.dark\)\s/);
        expect(source).not.toContain('border-color: var(--color-gray-200');
        expect(source).not.toContain('input::placeholder');
        expect(source).not.toContain('button:not(:disabled)');
        expect(source).not.toContain('dialog {');
    });

    it('uses accessible native outlines and explicit focus ring widths', () => {
        const hidden = rules('.outline-hidden');
        expect(hidden.length).toBeGreaterThan(0);
        expect(stylesheet.toString()).toContain('forced-colors: active');
        expect(values('.input', 'outline-style')).toContain('none');
        expect(values('.input:focus', '--tw-ring-shadow').join(' ')).toContain('2px');
        expect(values('.btn-primary:focus', '--tw-ring-shadow').join(' ')).toContain('2px');
        expect(values('.ring', '--tw-ring-shadow').join(' ')).toContain('1px');
    });

    it('keeps shared button sizing and disabled behavior', () => {
        for (const selector of ['.btn-primary', '.btn-secondary', '.btn-danger']) {
            expect(values(selector, 'display')).toContain('inline-flex');
            expect(values(selector, 'border-radius')).toContain('var(--radius-xl)');
            expect(values(selector, 'white-space')).toContain('normal');
            expect(values(`${selector}:disabled`, 'cursor')).toContain('not-allowed');
            expect(values(`${selector}:disabled`, 'opacity')).toContain('.5');
        }
        expect(values('.btn-secondary', '--tw-shadow').join(' ')).toContain('0 1px 2px 0');
        expect(values('.card', '--tw-backdrop-blur')).toContain('blur(var(--blur-sm))');
    });

    it('declares readable button palettes and hover colors in both themes', () => {
        expect(values('.btn-primary', 'color')).toContain('var(--color-sky-700)');
        expect(values('.btn-primary:hover', 'color')).toContain('var(--color-sky-800)');
        expect(values('.btn-primary:where(.dark, .dark *)', 'color')).toContain('var(--color-sky-100)');
        expect(values('.btn-primary:where(.dark, .dark *):hover', 'color')).toContain('var(--color-sky-50)');
        expect(values('.btn-danger', 'color')).toContain('var(--color-white)');
        expect(values('.btn-danger', 'background-color')).toContain('var(--color-rose-600)');
    });

    it('keeps class-based dark components and the viewport-fixed shared background', async () => {
        expect(values('.input:where(.dark, .dark *)', 'color')).toContain('var(--color-slate-100)');
        expect(values('.app-shell', 'isolation')).toContain('isolate');
        expect(values('.app-shell', 'background-image')).toEqual([]);
        expect(values('.app-shell:before', 'position')).toContain('fixed');
        expect(values('.app-shell:before', 'inset')).toContain('0');
        expect(values('.app-shell:before', 'pointer-events')).toContain('none');
        expect(values('.app-shell:before', 'z-index')).toContain('calc(10 * -1)');
        expect(values('.app-shell:where(.dark, .dark *):before', 'background-image').join(' ')).toContain('#020617');
        expect(stylesheet.toString()).not.toContain('prefers-color-scheme');
        for (const page of ['Dashboard.vue', 'Volumes/Index.vue', 'Stacks/Index.vue', 'Changelog/Index.vue']) {
            expect(await readFile(new URL(`./Pages/${page}`, import.meta.url), 'utf8')).toContain('<AppLayout');
        }
    });

    it('uses native theme variants and hover-capable media queries', () => {
        expect(values('.bg-white\\/80', 'background-color').join(' ')).toContain('color-mix');
        expect(values('.dark\\:bg-white\\/5:where(.dark, .dark *)', 'background-color').join(' ')).toContain('5%');
        const hover = rules('.hover\\:bg-slate-100:hover')[0];
        expect(hover).toBeDefined();
        expect(hover.parent?.type).toBe('atrule');
        if (hover.parent?.type === 'atrule') expect(hover.parent.params).toContain('hover: hover');
    });

    it('retains responsive breakpoints', () => {
        for (const [selector, breakpoint] of [['.sm\\:items-center', '40rem'], ['.md\\:hidden', '48rem'], ['.lg\\:hidden', '64rem']]) {
            const rule = rules(selector)[0];
            expect(rule).toBeDefined();
            expect(rule.parent?.type).toBe('atrule');
            if (rule.parent?.type === 'atrule') expect(rule.parent.params).toContain(breakpoint);
        }
    });

    it('compiles native gaps independently for nested form layouts', () => {
        for (const size of [2, 3, 4]) {
            expect(values(`.gap-${size}`, 'gap')).toContain(`calc(var(--spacing) * ${size})`);
        }
        expect(source).not.toContain('@utility space-y-');
    });

    it('lays out real user form labels with native gaps, including conditional errors', async () => {
        const page = await readFile(new URL('./Pages/Users/Form.vue', import.meta.url), 'utf8');
        const template = page.slice(page.indexOf('<template>') + '<template>'.length, page.lastIndexOf('</template>'));
        const document = new JSDOM(template).window.document;
        const labels = [...document.querySelectorAll('label')];
        expect(labels.length).toBe(6);
        for (const label of labels) {
            expect(label.classList.contains('flex')).toBe(true);
            expect(label.classList.contains('flex-col')).toBe(true);
            expect(label.classList.contains('gap-2')).toBe(true);
            expect(label.className).not.toContain('space-y-');
        }
        expect(document.querySelector('span[v-if="form.errors.name"]')).not.toBeNull();
    });

    it('does not use compatibility aliases or deprecated important prefixes in Vue sources', async () => {
        const sources = await vueSources(new URL('./', import.meta.url));
        for (const vue of sources) {
            expect(vue).not.toContain('legacy-outline-none');
            expect(vue).not.toMatch(/(?:class="|\s|:)![a-z]+-/);
        }
    });
});
