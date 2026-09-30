<?php
namespace AutoDealership\Integrations;

defined( 'ABSPATH' ) || exit;

/** Runtime contract implemented by a provider plugin. Credentials remain provider-owned. */
interface AdapterContract {
	/** Stable code-owned adapter identifier. */
	public function id(): string;

	/** Domain event keys handled by this adapter. */
	public function events(): array;

	/** Return true on remote acceptance or WP_Error with a safe machine error code. */
	public function deliver( string $event_key, array $payload, array $event );
}
