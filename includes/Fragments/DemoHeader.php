<?php
/**
 * Example fragment: Add a demo header.
 *
 * @package NewfoldLabs\WP\Module\Htaccess\Fragments
 */

namespace NewfoldLabs\WP\Module\Htaccess\Fragments;

use NewfoldLabs\WP\Module\Htaccess\Fragment;
use NewfoldLabs\WP\Module\Htaccess\Context;

class DemoHeader implements Fragment {

	/**
	 * Unique ID.
	 *
	 * @return string
	 */
	public function id() {
		return 'nfd.demo-header';
	}

	/**
	 * Priority — run after WordPress block.
	 *
	 * @return int
	 */
	public function priority() {
		return self::PRIORITY_POST_WP;
	}

	/**
	 * Exclusive block? Yes — only one copy allowed.
	 *
	 * @return bool
	 */
	public function exclusive() {
		return true;
	}

	/**
	 * Always enabled (safe for local testing).
	 *
	 * @param Context $context Context snapshot.
	 * @return bool
	 */
	public function is_enabled( $context ) {
		return true;
	}

	/**
	 * Render the block text.
	 *
	 * @param Context $context Context snapshot.
	 * @return string
	 */
	public function render( $context ) {
		return <<<HT
# BEGIN NFD Demo Header
<IfModule mod_headers.c>
    Header set X-NFD-Demo "Hello from Htaccess Manager"
</IfModule>
# END NFD Demo Header
HT;
	}
}
