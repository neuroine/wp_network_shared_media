<?php
/**
 * @package Netword_Shared_Media
 * @version 0.5
 */
/*
Plugin Name: Network Shared Media
Plugin URI: http://dekeijzer.org/
Description: This plugin adds a new tab to the "Add Media" window, allowing you to access media in other blogs.
Author: Joost de Keijzer, Aaron Eaton
Version: 0.6
*/

// Add filter that inserts our new tab
function network_shared_media_menu($tabs) {
	$newtab = array('shared_media' => __('Network Shared Media', 'networksharedmedia'));
	return array_merge($tabs, $newtab);
}

// Load media_nsm_process() into the existing iframe
function network_shared_media_upload_shared_media() {
	$nsm = new network_shared_media();
	return wp_iframe(array( $nsm, 'media_upload_shared_media' ), array());
}

function network_shared_media_init() {
	if ( current_user_can('upload_files') ) {
		add_filter('media_upload_tabs', 'network_shared_media_menu');
		add_action('media_upload_shared_media', 'network_shared_media_upload_shared_media');
	}
}
add_action( 'init', 'network_shared_media_init' );

class network_shared_media {
	var $blogs = array();
	var $media_items = '';
	var $current_blog_id;

	function __construct() {
		global $blog_id;
		$this->current_blog_id = $blog_id;

		/* copied from depricated get_blog_list */
		global $wpdb;
		$blogs = $wpdb->get_results( $wpdb->prepare("SELECT blog_id, domain, path FROM $wpdb->blogs WHERE site_id = %d AND public = '1' AND archived = '0' AND mature = '0' AND spam = '0' AND deleted = '0' ORDER BY registered DESC", $wpdb->siteid), ARRAY_A );

		$this->blogs = array();

		foreach ( (array) $blogs as $details ) {
			if( $details['blog_id'] != $this->current_blog_id ) {
				$this->blogs[] = $details;
			}
		}
	}

	function wp_edit_attachments_query( $q = false, $errors ) {
		global $wp_query;
		$post_mime_types = $avail_post_mime_types = array();
		$attachment_count = 0;
		$list_string_output = '';
		foreach( $this->blogs as $blog ) {
			switch_to_blog( $blog['blog_id'] );
			if ( !current_user_can('upload_files') ) continue;

			list( $more_post, $more_avail ) = wp_edit_attachments_query( $q );

			if( count( $post_mime_types ) == 0 ) $post_mime_types = $more_post;

			$avail_post_mime_types = array_merge( $more_avail, $avail_post_mime_types );
			$attachment_count += $wp_query->found_posts;
			$list_string_output .= $this->get_media_items_current_blog(null, $errors);
		}
		switch_to_blog( $this->current_blog_id );

		$avail_post_mime_types = array_values( array_unique( $avail_post_mime_types ) );
		return array( $post_mime_types, $avail_post_mime_types, $attachment_count, $list_string_output );
	}

	function wp_count_attachments( $mime_type = '' ) {
		$stats = array();
		foreach( $this->blogs as $blog ) {
			switch_to_blog( $blog['blog_id'] );
			if ( !current_user_can('upload_files') ) continue;

			$more_stats = (array) wp_count_attachments( $mime_type );
			foreach( $more_stats as $k => $v ) {
				if( array_key_exists( $k, $stats ) ) {
					$stats[$k] += $v;
				} else {
					$stats[$k] = $v;
				}
			}
		}
		switch_to_blog( $this->current_blog_id );

		return (object) $stats;
	}

	function get_media_items_all_blogs( $post_id, $errors ) {
		$output = '';
		foreach( $this->blogs as $blog ) {
			switch_to_blog( $blog['blog_id'] );
			if ( !current_user_can('upload_files') ) continue;

			$output .= $this->get_media_items( $post_id, $errors );
		}
		switch_to_blog( $this->current_blog_id );
		return $output;
	}

	function get_media_items( $post_id, $errors ) {
		global $blog_id;
		$output = get_media_items( $post_id, $errors );

		// remove edit button
		$output = preg_replace( "%<p><input type='button' id='imgedit-open-btn.+?class='imgedit-wait-spin'[^>]+></p>%s", '', $output );

		// remove delete link
		$output = preg_replace( "%<a href='#' class='del-link' onclick=.+?</a>%s", '', $output );
		$output = preg_replace( "%<div id='del_attachment_.+?</div>%s", '', $output );

		return $output;
	}

	/**
	 * {@internal Missing Short Description}}
	 *
	 * @since 2.5.0
	 *
	 * @param unknown_type $errors
	 */
	function media_upload_shared_media($errors) {
		global $wpdb, $wp_query, $wp_locale, $type, $tab, $post_mime_types, $blog_id;
	
		media_upload_header();

		$nsm_blog_id = null;
		if( !array_key_exists( 'blog_id', $_GET ) ) $_GET['blog_id'] = null;

		foreach( $this->blogs as $blog ) {
			if( $_GET['blog_id'] == $blog['blog_id'] ) {
				$nsm_blog_id = $blog['blog_id'];
				break;
			}
		}

		if( null == $nsm_blog_id )
			$nsm_blog_id = $this->blogs[0]['blog_id'];

		switch_to_blog( $nsm_blog_id );
?>

	<div style="float:none;height: 2em;">
	<ul class="subsubsub">
	<?php
	$blog_links = array();
	if ( empty($_GET['blog_id']) )
		$class = ' class="current"';
	else
		$class = '';
	foreach ( $this->blogs as $blog ) {
		$class = '';
		
		if ( $blog['blog_id'] == $blog_id )
			$class = ' class="current"';
	
		$blog_links[] = "<li><a href='" . esc_url(add_query_arg(array('blog_id'=>$blog['blog_id'], 'paged'=>false))) . "'$class>" . $blog['domain'] . '</a>';
	}
	echo "<li>" . __("Select blog:") . "</li>" . implode(' | </li>', $blog_links ) . '</li>';
	unset($blog_links);
	?>
	</ul>
	</div>

<?php
		$post_id = intval($_REQUEST['post_id']);

		// fix to make get_media_item add "Insert" button
		unset($_GET['post_id']);

		$form_action_url = plugins_url( 'media-upload.php', __FILE__ ) . "?type=$type&tab=library&post_id=$post_id&blog_id=$blog_id";
		$form_action_url = apply_filters('media_upload_form_url', $form_action_url, $type);

		$form_class = 'media-upload-form validate';
	
		$_GET['paged'] = isset( $_GET['paged'] ) ? intval($_GET['paged']) : 0;
		if ( $_GET['paged'] < 1 )
			$_GET['paged'] = 1;
		$start = ( $_GET['paged'] - 1 ) * 10;
		if ( $start < 1 )
			$start = 0;
		add_filter( 'post_limits', create_function( '$a', "return 'LIMIT $start, 10';" ) );
	
		list($post_mime_types, $avail_post_mime_types) = wp_edit_attachments_query();
?>
	
	<form id="filter" action="" method="get">
	<input type="hidden" name="type" value="<?php echo esc_attr( $type ); ?>" />
	<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>" />
	<input type="hidden" name="post_id" value="<?php echo (int) $post_id; ?>" />
	<input type="hidden" name="blog_id" value="<?php echo (int) $blog_id; ?>" />
	<input type="hidden" name="post_mime_type" value="<?php echo isset( $_GET['post_mime_type'] ) ? esc_attr( $_GET['post_mime_type'] ) : ''; ?>" />
	
	<p id="media-search" class="search-box">
		<label class="screen-reader-text" for="media-search-input"><?php _e('Search Media');?>:</label>
		<input type="text" id="media-search-input" name="s" value="<?php the_search_query(); ?>" />
		<?php submit_button( __( 'Search Media' ), 'button', '', false ); ?>
	</p>
	
	<ul class="subsubsub">
	<?php
	$type_links = array();
	$_num_posts = (array) wp_count_attachments();
	$matches = wp_match_mime_types(array_keys($post_mime_types), array_keys($_num_posts));
	foreach ( $matches as $_type => $reals )
		foreach ( $reals as $real )
			if ( isset($num_posts[$_type]) )
				$num_posts[$_type] += $_num_posts[$real];
			else
				$num_posts[$_type] = $_num_posts[$real];
	// If available type specified by media button clicked, filter by that type
	if ( empty($_GET['post_mime_type']) && !empty($num_posts[$type]) ) {
		$_GET['post_mime_type'] = $type;
		list($post_mime_types, $avail_post_mime_types) = wp_edit_attachments_query();
	}
	if ( empty($_GET['post_mime_type']) || $_GET['post_mime_type'] == 'all' )
		$class = ' class="current"';
	else
		$class = '';
	$type_links[] = "<li><a href='" . esc_url(add_query_arg(array('post_mime_type'=>'all', 'paged'=>false, 'm'=>false))) . "'$class>".__('All Types')."</a>";
	foreach ( $post_mime_types as $mime_type => $label ) {
		$class = '';
	
		if ( !wp_match_mime_types($mime_type, $avail_post_mime_types) )
			continue;
	
		if ( isset($_GET['post_mime_type']) && wp_match_mime_types($mime_type, $_GET['post_mime_type']) )
			$class = ' class="current"';
	
		$type_links[] = "<li><a href='" . esc_url(add_query_arg(array('post_mime_type'=>$mime_type, 'paged'=>false))) . "'$class>" . sprintf( translate_nooped_plural( $label[2], $num_posts[$mime_type] ), "<span id='$mime_type-counter'>" . number_format_i18n( $num_posts[$mime_type] ) . '</span>') . '</a>';
	}
	echo implode(' | </li>', apply_filters( 'media_upload_mime_type_links', $type_links ) ) . '</li>';
	unset($type_links);
	?>
	</ul>
	
	<div class="tablenav">
	
	<?php
	$page_links = paginate_links( array(
		'base' => add_query_arg( 'paged', '%#%' ),
		'format' => '',
		'prev_text' => __('&laquo;'),
		'next_text' => __('&raquo;'),
		'total' => ceil($wp_query->found_posts / 10),
		'current' => $_GET['paged']
	));
	
	if ( $page_links )
		echo "<div class='tablenav-pages'>$page_links</div>";
	?>
	
	<div class="alignleft actions">
	<?php
	
	$arc_query = "SELECT DISTINCT YEAR(post_date) AS yyear, MONTH(post_date) AS mmonth FROM $wpdb->posts WHERE post_type = 'attachment' ORDER BY post_date DESC";
	
	$arc_result = $wpdb->get_results( $arc_query );
	
	$month_count = count($arc_result);
	
	if ( $month_count && !( 1 == $month_count && 0 == $arc_result[0]->mmonth ) ) { ?>
	<select name='m'>
	<option<?php selected( @$_GET['m'], 0 ); ?> value='0'><?php _e('Show all dates'); ?></option>
	<?php
	foreach ($arc_result as $arc_row) {
		if ( $arc_row->yyear == 0 )
			continue;
		$arc_row->mmonth = zeroise( $arc_row->mmonth, 2 );
	
		if ( isset($_GET['m']) && ( $arc_row->yyear . $arc_row->mmonth == $_GET['m'] ) )
			$default = ' selected="selected"';
		else
			$default = '';
	
		echo "<option$default value='" . esc_attr( $arc_row->yyear . $arc_row->mmonth ) . "'>";
		echo esc_html( $wp_locale->get_month($arc_row->mmonth) . " $arc_row->yyear" );
		echo "</option>\n";
	}
	?>
	</select>
	<?php } ?>
	
	<?php submit_button( __( 'Filter &#187;' ), 'secondary', 'post-query-submit', false ); ?>
	
	</div>
	
	<br class="clear" />
	</div>
	</form>
	
	<form enctype="multipart/form-data" method="post" action="<?php echo esc_attr($form_action_url); ?>" class="<?php echo $form_class; ?>" id="library-form">
	
	<?php wp_nonce_field('media-form'); ?>
	<?php //media_upload_form( $errors ); ?>
	
	<script type="text/javascript">
	<!--
	jQuery(function($){
		var preloaded = $(".media-item.preloaded");
		if ( preloaded.length > 0 ) {
			preloaded.each(function(){prepareMediaItem({id:this.id.replace(/[^0-9]/g, '')},'');});
			updateMediaForm();
		}
	});
	-->
	</script>
	
	<div id="media-items">
	<?php add_filter('attachment_fields_to_edit', 'media_post_single_attachment_fields_to_edit', 10, 2); ?>
	<?php echo $this->get_media_items(null, $errors); ?>
	</div>
	<p class="ml-submit"></p>
	</form>
	<?php
	}
}