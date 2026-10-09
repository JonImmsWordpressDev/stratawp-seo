import { defineConfig } from 'vitest/config';

// The editor tests are plain Node tests: they cover the analysis modules and
// compile JSX through babel.config.js themselves (jsx-pragma.test.js), so no
// DOM environment and no Babel transform are needed here.
export default defineConfig( {
	test: {
		include: [ 'src/editor/**/*.test.js' ],
		// build/ holds staged copies of the plugin made by bin/build-zip.sh.
		exclude: [ '**/node_modules/**', 'vendor/**', 'build/**' ],
		environment: 'node',
		globals: true,
	},
} );
