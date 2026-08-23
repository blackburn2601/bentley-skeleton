<script setup lang="ts">
import { ShieldAlert } from 'lucide-vue-next'

import { Button } from '@/components/ui/button'
</script>

<template>
  <div class="flex flex-col items-center gap-3 py-16 text-center">
    <ShieldAlert class="size-10 text-muted-foreground" />
    <h1 class="text-xl font-semibold">You do not have access to this page</h1>
    <p class="max-w-md text-sm text-muted-foreground">
      Your account does not hold the permission this screen requires. If that is wrong, an
      administrator can grant it — it takes effect on your next request, without signing out.
    </p>
    <Button as-child variant="outline" class="mt-2">
      <RouterLink to="/admin">Back to administration</RouterLink>
    </Button>
  </div>
</template>
