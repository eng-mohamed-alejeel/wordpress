<?php
namespace AutoDealership\Integrations;

defined( 'ABSPATH' ) || exit;

/** Optional provider polling boundary used by the asynchronous reconciliation worker. */
interface ReconciliationContract {
	/** Return ProviderResult or WP_Error with a safe machine error code. */
	public function reconcile( string $event_key, string $remote_reference, array $receipt );
}
