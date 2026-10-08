/**
 * The plugin supports WordPress 6.0, but the default preset compiles JSX for
 * the automatic runtime, whose react-jsx-runtime script only ships from
 * WordPress 6.6. Compile JSX to createElement from @wordpress/element instead,
 * which every supported version has.
 */

// Adds `import { createElement, Fragment } from '@wordpress/element'` to files
// that contain JSX and do not already bring those names into scope.
const importJsxPragma = ( { types: t } ) => ( {
	visitor: {
		JSXElement( path, state ) {
			state.swpsHasJsx = true;
		},
		JSXFragment( path, state ) {
			state.swpsHasJsx = true;
			state.swpsHasFragment = true;
		},
		Program: {
			exit( path, state ) {
				if ( ! state.swpsHasJsx ) {
					return;
				}
				const names = [ 'createElement' ];
				if ( state.swpsHasFragment ) {
					names.push( 'Fragment' );
				}
				const missing = names.filter( ( name ) => ! path.scope.hasBinding( name ) );
				if ( ! missing.length ) {
					return;
				}
				path.unshiftContainer(
					'body',
					t.importDeclaration(
						missing.map( ( name ) => t.importSpecifier( t.identifier( name ), t.identifier( name ) ) ),
						t.stringLiteral( '@wordpress/element' )
					)
				);
			},
		},
	},
} );

module.exports = ( api ) => {
	api.cache( true );
	return {
		presets: [ '@wordpress/babel-preset-default' ],
		// Project plugins run before the preset's, so JSX is compiled here
		// and the preset's automatic runtime transform never sees any.
		plugins: [
			importJsxPragma,
			[
				'@babel/plugin-transform-react-jsx',
				{
					runtime: 'classic',
					pragma: 'createElement',
					pragmaFrag: 'Fragment',
				},
			],
		],
	};
};
