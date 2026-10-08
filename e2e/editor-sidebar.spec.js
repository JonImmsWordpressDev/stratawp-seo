// Manual smoke test against a local site with the plugin active and the
// sidebar enabled (wp option update swps_editor_sidebar 1).
// WP_BASE_URL=http://site.local WP_USER=admin WP_PASS=... npm run test:e2e
const { test, expect } = require( '@playwright/test' );

test( 'sidebar scores live, applies a rule fix and undoes it', async ( { page } ) => {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', process.env.WP_USER );
	await page.fill( '#user_pass', process.env.WP_PASS );
	await page.click( '#wp-submit' );

	await page.goto( '/wp-admin/post-new.php' );
	const close = page.getByRole( 'button', { name: 'Close' } );
	if ( await close.count() ) {
		await close.first().click();
	}

	await page.getByRole( 'textbox', { name: 'Add title' } ).fill( 'Cold brew coffee guide' );
	await page.keyboard.press( 'Enter' );
	await page.keyboard.type( 'Cold brew coffee is easy to make at home.' );

	// First match is the pinned top-bar button.
	await page.getByRole( 'button', { name: 'StrataWP SEO' } ).first().click();
	await page.getByLabel( 'Focus keyword' ).fill( 'cold brew coffee' );

	// The score appears within the debounce window.
	await expect( page.getByTestId( 'swps-score' ).first() ).toBeVisible();

	const slugRow = page.locator( '[data-check="kw_in_slug"]' );
	await expect( slugRow ).toHaveClass( /swps-check--fail/ );

	await page.getByRole( 'button', { name: 'Fix: Keyword in the URL slug' } ).click();
	await expect( page.getByTestId( 'swps-diff' ) ).toBeVisible();
	await page.getByRole( 'button', { name: 'Apply' } ).click();
	await expect( slugRow ).toHaveClass( /swps-check--pass/ );

	await page.getByRole( 'button', { name: 'Undo' } ).click();
	await expect( slugRow ).toHaveClass( /swps-check--fail/ );
} );
