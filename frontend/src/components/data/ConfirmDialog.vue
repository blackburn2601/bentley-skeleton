<script setup lang="ts">
import { ref, watch } from 'vue'

import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'

/**
 * Confirmation for an action that cannot be undone.
 *
 * `confirmPhrase` makes the user type something exact. That is not friction for its own sake:
 * a modal with a single red button is dismissed by reflex, and the actions using this one —
 * erasing a person's data, deleting a role every holder depends on — have no undo.
 */
const props = defineProps<{
  title: string
  description: string
  confirmLabel: string
  /** When set, the confirm button stays disabled until this is typed exactly. */
  confirmPhrase?: string
  busy?: boolean
}>()

const open = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ confirm: [] }>()

const typed = ref('')

watch(open, (isOpen) => {
  if (!isOpen) {
    typed.value = ''
  }
})
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="sm:max-w-md">
      <DialogHeader>
        <DialogTitle>{{ props.title }}</DialogTitle>
        <DialogDescription>{{ props.description }}</DialogDescription>
      </DialogHeader>

      <div v-if="props.confirmPhrase" class="space-y-1.5">
        <Label for="confirm-phrase">
          Type <code class="font-semibold">{{ props.confirmPhrase }}</code> to confirm
        </Label>
        <Input id="confirm-phrase" v-model="typed" autocomplete="off" />
      </div>

      <DialogFooter>
        <Button variant="outline" :disabled="props.busy" @click="open = false">Cancel</Button>
        <Button
          variant="destructive"
          :disabled="props.busy || (!!props.confirmPhrase && typed !== props.confirmPhrase)"
          @click="emit('confirm')"
        >
          {{ props.busy ? 'Working…' : props.confirmLabel }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
