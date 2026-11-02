import { mount } from '@vue/test-utils';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { defineComponent } from 'vue';
import { describe, expect, it } from 'vitest';

describe('patched test-tool dependencies', () => {
    it('preserves formatted Vue wrapper output', () => {
        const wrapper = mount(defineComponent({ template: '<section><div>Backup</div><div>Restore</div></section>' }));

        try {
            expect(wrapper.html()).toBe('<section>\n  <div>Backup</div>\n  <div>Restore</div>\n</section>');
        } finally {
            wrapper.unmount();
        }
    });

    it('formats globbed files using EditorConfig with the patched glob and minimatch APIs', () => {
        const directory = mkdtempSync(join(tmpdir(), 'volumevault-beautify-'));
        const require = createRequire(import.meta.url);
        const cli = require.resolve('js-beautify/js/bin/js-beautify.js');

        try {
            writeFileSync(join(directory, '.editorconfig'), 'root = true\n\n[*.{js,ts}]\nindent_style = space\nindent_size = 3\n');
            writeFileSync(join(directory, 'backup.js'), 'function backup(){return true;}');
            writeFileSync(join(directory, 'restore.js'), 'function restore(){return false;}');

            execFileSync(process.execPath, [cli, '--editorconfig', '--replace', '*.js'], { cwd: directory });

            expect(readFileSync(join(directory, 'backup.js'), 'utf8')).toContain('\n   return true;\n');
            expect(readFileSync(join(directory, 'restore.js'), 'utf8')).toContain('\n   return false;\n');
        } finally {
            rmSync(directory, { recursive: true, force: true });
        }
    });
});
