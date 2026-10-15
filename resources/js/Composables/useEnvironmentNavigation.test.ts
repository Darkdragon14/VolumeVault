import { mount } from '@vue/test-utils';
import { defineComponent, nextTick, reactive } from 'vue';
import { expect, it } from 'vitest';
import { environmentFormsDirty, environmentFormsProcessing, useEnvironmentForms } from './useEnvironmentForms';

it('tracks actual reactive form flags only for mounted pages without copying secrets', async () => {
    const form = reactive({ isDirty: false, processing: false, password: 'never copied' });
    const other = reactive({ isDirty: false, processing: false });
    const fixture = defineComponent({ setup() { useEnvironmentForms(form, other); }, template: '<div />' });
    const wrapper = mount(fixture);
    try {
        expect(environmentFormsDirty.value).toBe(false);
        form.isDirty = true;
        other.processing = true;
        await nextTick();
        expect(environmentFormsDirty.value).toBe(true);
        expect(environmentFormsProcessing.value).toBe(true);
        form.isDirty = false;
        expect(environmentFormsDirty.value).toBe(false);
    } finally { wrapper.unmount(); }
    expect(environmentFormsProcessing.value).toBe(false);
    expect(environmentFormsDirty.value).toBe(false);
    form.isDirty = true;
    expect(environmentFormsDirty.value).toBe(false);
});

it('registers every AppLayout useForm page, including all profile forms', () => {
    const pages = import.meta.glob('../Pages/**/*.vue', { eager: true, query: '?raw', import: 'default' }) as Record<string, string>;
    for (const [path, source] of Object.entries(pages)) {
        if (!source.includes('<AppLayout') || !source.includes('useForm(') && !source.includes('useForm<')) continue;
        const forms = [...source.matchAll(/const (\w+) = useForm(?:<[^;]+?>)?\(/g)].map(match => match[1]);
        const registrations = source.match(/useEnvironmentForms\([\s\S]*?\n?\);/g)?.join(' ') ?? '';
        for (const form of forms) expect(registrations, `${path}: ${form}`).toMatch(new RegExp(`\\b${form}\\b`));
    }
    for (const name of ['Dashboard', 'Stacks/Index', 'DockerHosts/Index']) {
        expect(pages[`../Pages/${name}.vue`]).toContain('useEnvironmentForms({');
    }
});
