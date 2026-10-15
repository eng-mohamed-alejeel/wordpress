<?php
/** Compatibility entry points. Tenant pages are created explicitly by the core setup wizard. */
defined( 'ABSPATH' ) || exit;

/** @deprecated Use ProductSetup::create_pages() through the authorized setup action. */
function car_dealer_setup_public_site() {
    // Kept for older integrations; never seed local business details or overwrite editorial content.
}

/** @deprecated Existing contact copy belongs to the site owner and is never migrated automatically. */
function car_dealer_upgrade_contact_copy() {
}
