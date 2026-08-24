<template>
  <section class="space-y-4">
    <h1>Nicht gefunden</h1>
    <p>Unter dieser Adresse liegt nichts.</p>
    <p><RouterLink :to="{ name: 'sign-in' }">Zur Anmeldung</RouterLink></p>
  </section>
</template>
