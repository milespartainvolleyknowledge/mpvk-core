<?php
defined( 'ABSPATH' ) || exit;

/**
 * PWA: installable home-screen app for the portal.
 * Serves /mpvk-manifest.json and /mpvk-sw.js via early request interception (no rewrite
 * rules, so it survives plugin updates without a flush). Service worker also carries the
 * push-notification handlers (see MPVK_Push for the server side).
 */
class MPVK_PWA {

	const SW_VERSION = '2'; // bump to force clients to update the cached service worker

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'maybe_serve' ), 1 );
	}

	private static function req_path(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		return rtrim( (string) wp_parse_url( $uri, PHP_URL_PATH ), '/' );
	}

	public static function maybe_serve(): void {
		$path = self::req_path();
		if ( '/mpvk-manifest.json' === $path ) {
			self::serve_manifest();
		} elseif ( '/mpvk-sw.js' === $path ) {
			self::serve_sw();
		}
	}

	private static function icon( string $file ): string {
		return MPVK_PLUGIN_URL . 'assets/pwa/' . $file;
	}

	public static function serve_manifest(): void {
		nocache_headers();
		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		echo wp_json_encode( array(
			'name'             => get_bloginfo( 'name' ) . ' Portal',
			'short_name'       => 'VolleyKnowledge',
			'description'      => 'Your training + coaching, in your pocket.',
			'start_url'        => home_url( '/portal' ),
			'scope'            => home_url( '/' ),
			'display'          => 'standalone',
			'orientation'      => 'portrait',
			'background_color' => '#F0E7D3',
			'theme_color'      => '#1F2E40',
			'icons'            => array(
				array( 'src' => self::icon( 'icon-192.png' ), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any' ),
				array( 'src' => self::icon( 'icon-512.png' ), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any' ),
				array( 'src' => self::icon( 'icon-maskable.png' ), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable' ),
			),
		), JSON_UNESCAPED_SLASHES );
		exit;
	}

	public static function serve_sw(): void {
		nocache_headers();
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Service-Worker-Allowed: /' );
		$portal = esc_js( home_url( '/portal' ) );
		$ver    = self::SW_VERSION;
		// Static, data-free offline page. Never contains a nonce or any user content,
		// so it's safe to cache and serve to anyone sharing the device.
		$offline = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<title>Offline</title><style>html,body{height:100%;margin:0}'
			. 'body{display:flex;align-items:center;justify-content:center;background:#F0E7D3;color:#1F2E40;'
			. "font:16px/1.5 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;text-align:center;padding:2rem}"
			. '.b{max-width:22rem}h1{font-size:1.2rem;margin:0 0 .5rem}p{color:#6E4E30}'
			. 'a{display:inline-block;margin-top:1rem;background:#B9922F;color:#1F2E40;text-decoration:none;'
			. 'border-radius:999px;padding:.6rem 1.3rem;font-weight:700}</style></head>'
			. '<body><div class="b"><h1>You\'re offline</h1><p>VolleyKnowledge needs a connection right now. '
			. 'Reconnect and try again.</p><a href="' . esc_url( home_url( '/portal' ) ) . '" onclick="location.reload();return false;">Retry</a></div></body></html>';
		$offline_js = wp_json_encode( $offline ); // JS string literal, safely escaped
		// phpcs:disable
		echo <<<JS
/* MPVK service worker v{$ver} */
var CACHE = 'mpvk-static-v{$ver}';
var OFFLINE_URL = '/mpvk-offline.html';
var OFFLINE_HTML = {$offline_js};
self.addEventListener('install', function (e) {
  e.waitUntil(caches.open(CACHE).then(function (c) {
    return c.put(OFFLINE_URL, new Response(OFFLINE_HTML, { headers: { 'Content-Type': 'text/html; charset=utf-8' } }));
  }).then(function () { return self.skipWaiting(); }));
});
self.addEventListener('activate', function (e) {
  e.waitUntil(caches.keys().then(function (keys) {
    return Promise.all(keys.filter(function (k) { return k !== CACHE; }).map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});
// Network-first for navigations (always fetch fresh, authenticated HTML — never cache it).
// On failure, fall back to the static offline page (no user data, safe to share).
self.addEventListener('fetch', function (e) {
  var req = e.request;
  if (req.method !== 'GET') return;
  if (req.mode === 'navigate') {
    e.respondWith(
      fetch(req).catch(function () { return caches.match(OFFLINE_URL, { cacheName: CACHE }); })
    );
  }
});
// Push notification from the server (payload: {title, body, url, tag})
self.addEventListener('push', function (e) {
  var data = {};
  try { data = e.data ? e.data.json() : {}; } catch (err) { data = { body: e.data && e.data.text() }; }
  var title = data.title || 'VolleyKnowledge';
  var opts = {
    body: data.body || 'New activity',
    tag: data.tag || 'mpvk',
    icon: '{$portal}'.replace(/\\/portal$/, '') + '/wp-content/plugins/mpvk-core/assets/pwa/icon-192.png',
    badge: '{$portal}'.replace(/\\/portal$/, '') + '/wp-content/plugins/mpvk-core/assets/pwa/icon-192.png',
    data: { url: data.url || '{$portal}' },
    renotify: true
  };
  e.waitUntil(self.registration.showNotification(title, opts));
});
self.addEventListener('notificationclick', function (e) {
  e.notification.close();
  var url = (e.notification.data && e.notification.data.url) || '{$portal}';
  e.waitUntil(clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
    for (var i = 0; i < list.length; i++) { if (list[i].url.indexOf('/portal') > -1 && 'focus' in list[i]) return list[i].focus(); }
    if (clients.openWindow) return clients.openWindow(url);
  }));
});
JS;
		// phpcs:enable
		exit;
	}

	/** <head> tags injected into the portal document. */
	public static function head_tags(): string {
		$manifest = esc_url( home_url( '/mpvk-manifest.json' ) );
		$icon     = esc_url( self::icon( 'apple-touch.png' ) );
		return '<link rel="manifest" href="' . $manifest . '">'
			. '<meta name="theme-color" content="#1F2E40">'
			. '<meta name="apple-mobile-web-app-capable" content="yes">'
			. '<meta name="mobile-web-app-capable" content="yes">'
			. '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">'
			. '<meta name="apple-mobile-web-app-title" content="VolleyKnowledge">'
			. '<link rel="apple-touch-icon" href="' . $icon . '">';
	}

	/** SW registration + install-prompt hint, appended to the portal script. */
	public static function boot_script(): string {
		$sw = esc_js( home_url( '/mpvk-sw.js' ) );
		return "(function(){if('serviceWorker' in navigator){navigator.serviceWorker.register('$sw').catch(function(){});}})();";
	}
}
