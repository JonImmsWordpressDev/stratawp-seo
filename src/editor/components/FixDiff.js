import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';

export default function FixDiff( { proposal, onApply, onDismiss } ) {
	return (
		<div className="swps-diff" data-testid="swps-diff">
			{ proposal.original !== '' && (
				<div className="swps-diff__before">
					<span className="swps-diff__tag">{ __( 'Before', 'stratawp-seo' ) }</span>
					<del>{ proposal.original }</del>
				</div>
			) }
			<div className="swps-diff__after">
				<span className="swps-diff__tag">{ __( 'After', 'stratawp-seo' ) }</span>
				{ proposal.heading && <strong>{ proposal.heading }</strong> }
				<ins>{ proposal.value }</ins>
			</div>
			<div className="swps-diff__actions">
				<Button variant="primary" size="small" onClick={ onApply }>
					{ __( 'Apply', 'stratawp-seo' ) }
				</Button>
				<Button variant="tertiary" size="small" onClick={ onDismiss }>
					{ __( 'Dismiss', 'stratawp-seo' ) }
				</Button>
			</div>
		</div>
	);
}
