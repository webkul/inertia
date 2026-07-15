<?php
/**
 * Example: webkulwp/inertia with a plain JavaScript client — no framework.
 *
 * Run with:  php -S localhost:8000 index.php
 *
 * The root view is a bare-bones HTML shell: the JSON script tag, an empty
 * #app container and the client script. app.js does the rest.
 *
 * (Without set_root_view() the package renders a similar built-in shell,
 * but it has no way to load your JS outside WordPress — where wp_head()
 * would print the enqueued scripts — so examples always set a root view.)
 *
 * @package Webkul\Inertia
 */

// Adjust to your project's autoloader.
require __DIR__ . '/../../../../vendor/autoload.php';

use Webkul\Inertia\Inertia;

$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );

// Let the PHP dev server serve the client script directly.
if ( '/app.js' === $path ) {
	return false;
}

$inertia = Inertia::instance()
	->set_version( '1.0.0' )
	->set_root_view(
		function ( $page_json, $page ) {
			?>
			<!DOCTYPE html>
			<html lang="en">
			<head>
				<meta charset="UTF-8">
				<meta name="viewport" content="width=device-width, initial-scale=1">
				<title>Inertia + vanilla JS example</title>
			</head>
			<body>
				<script data-page="app" type="application/json"><?php echo $page_json; ?></script>
				<div id="app"></div>
				<script src="/app.js" defer></script>
			</body>
			</html>
			<?php
		}
	);

switch ( $path ) {
	case '/orders':
		$inertia->render(
			'Orders',
			array(
				'orders'      => function () {
					return array(
						array( 'id' => 1, 'total' => '19.99' ),
						array( 'id' => 2, 'total' => '34.50' ),
					);
				},
				// Simulated "expensive" prop: excluded by the client's
				// partial reload, so this closure never runs on refresh.
				'stats'       => function () {
					return array( 'count' => 2 );
				},
				'generatedAt' => gmdate( 'H:i:s' ),
			)
		);
		break;

	default:
		$inertia->render(
			'Home',
			array(
				'message' => 'Served by webkulwp/inertia',
			)
		);
		break;
}
