<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the website, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'test' );

/** Database username */
define( 'DB_USER', 'root' );

/** Database password */
define( 'DB_PASSWORD', '' );

/** Database hostname */
define( 'DB_HOST', 'localhost' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8mb4' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',         'MHm~WUxj>DIs,,;Uj.K}Mubt :jWEaYJ^7ouVk3fM=EJB*T!i1owRTY@:$ S:njs' );
define( 'SECURE_AUTH_KEY',  ';bX[FdlvW36uqnpq!j/Vu`[`L)8c/0b)f4N}nTe=<9m=C^-i6r2+Ywg&?;9w2^mv' );
define( 'LOGGED_IN_KEY',    'mz]ufB3,}ML<41I|KT:STPxB/CBjf(yNSv9}Yj}b>vCH.GvsuPK~eE15=cgI[Mf3' );
define( 'NONCE_KEY',        'Bd8s8x$@@9,WE&&XkrVS>(z@?,XwXPEx~B|TXK/{gS-!lDa@};_5G[:b8C{J&FLZ' );
define( 'AUTH_SALT',        '7Vm.ZG7CppnZa/WfBgq[E,tZ[O+[82-tCRZ-f[_No>tbn&+cr+EhY5T:!_gW=luH' );
define( 'SECURE_AUTH_SALT', 'WIH9`ngJSZ/-yA|BK;isx6uD8>H:t{~gcgV4-6-IP|l+.w$N}h8plr{>&1tOt1]T' );
define( 'LOGGED_IN_SALT',   'Qh3IT|u$hMSZtAQZ]FS;Ad8KVi&>J4yO};iZX|8j4eZ/t)IVR+^|;pn,%oG}_*K<' );
define( 'NONCE_SALT',       'R}giHEv,3}(ouGf]i:N(`+ /)bRmv-ba8i EMp{sQesHwbfHcDH0|[0m^M/>HQ)b' );

/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 *
 * At the installation time, database tables are created with the specified prefix.
 * Changing this value after WordPress is installed will make your site think
 * it has not been installed.
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/#table-prefix
 */
$table_prefix = 'wp_';

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://developer.wordpress.org/advanced-administration/debug/debug-wordpress/
 */
define( 'WP_DEBUG', false );

/* Add any custom values between this line and the "stop editing" line. */



/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
