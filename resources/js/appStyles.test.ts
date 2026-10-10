// @vitest-environment node
import { readFile } from 'node:fs/promises';
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
        if (postcss.list.comma(rule.selector).includes(selector)) {
            matches.push(rule);
        }
    });
    return matches;
}

function values(selector: string, property: string): string[] {
    return rules(selector).flatMap((rule) => rule.nodes.flatMap((node) => (
        node.type === 'decl' && node.prop === property ? [node.value] : []
    )));
}

function themeValue(property: string): string | undefined {
    let value: string | undefined;
    stylesheet.walkDecls(property, (declaration) => {
        if (declaration.parent?.type === 'rule' && declaration.parent.selector.includes(':root')) {
            value = declaration.value;
        }
    });
    return value;
}

function layerName(rule: Rule): string | undefined {
    let parent = rule.parent;
    while (parent) {
        if (parent.type === 'atrule' && parent.name === 'layer') {
            return parent.params;
        }
        parent = parent.parent;
    }
}

// Evaluate the background cascade for this single-class utility fixture on a
// hover-capable screen. :where() adds no specificity; :hover adds one class unit.
function actionIconBackground(dark: boolean, hovered: boolean): string | undefined {
    const document = new JSDOM(`<html class="${dark ? 'dark' : ''}"><body><button class="bg-white/5 hover:bg-slate-100 dark:hover:bg-white/10" ${hovered ? 'data-hover' : ''}></button></body></html>`).window.document;
    const button = document.querySelector('button')!;
    const layerOrder = ['theme', 'base', 'components', 'utilities'];
    let winner: { layer: number; specificity: number; value: string } | undefined;

    stylesheet.walkRules((rule) => {
        for (const selector of postcss.list.comma(rule.selector)) {
            if (!selector.includes('bg-white\\/5') && !selector.includes('hover\\:bg-slate-100') && !selector.includes('hover\\:bg-white\\/10')) {
                continue;
            }
            if (!button.matches(selector.replace(/(?<!\\):hover/g, '[data-hover]'))) {
                continue;
            }
            const unescaped = selector.replace(/:where\([^)]*\)/g, '').replace(/\\./g, '');
            const specificity = (unescaped.match(/\.[\w-]+|:[\w-]+/g) ?? []).length;
            const layer = layerOrder.indexOf(layerName(rule) ?? '') === -1 ? layerOrder.length : layerOrder.indexOf(layerName(rule)!);
            rule.walkDecls('background-color', (declaration) => {
                if (!winner || layer > winner.layer || (layer === winner.layer && specificity >= winner.specificity)) {
                    winner = { layer, specificity, value: declaration.value };
                }
            });
        }
    });
    return winner?.value;
}

describe('application Tailwind 4 stylesheet', () => {
    beforeAll(async () => {
        source = await readFile(stylesheetPath, 'utf8');
        // Compile the real stylesheet and its real Blade, Vue and TypeScript sources.
        // Extra candidates only exercise compatibility aliases not used on every page.
        const result = await postcss([tailwindcss({ optimize: { minify: false } })]).process(
            `${source}\n@source inline("shadow shadow-sm rounded rounded-sm blur blur-sm backdrop-blur backdrop-blur-sm drop-shadow drop-shadow-sm ring outline");`,
            { from: stylesheetPath },
        );
        stylesheet = result.root;
    }, 30000);

    it('uses explicit application sources, including TypeScript, without scanning dependencies or tests', () => {
        expect(source).toContain('@import "tailwindcss" source(none)');
        expect(source).toContain('@source "../js/**/*.{js,ts,vue}"');
        expect(source).toContain('@source not "../js/**/*.test.ts"');
        expect(source).not.toContain('node_modules');
        expect(rules('.fixed').length).toBeGreaterThan(0);
        expect(rules('.grid-cols-1').length).toBeGreaterThan(0);
        // This candidate exists only in an excluded test file, never in application sources.
        expect(rules('.p-\\[9876px\\]').length).toBe(0); // p-[9876px]
        expect(stylesheet.toString()).not.toMatch(/@apply\b|@source\b|@theme\b/);
    });

    it('retains Figtree and the v3 shadow, radius and blur scales', () => {
        expect(themeValue('--font-sans')).toMatch(/^Figtree,/);
        expect(values('.shadow', '--tw-shadow').join(' ')).toContain('0 1px 3px 0');
        expect(values('.shadow-sm', '--tw-shadow').join(' ')).toContain('0 1px 2px 0');
        expect(themeValue('--radius')).toBe('.25rem');
        expect(themeValue('--radius-sm')).toBe('.125rem');
        expect(values('.rounded', 'border-radius')).toContain('var(--radius)');
        expect(values('.rounded-sm', 'border-radius')).toContain('var(--radius-sm)');
        expect(themeValue('--blur')).toBe('8px');
        expect(themeValue('--blur-sm')).toBe('4px');
        expect(values('.blur', '--tw-blur')).toContain('blur(var(--blur))');
        expect(values('.blur-sm', '--tw-blur')).toContain('blur(var(--blur-sm))');
        expect(values('.backdrop-blur-sm', '--tw-backdrop-blur')).toContain('blur(var(--blur-sm))');
        expect(values('.drop-shadow-sm', '--tw-drop-shadow-size').join(' ')).toContain('0 1px 1px');
        expect(values('.drop-shadow', '--tw-drop-shadow-size').join(' ')).toContain('0 1px 2px');
    });

    it('keeps the v3 default ring and accessible transparent outlines', () => {
        expect(values('.ring', '--tw-ring-shadow').join(' ')).toContain('3px');
        expect(values('.ring', '--tw-ring-shadow').join(' ')).toContain('#3b82f680');
        expect(values('.outline', 'outline-width').at(-1)).toBe('2px');
        const outlineRules: Rule[] = [];
        stylesheet.walkRules((rule) => {
            if (rule.selector.includes('.outline-none') || rule.selector.includes('.focus\\:outline-none:focus')) {
                outlineRules.push(rule);
            }
        });
        expect(outlineRules.at(-1)?.toString()).toContain('outline: 2px solid #0000');
        expect(outlineRules.at(-1)?.toString()).toContain('outline-offset: 2px');
        expect(values('.input', 'outline')).toContain('2px solid #0000');
        expect(values('.btn-primary:focus', 'outline')).toContain('2px solid #0000');
    });

    it('expands the shared button utility for each component and its disabled state', () => {
        for (const selector of ['.btn-primary', '.btn-secondary', '.btn-danger']) {
            expect(values(selector, 'display')).toContain('inline-flex');
            expect(values(selector, 'border-radius')).toContain('var(--radius-xl)');
            expect(values(selector, 'white-space')).toContain('normal');
            expect(values(`${selector}:disabled`, 'cursor')).toContain('not-allowed');
            expect(values(`${selector}:disabled`, 'opacity')).toContain('.5');
        }
        expect(values('.btn-secondary', '--tw-shadow').join(' ')).toContain('0 1px 2px 0');
        expect(values('.card', '--tw-backdrop-blur')).toContain('blur(var(--blur))');
        expect(values('.input:focus', '--tw-ring-shadow').join(' ')).toContain('2px');
    });

    it('preserves the applied button text palette in light and dark themes', () => {
        expect(values('.btn-primary', 'color').at(-1)).toBe('#0369a1');
        expect(values('.btn-primary:where(.dark, .dark *)', 'color').at(-1)).toBe('#e0f2fe');
        expect(values('.btn-danger', 'color').at(-1)).toBe('#0f172a');
        expect(values('.btn-danger:where(.dark, .dark *)', 'color').at(-1)).toBe('#fff');
    });

    it('uses class-based dark components rather than system color-scheme media queries', () => {
        expect(values('.input:where(.dark, .dark *)', 'color')).toContain('var(--color-slate-100)');
        expect(values('.label:where(.dark, .dark *)', 'color')).toContain('var(--color-slate-200)');
        expect(values('.app-shell:where(.dark, .dark *)', 'background-image').join(' ')).toContain('#020617');
        expect(values('.btn-secondary:where(.dark, .dark *)', '--tw-shadow')).toContain('0 0 #0000');
        expect(stylesheet.toString()).not.toContain('prefers-color-scheme');
    });

    it('preserves the v3 preflight border, placeholder and button defaults', () => {
        expect(values('::file-selector-button', 'border-color')).toContain('var(--color-gray-200, currentColor)');
        expect(values('input::placeholder', 'color')).toContain('var(--color-gray-400)');
        expect(values('button:not(:disabled)', 'cursor')).toContain('pointer');
        expect(values('dialog', 'margin')).toContain('auto');
        expect(values('html', 'color-scheme')).toContain('light');
        expect(values('html.dark', 'color-scheme')).toContain('dark');
    });

    it('keeps light/dark palette overrides in the utility layer and colors the new divide selector', () => {
        expect(values('.text-white', 'color').at(-1)).toBe('#0f172a');
        expect(values(':where(.dark) .text-white', 'color')).toContain('#fff');
        expect(values('.bg-slate-950', 'background-color').at(-1)).toBe('#ffffffeb');
        expect(values(':where(.dark) .bg-slate-950', 'background-color')).toContain('#020617');
        const lightDivider = '.divide-white\\/10 > :not(:last-child)';
        expect(values(lightDivider, 'border-color')).toContain('#e2e8f0');
        expect(values(`:where(.dark) ${lightDivider}`, 'border-color')).toContain('#ffffff1a');
        expect(layerName(rules(':where(.dark) .text-white')[0])).toBe('utilities');
        expect(stylesheet.toString()).not.toContain('var(--tw-shadow-colored)');
        expect(values(':where(.dark) .shadow-black\\/20', '--tw-shadow-color')).toContain('#0003');
    });

    it('allows action icon and dashboard link hover backgrounds to win in both themes', () => {
        expect(actionIconBackground(false, false)).toBe('#ffffffd1');
        expect(actionIconBackground(false, true)).toBe('var(--color-slate-100)');
        expect(actionIconBackground(true, false)).toBe('#ffffff0d');
        expect(actionIconBackground(true, true)).toBe('color-mix(in oklab, var(--color-white) 10%, transparent)');
    });

    it('compiles existing responsive layouts at the unchanged breakpoints', () => {
        for (const [selector, breakpoint] of [['.sm\\:items-center', '40rem'], ['.md\\:hidden', '48rem'], ['.lg\\:hidden', '64rem']]) {
            const rule = rules(selector)[0];
            expect(rule).toBeDefined();
            expect(rule.parent?.type).toBe('atrule');
            if (rule.parent?.type === 'atrule') {
                expect(rule.parent.params).toContain(breakpoint);
            }
        }
    });

    it('places form spacing on the control after inline label text, not on the span', () => {
        const selector = '.space-y-2 > :not([hidden]) ~ :not([hidden])';
        const document = new JSDOM('<label class="space-y-2"><span>Name</span><input class="input"></label>').window.document;

        expect([...document.querySelectorAll(selector)].map((element) => element.tagName)).toEqual(['INPUT']);
        expect(values(selector, 'margin-block-start')).toContain('calc(var(--spacing) * 2)');
        expect(values(selector, 'margin-block-end')).toContain('0');
        expect(values('.space-y-2 > :not(:last-child)', 'margin-block')).toContain('0');
    });

    it('keeps outer spacing independent of a child’s own spacing utility', () => {
        const document = new JSDOM('<section class="space-y-3"><article>First</article><article class="space-y-4"><span>Volume</span><input></article></section>').window.document;
        const outerSelector = '.space-y-3 > :not([hidden]) ~ :not([hidden])';
        const innerSelector = '.space-y-4 > :not([hidden]) ~ :not([hidden])';

        expect(document.querySelector(outerSelector)?.className).toBe('space-y-4');
        expect(document.querySelector(innerSelector)?.tagName).toBe('INPUT');
        expect(values(outerSelector, 'margin-block-start')).toContain('calc(var(--spacing) * 3)');
        expect(values(innerSelector, 'margin-block-start')).toContain('calc(var(--spacing) * 4)');
        expect(stylesheet.toString()).not.toContain('--legacy-space-y');
    });
});
