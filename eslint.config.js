import globals from 'globals';

/**
 * The CP JavaScript. These rules are the JS half of the Foster Commerce code standards, which nothing
 * enforced before: PHPStan, ECS and Rector are PHP tools and never see this directory.
 */
export default [
	{
		files: ['src/assetbundles/dist/js/*.js'],
		languageOptions: {
			ecmaVersion: 2022,
			sourceType: 'script',
			globals: {
				...globals.browser,
				// Craft's CP globals. The bundles are only ever registered from CP templates.
				Craft: 'readonly',
				Garnish: 'readonly',
				$: 'readonly',
				jQuery: 'readonly',
			},
		},
		rules: {
			// `var` is function-scoped and hoisted, so one declared inside an if/for leaks out of it.
			'no-var': 'error',
			'prefer-const': 'error',
			eqeqeq: ['error', 'always'],
			'no-undef': 'error',
			'no-unused-vars': 'error',
			'no-console': 'error',
			curly: ['error', 'all'],
			'no-implicit-globals': 'error',
		},
	},
];
