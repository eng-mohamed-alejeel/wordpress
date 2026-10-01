<?php
namespace AutoDealership\Integrations;

defined( 'ABSPATH' ) || exit;

/** Safe runtime readiness reported by a provider adapter without exposing values. */
interface AdapterReadinessContract {
	/** sandbox or production. */
	public function environment(): string;

	/** Required check keys mapped to booleans. Values and secret names are forbidden. */
	public function readiness_checks(): array;
}
