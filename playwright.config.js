module.exports = {
	testDir: 'e2e',
	timeout: 90000,
	use: { baseURL: process.env.WP_BASE_URL, headless: true },
};
