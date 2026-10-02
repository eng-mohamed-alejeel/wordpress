# Retired Car Dealer source

These files were moved out of the deployable theme in increment 1.29.13. They include old dealership writers, admin pages, compatibility fallbacks, unused admin assets and historical theme checks. They are retained byte for byte where possible to preserve manual work and make source review possible.

Neither the theme nor the plugin bootstraps this directory. Do not load these PHP files in a running site: they contain former business handlers and may register duplicate hooks or bypass current plugin validation. The plugin is the sole operational owner of those capabilities. A rollback to a pre-1.29.13 deployment requires restoring a matching theme and plugin release together, not including an archived file from this directory.

This source move does not insert, update or delete WordPress content or dealership records.
