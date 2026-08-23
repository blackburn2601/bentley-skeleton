<script setup lang="ts">
import { ToastDescription, ToastProvider, ToastRoot, ToastTitle, ToastViewport } from 'reka-ui'

import { useToast } from '@/composables/useToast'
import { cn } from '@/lib/utils'

const { toasts, dismiss } = useToast()
</script>

<template>
  <ToastProvider>
    <ToastRoot
      v-for="toast in toasts"
      :key="toast.id"
      :duration="toast.variant === 'destructive' ? 10000 : 4000"
      :class="
        cn(
          'pointer-events-auto grid gap-1 rounded-lg border p-4 shadow-lg',
          'data-[state=open]:animate-in data-[state=open]:slide-in-from-right-full',
          'data-[state=closed]:animate-out data-[state=closed]:fade-out-80',
          toast.variant === 'destructive'
            ? 'border-destructive/40 bg-destructive text-destructive-foreground'
            : toast.variant === 'success'
              ? 'border-success/40 bg-success text-success-foreground'
              : 'border-border bg-popover text-popover-foreground',
        )
      "
      @update:open="(open: boolean) => !open && dismiss(toast.id)"
    >
      <ToastTitle class="text-sm font-medium">{{ toast.title }}</ToastTitle>
      <ToastDescription v-if="toast.description" class="text-xs opacity-90">
        {{ toast.description }}
      </ToastDescription>
    </ToastRoot>

    <!-- A failure the user cannot read is a failure they will report as "nothing happened",
         so the region is a live region and sits above every dialog. -->
    <ToastViewport
      class="pointer-events-none fixed bottom-0 right-0 z-100 flex w-full max-w-sm flex-col gap-2 p-4"
    />
  </ToastProvider>
</template>
