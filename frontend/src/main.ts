import { createPinia } from 'pinia'
import { createApp } from 'vue'

import App from './App.vue'
import { vCan } from './directives/can'
import { router } from './router'
import './style.css'

const app = createApp(App)

app.use(createPinia())
app.use(router)
app.directive('can', vCan)

app.mount('#app')
