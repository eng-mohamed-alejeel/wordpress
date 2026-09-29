<?php
/** Real cookie, nonce and REST boundary checks for account-owned preferences. */
if ( PHP_SAPI !== 'cli' || ! defined( 'DB_NAME' ) || ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', DB_NAME ) ) { exit( 1 ); }

$preference_path = $rest_path( '/auto-dealership/v1/account/preferences' );
adc_check( 401 === $http_request( $preference_path )[0], 'Account preference REST read rejects anonymous callers.' );
$preference_http = $http_auth( $preference_account );
list( $preference_get_status, $preference_get_body ) = $http_request( $preference_path, $preference_http );
$preference_get = json_decode( $preference_get_body, true );
adc_check( 200 === $preference_get_status && false === $preference_get['consent_marketing'] && $preference_customer === (int) $preference_get['linked_customer_id'], 'Authenticated preference REST read returns only the current account state.' );
adc_check( 401 === $http_request( $preference_path, array( 'cookie'=>$preference_http['cookie'] ), 'POST', array( 'consent_marketing'=>true ) )[0], 'Account preference REST write requires a valid WordPress nonce.' );
adc_check( 400 === $http_request( $preference_path, $preference_http, 'POST', array() )[0], 'Account preference REST schema requires an explicit boolean choice.' );
list( $preference_post_status, $preference_post_body ) = $http_request( $preference_path, $preference_http, 'POST', array( 'consent_marketing'=>true ) );
$preference_post = json_decode( $preference_post_body, true );
adc_check( 200 === $preference_post_status && true === $preference_post['consent_marketing'] && '1' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT consent_marketing FROM $customers WHERE id=%d", $preference_customer ) ), 'Authenticated preference REST write updates the account-owned canonical customer.' );
$other_preference_http = $http_auth( $account_actor );
$other_preference = json_decode( $http_request( $preference_path, $other_preference_http )[1], true );
$other_preference_customer = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $customers WHERE account_user_id=%d AND merged_into_id IS NULL", $account_actor ) );
adc_check( $other_preference_customer > 0 && $other_preference_customer === (int) $other_preference['linked_customer_id'] && $preference_customer !== (int) $other_preference['linked_customer_id'], 'Preference REST reads cannot select or disclose another account customer.' );
adc_check( 200 === $http_request( $preference_path, $preference_http, 'POST', array( 'consent_marketing'=>false ) )[0] && '0' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT consent_marketing FROM $customers WHERE id=%d", $preference_customer ) ), 'Authenticated REST opt-out immediately clears canonical marketing permission.' );
