<?php

/*
Plugin Name: Liveinternet Importer
Plugin URI: http://wordpress.org/extend/plugins/liveinternet-importer/
Description: Import posts and comments from Liveinternet.
Author: Seyfer (recode from dmpink.ru)
Author URI: http://seyferseed.div-portal.ru/
Version: 2012.2
Stable tag: 2012.2
License: GPL version 2 or later - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
*/

if ( !defined('WP_LOAD_IMPORTERS') )
	return;

// Load Importer API
require_once ABSPATH . 'wp-admin/includes/import.php';

if ( !class_exists( 'WP_Importer' ) ) {
	$class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
	if ( file_exists( $class_wp_importer ) )
		require_once $class_wp_importer;
}

if ( class_exists( 'WP_Importer' ) ) {
class liru_Import extends WP_Importer {
var $file;
function header() {
	echo '<div class="wrap">';
	echo '<h2>'.__('Import LiveInternet.ru').'</h2>';
}
function footer() {
	echo '</div>';
}
function unhtmlentities($string) { // From php.net for < 4.3 compat
	$trans_tbl = get_html_translation_table(HTML_ENTITIES);
	$trans_tbl = array_flip($trans_tbl);
	return strtr($string, $trans_tbl);
}
function greet() {
	echo '<div class="narrow">';
	echo '<p>'.__('Howdy! Upload your LiveInternet.ru XML export file and we&#8217;ll import the posts into this blog.').'</p>';
	echo '<p>'.__('Choose a LiveInternet.ru XML file to upload, then click Upload file and import.').'</p>';
	wp_import_upload_form("admin.php?import=liru&amp;step=1");
	echo '</div>';
}
function import_posts() {
	global $wpdb, $current_user;
	set_magic_quotes_runtime(0);
	$importdata = file($this->file); // Read the file into an array
	$importdata = implode('', $importdata); // squish it
	$importdata = str_replace(array ("\r\n", "\r"), "\n", $importdata);
	preg_match_all('|<item>(.*?)</item>|is', $importdata, $posts);
	$posts = $posts[1];
	unset($importdata);
	echo '<ol>';
	foreach ($posts as $post) {
	  preg_match('|<title>(.*?)</title>|is', $post, $post_title);
	  $post_title = str_replace(array ('<![CDATA[', ']]>'), '', trim($post_title[1]));
	  $post_title = $wpdb->escape(trim($post_title));
		if ( empty($post_title) ) {
			preg_match('|<link>(.*?)</link>|is', $post, $post_title);
			$post_title = $wpdb->escape(trim($post_title[1]));
		}
		$post_title = iconv("windows-1251","utf-8",$post_title);
		preg_match('|<pubDate>(.*?)</pubDate>|is', $post, $post_date);
		$post_date = $post_date[1];
		$post_date=str_replace(array ('<![CDATA[', ']]>'), '', trim($post_date));
		$post_date = strtotime($post_date);
		$post_date = date('Y-m-d H:i:s', $post_date);
		preg_match('|<description>(.*?)</description>|is', $post, $post_content);
		$post_content = str_replace(array ('<![CDATA[', ']]>'), '', trim($post_content[1]));
		$post_content = $this->unhtmlentities($post_content);
		$post_content = iconv("windows-1251","utf-8",$post_content);
		// Clean up content
		$post_content = preg_replace('|<(/?[A-Z]+)|e', "'<' . strtolower('$1')", $post_content);
		$post_content = str_replace('<br>', '<br />', $post_content);
		$post_content = str_replace('<hr>', '<hr />', $post_content);
		$post_content = $wpdb->escape($post_content);
		$post_author = $current_user->ID;
		$post_status = 'publish';
		$offset = 0;
    $match_count = 0;
    $tags_input = '';
    while(preg_match('|<category>(.*?)</category>|is', $post, $matches, PREG_OFFSET_CAPTURE, $offset))
    {
        $match_count++;
        $match_start = $matches[0][1];
        $match_length = strlen($matches[0][0]);
        $matches[0][0]=str_replace(array ('<![CDATA[', ']]>'), '', trim($matches[0][0]));
        $tags_input = $tags_input.$matches[0][0].',';
        $offset = $match_start + $match_length;
    }
    $tags_input = iconv("windows-1251","utf-8",$tags_input);
		echo '<li>';
		if ($post_id = post_exists($post_title, $post_content, $post_date)) {
			printf(__('Post <em>%s</em> already exists.'), stripslashes($post_title));
		} else {
			printf(__('Importing post <em>%s</em>...'), stripslashes($post_title));
			$postdata = compact('post_author', 'post_date', 'post_content', 'post_title', 'post_status', 'tags_input');
			$post_id = wp_insert_post($postdata);
			if ( is_wp_error( $post_id ) )
				return $post_id;
			if (!$post_id) {
				_e("Couldn't get post ID");
				echo '</li>';
				break;
			}
		}
	}
}
function import() {
	$file = wp_import_handle_upload();
	if ( isset($file['error']) ) {
		echo $file['error'];
		return;
	}
	$this->file = $file['file'];
	$result = $this->import_posts();
	if ( is_wp_error( $result ) )
		return $result;
	wp_import_cleanup($file['id']);
	do_action('import_done', 'LiveInternet.ru');
	echo '<h3>';
	printf(__('All done. <a href="%s">Have fun!</a>'), get_option('home'));
	echo '</h3>';
}
function dispatch() {
	if (empty ($_GET['step']))
		$step = 0;
	else
		$step = (int) $_GET['step'];
  	$this->header();
	switch ($step) {
		case 0 :
			$this->greet();
			break;
		case 1 :
			check_admin_referer('import-upload');
			$result = $this->import();
			if ( is_wp_error( $result ) )
				echo $result->get_error_message();
			break;
	}
	$this->footer();
}
function liru_Import() {
// Nothing.
}
}
}

add_action( 'init', 'liru_Import' );

$liveinternetru_import = new liru_Import();
register_importer('liru', __('LiveInternet.ru'), __('Import posts from a LiveInternet.ru XML export file.'), array ($liveinternetru_import, 'dispatch'));
?>