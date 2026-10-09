// babel-jest, set by the preset, picks up babel.config.js, so tests compile
// JSX with the same classic runtime as the shipped bundle.
module.exports = {
	preset: '@wordpress/jest-preset-default',
	// build/ holds staged copies of the plugin made by bin/build-zip.sh.
	testPathIgnorePatterns: [ '/node_modules/', '<rootDir>/vendor/', '<rootDir>/build/' ],
};
