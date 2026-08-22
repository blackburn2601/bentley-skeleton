<script setup lang="ts">
import { ApiError } from '@/api/problem'

/**
 * Shared form shell: submission state, and errors rendered where they belong.
 *
 * Field errors go beside their inputs and the rest goes to a single banner. Without this,
 * every form invents its own error handling and the validation detail the API carefully
 * returns ends up as one generic "something went wrong".
 */
defineProps<{
  title: string
  submitLabel: string
  busy?: boolean
  error?: ApiError | Error | null
  success?: string | null
}>()

defineEmits<{ submit: [] }>()

function messageFor(error: ApiError | Error | null | undefined): string | null {
  if (!error) return null
  // Field-level detail is rendered next to the fields; the banner would only repeat it.
  if (error instanceof ApiError && Object.keys(error.fieldErrors).length > 0) return null
  return error.message
}

function requestIdFor(error: ApiError | Error | null | undefined): string | undefined {
  return error instanceof ApiError ? error.requestId : undefined
}
</script>

<template>
  <form class="form" novalidate @submit.prevent="$emit('submit')">
    <h1>{{ title }}</h1>

    <p v-if="success" class="notice notice--success" role="status">{{ success }}</p>

    <div v-if="messageFor(error)" class="notice notice--error" role="alert">
      <p>{{ messageFor(error) }}</p>
      <p v-if="requestIdFor(error)" class="notice__meta">
        Reference: <code>{{ requestIdFor(error) }}</code>
      </p>
    </div>

    <slot />

    <button type="submit" :disabled="busy">
      {{ busy ? 'Working…' : submitLabel }}
    </button>

    <slot name="footer" />
  </form>
</template>
