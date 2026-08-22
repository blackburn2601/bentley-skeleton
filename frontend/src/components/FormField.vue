<script setup lang="ts">
/**
 * One labelled input with its error.
 *
 * `aria-invalid` and `aria-describedby` are wired up because a validation message a screen
 * reader never announces is a validation message that does not exist for that user.
 */
const model = defineModel<string>({ required: true })

defineProps<{
  id: string
  label: string
  type?: string
  autocomplete?: string
  error?: string
  hint?: string
}>()
</script>

<template>
  <div class="field">
    <label :for="id">{{ label }}</label>
    <input
      :id="id"
      v-model="model"
      :type="type ?? 'text'"
      :autocomplete="autocomplete"
      :aria-invalid="error ? 'true' : undefined"
      :aria-describedby="error ? `${id}-error` : hint ? `${id}-hint` : undefined"
    />
    <p v-if="hint && !error" :id="`${id}-hint`" class="field__hint">{{ hint }}</p>
    <p v-if="error" :id="`${id}-error`" class="field__error" role="alert">{{ error }}</p>
  </div>
</template>
