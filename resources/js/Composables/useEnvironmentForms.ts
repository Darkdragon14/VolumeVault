import { computed, onBeforeUnmount, onMounted, shallowReactive } from 'vue';

type EditableForm = { readonly isDirty: boolean; readonly processing: boolean };
const forms = shallowReactive(new Set<EditableForm>());

export const environmentFormsDirty = computed(() => [...forms].some(form => form.isDirty));
export const environmentFormsProcessing = computed(() => [...forms].some(form => form.processing));

/** Track form state only while its page is mounted; never copy field values. */
export function useEnvironmentForms(...pageForms: EditableForm[]): void {
    onMounted(() => pageForms.forEach(form => forms.add(form)));
    onBeforeUnmount(() => pageForms.forEach(form => forms.delete(form)));
}
