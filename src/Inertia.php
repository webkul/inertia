<?php
/**
 * Inertia protocol adapter for PHP.
 *
 * Framework-agnostic: works with plain PHP and integrates transparently
 * with WordPress (WP helpers are used automatically when they exist).
 *
 * Provides what the inertia-laravel package provides on Laravel:
 *
 * - First / standard visit  -> full HTML document with the page object in a
 *   JSON script tag, rendered by the configured root view.
 * - Inertia visit (XHR with `X-Inertia: true`) -> bare JSON page object, no HTML,
 *   so the client swaps props without a page reload.
 * - Stale assets (`X-Inertia-Version` mismatch) -> 409 + `X-Inertia-Location`,
 *   telling the client to do one hard reload to pick up new bundles.
 * - Partial reloads -> prop filtering via `X-Inertia-Partial-Data` /
 *   `X-Inertia-Partial-Except` headers.
 * - Form submissions -> `redirect()` answers non-GET requests with a 303
 *   so the Inertia client follows up with a GET visit.
 * - Shared props -> `share()` merges props (auth user, validation errors,
 *   flash messages) into every render() call.
 *
 * @package Webkul\Inertia
 * @version 1.1.0
 */

namespace Webkul\Inertia;

/**
 * Inertia adapter class
 */
class Inertia {

	/**
	 * The single instance of the class
	 *
	 * @var Inertia
	 */
	protected static $_instance = null;

	/**
	 * Current asset version. A change here forces clients on the old
	 * bundle to hard-reload once (409 handshake) on their next visit.
	 *
	 * @var string|callable
	 */
	protected $version = '';

	/**
	 * Renderer for the HTML shell on standard (non-XHR) visits.
	 *
	 * Receives the page object encoded as JSON (with HEX flags, safe inside
	 * a script tag) and, as a second argument, the raw page object array.
	 *
	 * @var callable|null
	 */
	protected $root_view = null;

	/**
	 * Container / script id used by the default root view.
	 *
	 * @var string
	 */
	protected $app_id = 'app';

	/**
	 * Response charset used for the JSON Content-Type header and the
	 * default root view. Under WordPress the `blog_charset` option wins.
	 *
	 * @var string
	 */
	protected $charset = 'UTF-8';

	/**
	 * Props shared with every render() call (e.g. auth user, validation
	 * errors, flash messages). Merged under the page props; render() props
	 * win on key collision.
	 *
	 * @var array
	 */
	protected $shared_props = array();

	/**
	 * Main Inertia Instance
	 *
	 * @return Inertia Main instance
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Set the asset version, either as a string or as a callable
	 * resolved lazily on each request.
	 *
	 * @param string|callable $version The asset version.
	 * @return Inertia
	 */
	public function set_version( $version ) {
		$this->version = $version;
		return $this;
	}

	/**
	 * Set the root view renderer used for standard (non-XHR) visits.
	 *
	 * The callable receives ( string $page_json, array $page ) and must
	 * output the full HTML document. It may terminate the request itself;
	 * if it returns, the request is terminated for it.
	 *
	 * @param callable $renderer The HTML shell renderer.
	 * @return Inertia
	 */
	public function set_root_view( callable $renderer ) {
		$this->root_view = $renderer;
		return $this;
	}

	/**
	 * Set the container / script id used by the default root view.
	 *
	 * @param string $app_id The element id.
	 * @return Inertia
	 */
	public function set_app_id( $app_id ) {
		$this->app_id = $app_id;
		return $this;
	}

	/**
	 * Set the response charset (ignored under WordPress, where the
	 * `blog_charset` option is used instead).
	 *
	 * @param string $charset The charset, e.g. `UTF-8`.
	 * @return Inertia
	 */
	public function set_charset( $charset ) {
		$this->charset = $charset;
		return $this;
	}

	/**
	 * Whether the current request is an Inertia XHR visit.
	 *
	 * @return bool
	 */
	public function is_inertia_request() {
		return 'true' === $this->server_value( 'HTTP_X_INERTIA' );
	}

	/**
	 * Share a prop with every subsequent render() call.
	 *
	 * Mirrors Inertia::share() from inertia-laravel: pass a key/value pair
	 * or an associative array. Typical use: validation errors and flash
	 * messages pulled from a session-like store. Props passed to render()
	 * win on key collision.
	 *
	 * @param string|array $key   The prop name, or an array of props.
	 * @param mixed        $value The prop value (closures resolve lazily).
	 * @return Inertia
	 */
	public function share( $key, $value = null ) {
		if ( is_array( $key ) ) {
			$this->shared_props = array_merge( $this->shared_props, $key );
		} else {
			$this->shared_props[ $key ] = $value;
		}

		return $this;
	}

	/**
	 * Redirect the current request, Inertia-style, and terminate.
	 *
	 * Mirrors what inertia-laravel does after form submissions: a plain
	 * HTTP redirect that the Inertia client follows with a GET visit.
	 * Non-GET requests (POST/PUT/PATCH/DELETE) get a 303 so the follow-up
	 * request is guaranteed to be a GET; GET requests get a 302.
	 *
	 * @param string $url The redirect target.
	 * @return void
	 */
	public function redirect( $url ) {
		$method = strtoupper( $this->server_value( 'REQUEST_METHOD' ) );
		$status = ( '' === $method || 'GET' === $method ) ? 302 : 303;

		$this->send_status( $status );
		header( 'Location: ' . $this->sanitize_url( $url ) );
		exit;
	}

	/**
	 * Respond to the current request with an Inertia page.
	 *
	 * Sends JSON for Inertia visits, otherwise renders the HTML shell
	 * with the page object embedded in a JSON script tag.
	 * Always terminates the request.
	 *
	 * @param string $component The page component name resolved from the URL.
	 * @param array  $props     The page props. Closures are resolved lazily,
	 *                          after partial-reload filtering.
	 * @return void
	 */
	public function render( $component, $props = array() ) {
		$url   = $this->current_url();
		$props = array_merge( $this->shared_props, $props );

		header( 'Vary: X-Inertia' );

		if ( $this->is_inertia_request() ) {
			$this->check_asset_version( $url );

			$props = $this->filter_partial_props( $component, $props );
			$props = $this->resolve_props( $props );

			$this->send_json_page( $this->page_object( $component, $props, $url ) );
		}

		$props = $this->resolve_props( $props );
		$page  = $this->page_object( $component, $props, $url );

		// HEX flags make the JSON safe to embed inside a <script> tag
		// (`</script>` and quotes can never appear in the output).
		$page_json = $this->json_encode(
			$page,
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);

		if ( is_callable( $this->root_view ) ) {
			call_user_func( $this->root_view, $page_json, $page );
		} else {
			$this->render_default_view( $page_json );
		}

		exit;
	}

	/**
	 * Build the Inertia page object.
	 *
	 * @param string $component The page component name.
	 * @param array  $props     The resolved page props.
	 * @param string $url       The current URL (path + query string).
	 * @return array
	 */
	protected function page_object( $component, $props, $url ) {
		return array(
			'component' => $component,
			'props'     => $props,
			'url'       => $url,
			'version'   => $this->asset_version(),
		);
	}

	/**
	 * Resolve the configured asset version.
	 *
	 * @return string
	 */
	protected function asset_version() {
		if ( is_callable( $this->version ) ) {
			return (string) call_user_func( $this->version );
		}

		return (string) $this->version;
	}

	/**
	 * On GET Inertia visits, compare the client's asset version with ours.
	 * On mismatch answer 409 + X-Inertia-Location so the client performs
	 * a full page visit and picks up the new assets.
	 *
	 * @param string $url The current URL to send back as the reload target.
	 * @return void
	 */
	protected function check_asset_version( $url ) {
		$method = $this->server_value( 'REQUEST_METHOD' );
		$method = '' !== $method ? $method : 'GET';

		if ( 'GET' !== $method || ! isset( $_SERVER['HTTP_X_INERTIA_VERSION'] ) ) {
			return;
		}

		if ( $this->server_value( 'HTTP_X_INERTIA_VERSION' ) === $this->asset_version() ) {
			return;
		}

		// $url (REQUEST_URI) already contains the site path, so build the
		// absolute URL from scheme + host only.
		$host     = $this->request_host();
		$location = ( $this->request_is_ssl() ? 'https://' : 'http://' ) . $host . $url;

		$this->send_status( 409 );
		header( 'X-Inertia-Location: ' . $this->sanitize_url( $location ) );
		exit;
	}

	/**
	 * Apply partial-reload filtering (`only` / `except`) when the client
	 * asked for a partial reload of the same component.
	 *
	 * @param string $component The page component name.
	 * @param array  $props     The page props.
	 * @return array
	 */
	protected function filter_partial_props( $component, $props ) {
		if ( $this->server_value( 'HTTP_X_INERTIA_PARTIAL_COMPONENT' ) !== $component ) {
			return $props;
		}

		if ( isset( $_SERVER['HTTP_X_INERTIA_PARTIAL_DATA'] ) ) {
			$only  = array_filter( array_map( 'trim', explode( ',', $this->server_value( 'HTTP_X_INERTIA_PARTIAL_DATA' ) ) ) );
			$props = array_intersect_key( $props, array_flip( $only ) );
		}

		if ( isset( $_SERVER['HTTP_X_INERTIA_PARTIAL_EXCEPT'] ) ) {
			$except = array_filter( array_map( 'trim', explode( ',', $this->server_value( 'HTTP_X_INERTIA_PARTIAL_EXCEPT' ) ) ) );
			$props  = array_diff_key( $props, array_flip( $except ) );
		}

		return $props;
	}

	/**
	 * Resolve lazy props: closures are only executed once they survive
	 * partial-reload filtering, so skipped props cost no queries.
	 *
	 * @param array $props The page props.
	 * @return array
	 */
	protected function resolve_props( $props ) {
		foreach ( $props as $key => $value ) {
			if ( $value instanceof \Closure ) {
				$props[ $key ] = $value();
			}
		}

		return $props;
	}

	/**
	 * Send the page object as a JSON Inertia response and terminate.
	 *
	 * @param array $page The Inertia page object.
	 * @return void
	 */
	protected function send_json_page( $page ) {
		$this->send_nocache_headers();
		$this->send_status( 200 );
		header( 'Content-Type: application/json; charset=' . $this->response_charset() );
		header( 'X-Inertia: true' );

		echo $this->json_encode( $page );
		exit;
	}

	/**
	 * Current URL (path + query string) for the page object.
	 *
	 * @return string
	 */
	protected function current_url() {
		return isset( $_SERVER['REQUEST_URI'] ) ? $this->sanitize_url( (string) $_SERVER['REQUEST_URI'] ) : '/';
	}

	/**
	 * Minimal fallback HTML shell used when no root view is configured.
	 *
	 * Inertia v3 reads the initial page object from a JSON script tag
	 * (`script[data-page="<id>"][type="application/json"]`).
	 *
	 * WordPress template hooks (wp_head, wp_footer, ...) are included
	 * automatically when running inside WordPress.
	 *
	 * @param string $page_json The Inertia page object encoded as JSON.
	 * @return void
	 */
	protected function render_default_view( $page_json ) {
		$app_id = htmlspecialchars( $this->app_id, ENT_QUOTES );
		?>
		<!DOCTYPE html>
		<html <?php $this->call_if_exists( 'language_attributes', 'lang="en"' ); ?>>
		<head>
			<meta charset="<?php echo htmlspecialchars( $this->response_charset(), ENT_QUOTES ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php $this->call_if_exists( 'get_bloginfo', '', array( 'name' ), true ); ?></title>
			<?php $this->call_if_exists( 'wp_head' ); ?>
		</head>
		<body <?php $this->call_if_exists( 'body_class' ); ?>>
			<script data-page="<?php echo $app_id; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above. ?>" type="application/json"><?php echo $page_json; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON encoded with HEX flags. ?></script>
			<div id="<?php echo $app_id; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above. ?>"></div>
			<?php $this->call_if_exists( 'wp_footer' ); ?>
		</body>
		</html>
		<?php
	}

	/**
	 * Read and sanitize a $_SERVER value. Uses WP sanitizers when
	 * available, plain PHP otherwise.
	 *
	 * @param string $key The $_SERVER key.
	 * @return string Empty string when the key is not set.
	 */
	protected function server_value( $key ) {
		if ( ! isset( $_SERVER[ $key ] ) ) {
			return '';
		}

		$value = (string) $_SERVER[ $key ];

		if ( function_exists( 'sanitize_text_field' ) && function_exists( 'wp_unslash' ) ) {
			return sanitize_text_field( wp_unslash( $value ) );
		}

		return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( $value ) ) );
	}

	/**
	 * JSON-encode with the WP wrapper when available.
	 *
	 * @param mixed $data  The data to encode.
	 * @param int   $flags json_encode flags.
	 * @return string
	 */
	protected function json_encode( $data, $flags = 0 ) {
		return function_exists( 'wp_json_encode' )
			? wp_json_encode( $data, $flags )
			: json_encode( $data, $flags );
	}

	/**
	 * Send an HTTP status code.
	 *
	 * @param int $code The status code.
	 * @return void
	 */
	protected function send_status( $code ) {
		if ( function_exists( 'status_header' ) ) {
			status_header( $code );
			return;
		}

		http_response_code( $code );
	}

	/**
	 * Send no-cache headers.
	 *
	 * @return void
	 */
	protected function send_nocache_headers() {
		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
			return;
		}

		header( 'Cache-Control: no-cache, must-revalidate, max-age=0' );
		header( 'Expires: Wed, 11 Jan 1984 05:00:00 GMT' );
	}

	/**
	 * Response charset: the WP `blog_charset` option when available,
	 * otherwise the configured charset.
	 *
	 * @return string
	 */
	protected function response_charset() {
		if ( function_exists( 'get_option' ) ) {
			$charset = get_option( 'blog_charset' );

			if ( is_string( $charset ) && '' !== $charset ) {
				return $charset;
			}
		}

		return $this->charset;
	}

	/**
	 * Sanitize a URL for output in a header or the page object.
	 *
	 * @param string $url The URL.
	 * @return string
	 */
	protected function sanitize_url( $url ) {
		if ( function_exists( 'esc_url_raw' ) && function_exists( 'wp_unslash' ) ) {
			return esc_url_raw( wp_unslash( $url ) );
		}

		return filter_var( $url, FILTER_SANITIZE_URL );
	}

	/**
	 * Whether the current request came over HTTPS.
	 *
	 * @return bool
	 */
	protected function request_is_ssl() {
		if ( function_exists( 'is_ssl' ) ) {
			return is_ssl();
		}

		if ( isset( $_SERVER['HTTPS'] ) && 'off' !== strtolower( (string) $_SERVER['HTTPS'] ) ) {
			return true;
		}

		return isset( $_SERVER['SERVER_PORT'] ) && 443 === (int) $_SERVER['SERVER_PORT'];
	}

	/**
	 * Host of the current request, for building the X-Inertia-Location URL.
	 *
	 * @return string
	 */
	protected function request_host() {
		$host = $this->server_value( 'HTTP_HOST' );

		if ( '' !== $host ) {
			return $host;
		}

		$host = $this->server_value( 'SERVER_NAME' );

		if ( '' === $host && function_exists( 'home_url' ) ) {
			$host = (string) parse_url( home_url(), PHP_URL_HOST );
		}

		return $host;
	}

	/**
	 * Call a global (WordPress) template function when it exists,
	 * otherwise output the plain-PHP fallback.
	 *
	 * @param string $function The function name.
	 * @param string $fallback Fallback string to echo when it does not exist.
	 * @param array  $args     Arguments for the function.
	 * @param bool   $echo     Whether to echo the function's return value
	 *                         (for getters like get_bloginfo).
	 * @return void
	 */
	protected function call_if_exists( $function, $fallback = '', $args = array(), $echo = false ) {
		if ( function_exists( $function ) ) {
			$result = call_user_func_array( $function, $args );

			if ( $echo ) {
				echo htmlspecialchars( (string) $result, ENT_QUOTES ); // phpcs:ignore WordPress.Security.EscapeOutput
			}

			return;
		}

		echo $fallback; // phpcs:ignore WordPress.Security.EscapeOutput -- static fallback markup.
	}
}
