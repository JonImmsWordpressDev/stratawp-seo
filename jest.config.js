const defaults = require( '@wordpress/scripts/config/jest-unit.config.js' );

module.exports = {
	...defaults,
	// build/ holds staged copies of the plugin made by bin/build-zip.sh.
	testPathIgnorePatterns: [ '/node_modules/', '<rootDir>/vendor/', '<rootDir>/build/' ],
};
