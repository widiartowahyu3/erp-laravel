import pluginVue from 'eslint-plugin-vue';
import pluginVueA11y from 'eslint-plugin-vuejs-accessibility';

export default [
    ...pluginVue.configs['flat/recommended'],
    ...pluginVueA11y.configs['flat/recommended'],
    {
        files: ['resources/js/**/*.{js,vue}'],
        rules: {
            'vue/multi-word-component-names': 'off',
            'vue/max-attributes-per-line': 'off',
            'vue/singleline-html-element-content-newline': 'off',
            'vue/html-self-closing': 'off',
            'vue/html-indent': 'off',
            'vue/html-closing-bracket-newline': 'off',
            'vue/no-v-html': 'error',
            'vue/no-static-inline-styles': 'error',
            'vuejs-accessibility/label-has-for': [
                'error',
                {
                    required: {
                        some: ['nesting', 'id'],
                    },
                },
            ],
        },
    },
];
