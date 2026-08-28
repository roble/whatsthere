import prettier from 'eslint-config-prettier/flat';
import vue from 'eslint-plugin-vue';

import {
    defineConfigWithVueTs,
    vueTsConfigs,
} from '@vue/eslint-config-typescript';

export default defineConfigWithVueTs(
    vue.configs['flat/essential'],
    vueTsConfigs.recommended,
    {
        languageOptions: {
            parserOptions: {
                tsconfigRootDir: import.meta.dirname,
            },
        },
    },
    {
        ignores: [
            'vendor',
            'node_modules',
            'public',
            'bootstrap/ssr',
            'stubs',
            'tailwind.config.js',
            'resources/js/components/ui/*',
            'modules/*/resources/js/components/ui/*',
            'stubs/saucebase/stack/*/resources/js/**/*',
        ],
    },
    {
        rules: {
            'vue/multi-word-component-names': 'off',
            '@typescript-eslint/no-explicit-any': 'off',

            // `const { size, variant: _, ...rest } = props` omits keys from the
            // rest object. The named bindings are meant to be unused -- that is
            // the whole point of writing them.
            '@typescript-eslint/no-unused-vars': [
                'error',
                { ignoreRestSiblings: true },
            ],

            // `interface Props extends /* @vue-ignore */ Other {}` is how an SFC
            // inherits another component's props. It has no members by design;
            // collapsing it to a type alias breaks defineProps resolution.
            '@typescript-eslint/no-empty-object-type': [
                'error',
                { allowInterfaces: 'with-single-extends' },
            ],
        },
    },
    prettier,
);
