import { createPinia } from 'pinia'
import { createApp } from 'vue'

import App from './App.vue'
import { setLoadingHandler } from './api/client'
import { vCan } from './directives/can'
import { router } from './router'
import { useUiStore } from './stores/ui'
import './style.css'

const app = createApp(App)

app.use(createPinia())

// Wire the global loading bar to the API client. Done after Pinia is installed so the store is
// active, and before `mount` so the first request — the boot `/me` call — is already counted.
const ui = useUiStore()
setLoadingHandler(
  () => ui.beginRequest(),
  () => ui.endRequest(),
)

app.use(router)
app.directive('can', vCan)

app.mount('#app')
