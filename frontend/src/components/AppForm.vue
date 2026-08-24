<script setup lang="ts">
import { ApiError } from '@/api/problem'
import { Button } from '@/components/ui/button'

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
  <form class="space-y-5" novalidate @submit.prevent="$emit('submit')">
    <h1 class="text-2xl font-semibold tracking-tight">{{ title }}</h1>

    <p
      v-if="success"
      class="rounded-md border border-success/40 bg-success/10 px-3 py-2 text-sm text-success"
      role="status"
    >
      {{ success }}
    </p>

    <div
      v-if="messageFor(error)"
      class="space-y-1 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive"
      role="alert"
    >
      <p>{{ messageFor(error) }}</p>
      <p v-if="requestIdFor(error)" class="text-xs opacity-80">
        Referenz: <code>{{ requestIdFor(error) }}</code>
      </p>
    </div>

    <div class="space-y-4">
      <slot />
    </div>

    <Button type="submit" class="w-full" :disabled="busy">
      {{ busy ? 'Bitte warten…' : submitLabel }}
    </Button>

    <slot name="footer" />
  </form>
</template>
