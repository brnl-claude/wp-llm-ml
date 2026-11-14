<?php
/**
 * Browser language detection, done in the browser so it keeps working with
 * full-page caching. Search engines are never redirected.
 *
 * Modes: "redirect" sends a first-time visitor who lands on a default-language
 * page (the home page, or any page) to the version in their browser
 * language; elsewhere it shows a suggestion bar. "suggest" only shows the bar.
 * A choice made in the switcher is remembered in the functional cookie
 * `wpllmml_lang` and always wins.
 *
 * @package WPLLMML
 */

namespace WPLLMML;

defined( 'ABSPATH' ) || exit;

/**
 * Browser language detection.
 */
class Detect {

	/**
	 * Router.
	 *
	 * @var Router
	 */
	private $router;

	/**
	 * Switcher.
	 *
	 * @var Switcher
	 */
	private $switcher;

	/**
	 * Constructor.
	 *
	 * @param Router   $router   Router.
	 * @param Switcher $switcher Switcher.
	 */
	public function __construct( Router $router, Switcher $switcher ) {
		$this->router   = $router;
		$this->switcher = $switcher;
	}

	/**
	 * Register hooks.
	 */
	public function boot() {
		if ( Languages::is_multilingual() ) {
			add_action( 'wp_head', array( $this, 'print_script' ), 1 );
		}
	}

	/**
	 * "Also available in ..." sentences, written in the target language.
	 *
	 * @return array code => [text, button]
	 */
	public static function messages() {
		$builtin = array(
			'de' => 'Diese Seite ist auch auf Deutsch verfügbar.',
			'en' => 'This page is also available in English.',
			'es' => 'Esta página también está disponible en español.',
			'fr' => 'Cette page est également disponible en français.',
			'it' => 'Questa pagina è disponibile anche in italiano.',
			'nl' => 'Deze pagina is ook beschikbaar in het Nederlands.',
			'pl' => 'Ta strona jest również dostępna w języku polskim.',
			'pt' => 'Esta página também está disponível em português.',
			'ro' => 'Această pagină este disponibilă și în limba română.',
		);
		$out     = array();
		foreach ( Languages::all() as $code => $lang ) {
			$out[ $code ] = array(
				'text'   => isset( $builtin[ $code ] ) ? $builtin[ $code ] : $lang['name'] . '?',
				'button' => $lang['name'],
			);
		}
		return (array) apply_filters( 'wpllmml_suggestion_messages', $out );
	}

	/**
	 * Print the detection script as early as possible in <head>.
	 */
	public function print_script() {
		$mode = Settings::get( 'detect', 'redirect' );
		if ( is_admin() || is_feed() || is_robots() || is_preview() || isset( $_GET['elementor-preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$alternates = array();
		foreach ( $this->switcher->alternates() as $code => $alt ) {
			$alternates[ $code ] = $alt[0];
		}
		$default      = Languages::default_code();
		$can_redirect = 'redirect' === $mode
			&& $this->router->lang === $default
			&& ( 'all' === Settings::get( 'detect_scope', 'home' ) || is_front_page() );

		$config = array(
			'lang'     => $this->router->lang,
			'langs'    => Languages::codes(),
			'alt'      => $alternates,
			'mode'     => $mode,
			'redirect' => $can_redirect,
			'msg'      => self::messages(),
			'close'    => __( 'Close' ), // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- core string.
		);
		?>
<script id="wpllmml-detect">/* LLM Multilingual: language preference & detection (no tracking) */
(function(d,w,c){var K='wpllmml_lang';
function get(){var m=d.cookie.match(/(?:^|;\s*)wpllmml_lang=([a-z0-9_-]+)/);return m?m[1]:'';}
function set(v){d.cookie=K+'='+v+';path=/;max-age=31536000;SameSite=Lax'+(location.protocol==='https:'?';Secure':'');}
d.addEventListener('click',function(e){var a=e.target&&e.target.closest?e.target.closest('[data-wpllmml-lang]'):null;if(a){set(a.getAttribute('data-wpllmml-lang'));}},true);
if(c.mode==='off'||navigator.webdriver||/bot|crawl|spider|slurp|preview|lighthouse|headless|facebookexternalhit/i.test(navigator.userAgent)){return;}
var pref=get(),target=pref;
if(!target){var l=navigator.languages||[navigator.language||''];for(var i=0;i<l.length&&!target;i++){var t=String(l[i]).toLowerCase();if(c.langs.indexOf(t)>-1){target=t;}else if(c.langs.indexOf(t.split('-')[0])>-1){target=t.split('-')[0];}}}
if(!target||target===c.lang||!c.alt[target]){return;}
var internal=d.referrer&&d.referrer.indexOf(location.protocol+'//'+location.host)===0;
if(c.redirect&&!internal){if(!pref){set(target);}w.location.replace(c.alt[target]);return;}
try{if(w.sessionStorage.getItem('wpllmml_dismissed')){return;}}catch(e){}
d.addEventListener('DOMContentLoaded',function(){var m=c.msg[target]||{text:'',button:target},b=d.createElement('div'),s=d.createElement('span'),a=d.createElement('a'),x=d.createElement('button');
b.className='wpllmml-suggest';b.setAttribute('role','region');b.setAttribute('aria-label',m.button);b.lang=target;
s.textContent=m.text+' ';a.href=c.alt[target];a.hreflang=target;a.setAttribute('data-wpllmml-lang',target);a.textContent=m.button+' →';
x.type='button';x.className='wpllmml-suggest__close';x.setAttribute('aria-label',c.close);x.textContent='×';
x.onclick=function(){b.parentNode.removeChild(b);try{w.sessionStorage.setItem('wpllmml_dismissed','1');}catch(e){}};
b.appendChild(s);b.appendChild(a);b.appendChild(x);d.body.appendChild(b);});
})(document,window,<?php echo wp_json_encode( $config ); ?>);</script>
		<?php
	}
}
