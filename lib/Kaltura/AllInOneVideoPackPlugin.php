<?php

class Kaltura_AllInOneVideoPackPlugin {
	/**
	 * @var KalturaSanitizer
	 */
	public $_sanitizer = null;

	public function __construct() {
		$this->_sanitizer = new KalturaSanitizer();
	}

	public function init() {

        // show notice on admin pages except for kaltura_options
		if ( ! KalturaHelpers::getOption( 'kaltura_partner_id' ) &&	! isset( $_POST['submit'] ) &&	(!isset( $_GET['page'] ) || 'kaltura_options' !== $_GET['page'])
		) {
			add_action( 'admin_notices', array($this, 'adminWarning' ) );

			return;
		}

		// filters
		add_filter( 'comment_text', array($this, 'commentTextFilter' ) );
		add_filter( 'media_buttons_context', array($this, 'mediaButtonsContextFilter' ) );
		add_filter( 'media_upload_tabs', array($this, 'mediaUploadTabsFilter' ) );
		add_filter( 'mce_external_plugins', array($this, 'mceExternalPluginsFilter' ) );
		add_filter( 'tiny_mce_version', array($this, 'tinyMceVersionFilter' ) );

		// actions
		add_action( 'admin_menu', array($this, 'adminMenuAction' ) );
		add_action( 'wp_enqueue_scripts', array($this, 'enqueueScripts' ) );
		add_action( 'admin_enqueue_scripts', array($this, 'adminEnqueueScripts' ) );

		// media upload actions
		add_action( 'media_upload_kaltura_upload', array($this, 'mediaUploadAction' ) );
		add_action( 'media_upload_kaltura_browse', array($this, 'mediaBrowseAction' ) );
		add_action( 'admin_print_scripts-media-upload-popup', array($this, 'mediaUploadPrintScriptsAction' ) );

		add_action( 'save_post', array($this, 'savePost' ) );
		add_action( 'wp_ajax_kaltura_ajax', array($this, 'executeLibraryController' ) );

		if ( KalturaHelpers::videoCommentsEnabled() ) {
			add_action( 'comment_form', array($this, 'commentFormAction' ) );
		}

		add_shortcode( 'kaltura-widget', array($this, 'shortcodeHandler' ) );

		add_filter( 'parse_request', array($this, 'parseRequest' ) );
	}

	public function adminWarning() {
        $kalturaOptionsPageUrl = admin_url('options-general.php?page=kaltura_options');
		echo '<div class="updated fade">
		    <p>
		        <strong>' . esc_html__( 'To complete the All in One Video Pack installation, ') . '<a href="' . esc_url($kalturaOptionsPageUrl) . '">' . esc_html__('you must get a Partner ID.') . '</a></strong>
		    </p>
		</div>';
	}

	public function mceExternalPluginsFilter( $content ) {
		$content            = $this->_sanitizer->sanitizer( $content, 'arr' );
		$pluginUrl          = KalturaHelpers::getPluginUrl();
		$content['kaltura'] = esc_url_raw($pluginUrl . '/tinymce/kaltura_tinymce.js?v' . KalturaHelpers::getPluginVersion());

		return $content;
	}

	public function tinyMceVersionFilter( $content ) {
		return $content . '_k' . KalturaHelpers::getPluginVersion();
	}

	public function adminMenuAction() {
		add_options_page( 'All in One Video', 'All in One Video', 'manage_options', 'kaltura_options', array($this, 'executeAdminController' ) );
		add_media_page( 'All in One Video', 'All in One Video', 'edit_posts', 'kaltura_library', array($this, 'executeLibraryController' ) );
	}

	public function enqueueScripts() {
		wp_enqueue_style( 'kaltura', KalturaHelpers::cssUrl( 'css/kaltura.css' ), array(), KalturaHelpers::getPluginVersion());
		wp_enqueue_script( 'kaltura', KalturaHelpers::jsUrl( 'js/kaltura.js' ), array('jquery'), KalturaHelpers::getPluginVersion(), false );
	}

	public function adminEnqueueScripts() {
		wp_register_script( 'kaltura-admin', KalturaHelpers::jsUrl( 'js/kaltura-admin.js' ), array(), KalturaHelpers::getPluginVersion(), false );
		wp_register_script( 'kaltura-player-selector', KalturaHelpers::jsUrl( 'js/kaltura-player-selector.js' ), array(), KalturaHelpers::getPluginVersion(), true );
		wp_register_script( 'kaltura-entry-status-checker', KalturaHelpers::jsUrl( 'js/kaltura-entry-status-checker.js' ), array(), KalturaHelpers::getPluginVersion(), true );
		wp_register_script( 'kaltura-editable-name', KalturaHelpers::jsUrl( 'js/kaltura-editable-name.js' ), array(), KalturaHelpers::getPluginVersion(), true );
		wp_register_script( 'kaltura-jquery-validate', KalturaHelpers::jsUrl( 'js/jquery.validate.min.js' ), array(), KalturaHelpers::getPluginVersion(), true );

		wp_enqueue_script( 'kaltura', KalturaHelpers::jsUrl( 'js/kaltura.js' ), array(), KalturaHelpers::getPluginVersion(), false );
		wp_enqueue_style( 'kaltura-admin', KalturaHelpers::cssUrl( 'css/admin.css' ), array(), KalturaHelpers::getPluginVersion() );

		wp_enqueue_style( 'kaltura' );
	}

	function executeLibraryController() {
		if ( ! isset( $_GET['kaction'] ) ) {
			$_GET['kaction'] = 'library';
		}
		$controller = new Kaltura_LibraryController();
		$controller->execute();
	}

	function executeAdminController() {
		$controller = new Kaltura_AdminController();
		$controller->execute();
	}

	public function commentTextFilter( $content ) {
		global $shortcode_tags;

		// we want to run our shortcode and not all
		$shortcode_tags_backup = $shortcode_tags;
		$shortcode_tags        = array();

		add_shortcode( 'kaltura-widget', array( $this, 'shortcodeHandler' ) );
		$content = do_shortcode( $content );

		// restore the original array
		$shortcode_tags = $shortcode_tags_backup;

		return $content;
	}

	public function mediaButtonsContextFilter( $content ) {
		global $post_ID, $temp_ID;
		$content = $this->_sanitizer->sanitizer( $content, 'string' );

		$uploading_iframe_ID       = (int) ( 0 == $post_ID ? $temp_ID : $post_ID );
		$media_upload_iframe_src   = admin_url("media-upload.php?post_id=$uploading_iframe_ID");
		$kaltura_iframe_src        = apply_filters( 'kaltura_iframe_src', "$media_upload_iframe_src&amp;tab=kaltura_upload" );
		$kaltura_title             = esc_attr__( 'Add Kaltura Media' );
		$kaltura_button_src        = KalturaHelpers::getPluginUrl() . '/images/kaltura_button.png';
        $kaltura_iframe_src_final  = $kaltura_iframe_src . "&amp;TB_iframe=true&amp;height=500&amp;width=840";

        $content .= '<a
		    href="' . esc_url($kaltura_iframe_src_final) . '"
		    class="thickbox"
		    title="' . esc_attr__($kaltura_title) . '">
		        <img src="' . esc_url($kaltura_button_src) . '"
		        alt="' . esc_attr__($kaltura_title) . '" />
		    </a>';


		return $content;
	}

	public function mediaUploadTabsFilter( $content ) {
		$content = $this->_sanitizer->sanitizer( $content, 'arr' );

		$content['kaltura_upload'] = esc_html__( 'Add Media' );
		$content['kaltura_browse'] = esc_html__( 'Browse Existing Media' );

		return $content;
	}

	public function mediaUploadTabsFilterOnlyKaltura() {
		$content = array();

		return $this->mediaUploadTabsFilter( $content );
	}

	public function mediaUploadAction() {
		$this->setKalturaOnlyMediaTabs();

		if ( ! isset( $_GET['kaction'] ) ) {
			$_GET['kaction'] = 'upload';
		}

		$controller = new Kaltura_LibraryController();

		wp_iframe( array( $controller, 'execute' ) );
	}

	public function mediaBrowseAction() {
		$this->setKalturaOnlyMediaTabs();

		if ( ! isset( $_GET['kaction'] ) ) {
			$_GET['kaction'] = 'browse';
		}

		$controller = new Kaltura_LibraryController();

		wp_iframe( array( $controller, 'execute' ) );
	}

	public function mediaUploadPrintScriptsAction() {
		wp_enqueue_script( 'kaltura_upload_popup', KalturaHelpers::jsUrl( 'js/upload-popup.js' ), array(), KalturaHelpers::getPluginVersion(), true );
	}

	public function commentFormAction( $post_id ) {
		if (function_exists('jetpack_is_mobile')&& jetpack_is_mobile()) {

            return;
        }

        else if ( wp_is_mobile() ) {
			return;
		}

		$user = wp_get_current_user();
		if ( ! $user->ID && ! KalturaHelpers::anonymousCommentsAllowed() ) {
            $logIn_url = get_option( 'siteurl' ) . '/wp-login.php?redirect_to=' . urlencode( get_permalink() );

			echo 'You must be <a href=' . esc_url($logIn_url) . '>logged in</a> to post a <br /> video comment.';
		} else {
            $openComment_url = site_url() . '?kaltura_iframe_handler';
			$js_click_code = 'Kaltura.openCommentCW("' . esc_url($openComment_url)  . '"); ';
			echo '<input type="button" id="kaltura_video_comment" name="kaltura_video_comment" tabindex="6" value="Add Video Comment" onclick="' . esc_js($js_click_code) . '" />';
		}
	}

	public function shortcodeHandler( $attrs ) {
		// prevent xss
		foreach ( $attrs as $key => $value ) {
			$attrs[$key] = esc_js( $value );
		}

		if ( ! isset( $attrs['entryid'] ) ) {
			return '';
		}

		$viewRenderer = new Kaltura_ViewRenderer();
		ob_start();
		$viewRenderer->renderView( 'embed-code.php', array('attrs' => $attrs) );
		$embedCode = ob_get_clean();

		return $embedCode;
	}

	public function savePost( $postId ) {
		if ( ! KalturaHelpers::getOption( 'kaltura_save_permalink' ) ) {
			return;
		}

		// ignore revisions
		if ( wp_is_post_revision( $postId ) ) {
			return;
		}

		try {
			$kmodel = KalturaModel::getInstance();
			$kmodel->updateEntryPermalink( $postId );
		} catch ( Exception $ex ) {
            trigger_error('An error occurred while updating entry\'s permalink - ' . $ex->getMessage() . ' - ' . $ex->getTraceAsString(), E_USER_NOTICE);
		}
	}

	public function parseRequest( $args ) {
		if ( isset( $_GET['kaltura_iframe_handler'] ) ) {
			nocache_headers();
			$controller = new Kaltura_FrontEndController();
			$controller->execute();
			die;
		} elseif ( isset( $_GET['kaltura_admin_iframe_handler'] ) ) {
			auth_redirect();
			nocache_headers();
			global $show_admin_bar;
			$show_admin_bar = false;

			$controller = new Kaltura_LibraryController();
			// we want to execute our controller before wordpress starts outputting the html
			ob_start();
			$controller->execute();
			$this->controllerOutput = ob_get_clean();
			wp_iframe( 'kalturaGetControllerOutput' );
			die;
		}
	}

	private function setKalturaOnlyMediaTabs() {
		unset( $GLOBALS['wp_filter']['media_upload_tabs'] ); // remove all registerd filters for the tabs
		add_filter( 'media_upload_tabs', array($this, 'mediaUploadTabsFilterOnlyKaltura' ) ); // register our filter for the tabs
		media_upload_header(); // will add the tabs menu
	}
}

function kalturaGetControllerOutput() {
	global $kalturaPlugin;
    echo $kalturaPlugin->controllerOutput;
}

//style div p button br