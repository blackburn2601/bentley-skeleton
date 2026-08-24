<script setup lang="ts">
/**
 * A six-digit TOTP entry, one box per digit (ADR-0026).
 *
 * `autocomplete="one-time-code"` lets an authenticator app or the browser's SMS-to-OTP heuristics
 * fill the code in one tap; `inputmode="numeric"` gives a numeric keypad on phones. Paste of a
 * full code spreads across the boxes, and focus advances as the user types.
 */
import { ref, useTemplateRef, watch } from 'vue'

const LENGTH = 6

const model = defineModel<string>({ required: true })

const digits = ref<string[]>(Array.from({ length: LENGTH }, () => ''))
const boxes = useTemplateRef<HTMLElement[]>('boxes')

// Keep the boxes in sync when the model changes from the outside (e.g. cleared on submit).
watch(
  model,
  (value) => {
    const next = value ?? ''
    for (let i = 0; i < LENGTH; ++i) {
      digits.value[i] = next[i] ?? ''
    }
  },
  { immediate: true },
)

function emit(): void {
  model.value = digits.value.join('')
}

function focusNext(index: number): void {
  const next = boxes.value?.[index + 1]
  next?.focus()
}

function focusPrev(index: number): void {
  const prev = boxes.value?.[index - 1]
  prev?.focus()
}

function onInput(event: Event, index: number): void {
  const input = event.target as HTMLInputElement
  // Keep only the last typed digit, so autofill or fast typing does not stack two in one box.
  const value = input.value.replace(/\D/g, '').slice(-1)
  digits.value[index] = value
  input.value = value
  emit()

  if (value !== '') {
    focusNext(index)
  }
}

function onKeydown(event: KeyboardEvent, index: number): void {
  if (event.key === 'Backspace' && digits.value[index] === '') {
    // An empty box's backspace steps back and clears the previous one, the way every OTP
    // widget the user has ever met does.
    event.preventDefault()
    focusPrev(index)
    const prevIndex = index - 1
    if (prevIndex >= 0) {
      digits.value[prevIndex] = ''
      emit()
    }
  } else if (event.key === 'ArrowLeft') {
    focusPrev(index)
  } else if (event.key === 'ArrowRight') {
    focusNext(index)
  }
}

function onPaste(event: ClipboardEvent): void {
  event.preventDefault()
  const text = event.clipboardData?.getData('text').replace(/\D/g, '').slice(0, LENGTH) ?? ''
  if (text === '') return

  for (let i = 0; i < LENGTH; ++i) {
    digits.value[i] = text[i] ?? ''
  }
  emit()
  // Land on the box after the last pasted digit, or the last box if the paste was full.
  const lastIndex = Math.min(text.length, LENGTH - 1)
  boxes.value?.[lastIndex].focus()
}
</script>

<template>
  <div
    class="flex justify-between gap-2"
    @paste="onPaste"
  >
    <input
      v-for="(digit, index) in digits"
      :key="index"
      ref="boxes"
      :value="digit"
      type="text"
      inputmode="numeric"
      :autocomplete="index === 0 ? 'one-time-code' : 'off'"
      :aria-label="`Code-Ziffer ${index + 1}`"
      maxlength="1"
      class="h-12 w-12 rounded-md border border-input bg-background text-center text-lg font-medium focus:outline-none focus:ring-2 focus:ring-ring"
      @input="onInput($event, index)"
      @keydown="onKeydown($event, index)"
    />
  </div>
</template>