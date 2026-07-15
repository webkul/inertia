<?php
/**
 * Example: webkul/inertia with a React client (@inertiajs/react).
 *
 * Run with:  php -S localhost:8000 index.php
 * Build the client first:  npm install && npm run build
 *
 * @package Webkul\Inertia
 */

// Adjust to your project's autoloader.
require __DIR__ . '/../../../../vendor/autoload.php';

use Webkul\Inertia\Inertia;

$inertia = Inertia::instance()
	// Bump on every asset build: clients on the old bundle get a 409 and
	// perform one hard reload to pick up dist/app.js again.
	->set_version( '1.0.0' )
	->set_app_id( 'app' )
	// The HTML shell for standard (non-XHR) visits. The React bundle boots
	// from the JSON script tag and mounts into #app.
	->set_root_view(
		function ( $page_json, $page ) {
			?>
			<!DOCTYPE html>
			<html lang="en">
			<head>
				<meta charset="UTF-8">
				<meta name="viewport" content="width=device-width, initial-scale=1">
				<title>Inertia + React example</title>
			</head>
			<body>
				<script data-page="app" type="application/json"><?php echo $page_json; ?></script>
				<div id="app"></div>
				<script src="/dist/app.js"></script>
			</body>
			</html>
			<?php
		}
	);

// ---------------------------------------------------------------------------
// Front controller: map the URL to a component + props, exactly like a
// controller would in a framework. PHP is the router; the client only
// resolves the component name it is given.
// ---------------------------------------------------------------------------

$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );

// Let the PHP dev server serve the built JS bundle directly.
if ( 0 === strpos( $path, '/dist/' ) ) {
	return false;
}

switch ( $path ) {
	case '/orders':
		$inertia->render(
			'Orders',
			array(
				// Lazy prop: the closure only runs when the prop survives
				// partial-reload filtering, so a partial visit asking for
				// other props costs no "query" here.
				'orders' => function () {
					return array(
						array( 'id' => 1, 'total' => '19.99' ),
						array( 'id' => 2, 'total' => '34.50' ),
					);
				},
			)
		);
		break;

	default:
		$inertia->render(
			'Home',
			array(
				'message' => 'Served by webkul/inertia',
			)
		);
		break;
}
