<script setup lang="ts">
import { ShieldAlert } from 'lucide-vue-next'

import { Button } from '@/components/ui/button'
</script>

<template>
  <div class="flex flex-col items-center gap-3 py-16 text-center">
    <ShieldAlert class="size-10 text-muted-foreground" />
    <h1 class="text-xl font-semibold">Sie haben keinen Zugriff auf diese Seite</h1>
    <p class="max-w-md text-sm text-muted-foreground">
      Ihrem Konto fehlt die Berechtigung, die diese Seite voraussetzt. Falls das nicht richtig
      ist, kann eine Administratorin oder ein Administrator sie erteilen — sie wirkt ab Ihrer
      nächsten Anfrage, ganz ohne Abmelden.
    </p>
    <Button as-child variant="outline" class="mt-2">
      <RouterLink to="/admin">Zurück zur Administration</RouterLink>
    </Button>
  </div>
</template>
