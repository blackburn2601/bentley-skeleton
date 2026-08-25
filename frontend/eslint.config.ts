import js from '@eslint/js'
import { defineConfigWithVueTs, vueTsConfigs } from '@vue/eslint-config-typescript'
import skipFormatting from '@vue/eslint-config-prettier/skip-formatting'
import { globalIgnores } from 'eslint/config'
import pluginVue from 'eslint-plugin-vue'

/**
 * The frontend mirrors the backend's rule: views render and dispatch, they do not decide.
 *
 * Only rules that catch real problems are enabled. Formatting is Prettier's job and is
 * switched off here (skipFormatting) so the two never argue — a lint run that reports
 * indentation buries the one finding that mattered.
 *
 * defineConfigWithVueTs, not plain defineConfig: it wires the TypeScript project references
 * that vue-tsc uses, so ESLint and the type-checker agree about what belongs to the app.
 */
export default defineConfigWithVueTs(
  globalIgnores(['dist/**', 'coverage/**', 'playwright-report/**', 'test-results/**']),

  {
    name: 'app/files',
    files: ['**/*.{ts,mts,tsx,vue}'],
  },

  js.configs.recommended,
  pluginVue.configs['flat/recommended'],
  vueTsConfigs.recommended,
  skipFormatting,

  {
    name: 'app/rules',
    rules: {
      // An unused variable is usually a half-finished edit. Underscore-prefixed names are the
      // documented way to say "deliberately unused".
      '@typescript-eslint/no-unused-vars': [
        'error',
        { argsIgnorePattern: '^_', varsIgnorePattern: '^_' },
      ],

      // `any` disables exactly the checking this project pays for.
      '@typescript-eslint/no-explicit-any': 'error',

      // Console output in shipped code is noise at best and a data leak at worst — a logged
      // response body can contain someone's personal data.
      'no-console': ['error', { allow: ['warn', 'error'] }],
      'no-debugger': 'error',

      // Multi-word component names avoid colliding with current and future HTML elements.
      'vue/multi-word-component-names': 'error',
    },
  },

  {
    // Tests may reach for shortcuts that production code may not.
    name: 'app/tests',
    files: ['**/*.spec.ts', 'e2e/**/*.ts'],
    rules: {
      '@typescript-eslint/no-non-null-assertion': 'off',
      'no-console': 'off',
      // Spec files mount stub components inline. The component-structure rules that protect
      // shipped .vue files flag those stubs (a one-off "Dialog" with untyped props is fine in
      // a test, not in the app), so they are scoped off here rather than worked around.
      'vue/one-component-per-file': 'off',
      'vue/multi-word-component-names': 'off',
      'vue/no-reserved-component-names': 'off',
      'vue/require-prop-types': 'off',
      'vue/require-default-prop': 'off',
    },
  },

  {
    // The vendored design-system primitives (ADR-0022).
    //
    // shadcn-vue names them Button.vue, Card.vue, Table.vue — single words, which
    // vue/multi-word-component-names rejects. Renaming every one of them would fork the
    // upstream source for no benefit and make the next re-vendor a merge conflict. Scoped to
    // this directory so the rule still protects components written here.
    name: 'app/ui-primitives',
    files: ['src/components/ui/**/*.vue'],
    rules: {
      'vue/multi-word-component-names': 'off',
      'vue/require-default-prop': 'off',
      'vue/attributes-order': 'off',
    },
  },
)
