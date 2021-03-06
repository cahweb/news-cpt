<?php

/*
 *
 * Plugin Name: Common - News CPT
 * Description: News CPT to be used with all CAH Wordpress Sites for news
 * Author: Alessandro Vecchi
 *
 */

/* Custom Post Type ------------------- */

// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

// Load our CSS if option is on
if(get_option('news_style_option') == "on")
	add_action('wp_enqueue_scripts', 'add_style');

function add_style(){
	wp_register_style('news-style', plugins_url('css/style.css', __FILE__));
	wp_enqueue_style('news-style');
}
// Add create function to init
add_action('init', 'news_create_type');

// Create the custom post type and register it
function news_create_type() {
	$args = array(
	      'label' => 'News',
	        'public' => true,
	        'show_ui' => true,
	        'capability_type' => 'post',
	        'show_in_rest' => true,
	        'hierarchical' => false,
	        'rewrite' => array('slug' => 'news'),
			'menu_icon'  => 'dashicons-format-aside',
	        'query_var' => true,
			'menu_position' => 5,
	        'taxonomies' => array('category'),
	        'supports' => array(
	            'title',
	            'editor',
	            'excerpt',
	            'thumbnail',
				'author',
				'revisions')
	    );
	register_post_type( 'news' , $args );
	register_taxonomy_for_object_type('post_tag', 'news');
}

add_action("admin_init", "news_init");
add_action('save_post', 'news_save');


/*----- API Route Registration -----*/
add_action( 'rest_api_init', function () {
	register_rest_route( 'news', '/sites', array(
		'methods' => 'GET',
		'callback' => 'get_all_sites',
	) );

	register_rest_route( 'news', '/depts', [
		'methods' => WP_REST_Server::READABLE,
		'callback' => 'cah_news_cpt_get_depts',
	]);
} );

function get_all_sites( $data ) {
	$urls = explode(";", get_option('news_list_option'));

	return $urls;
}

function cah_news_cpt_get_depts() {
	$terms = get_terms( [
		'taxonomy' => 'dept',
		'hide_empty' => false,
	]);

	return rest_ensure_response( $terms );
}

/*----- API Meta Registration -----*/
add_action( 'rest_api_init', 'api_register_approved' );
add_action( 'rest_api_init', 'api_register_author' );
add_action( 'rest_api_init', 'api_register_site_name' );
add_action( 'rest_api_init', 'api_register_featured_media' );

function api_register_approved() {
    register_rest_field( 'news',
        'approved',
        array(
            'get_callback'    => 'api_get_approved',
            'update_callback' => 'api_update_approved',
            'schema'          => null,
        )
    );
}

function api_get_approved( $object, $field_name, $request ) {
    return intval(get_post_meta( $object[ 'id' ], $field_name, true ));
}

function api_update_approved($value, $object, $field_name){
	return update_post_meta( $object->ID, $field_name, strip_tags( $value ) );
}

function api_register_author() {
    register_rest_field( 'news',
        'author',
        array(
            'get_callback'    => 'api_get_author',
            'update_callback' => 'api_update_author',
            'schema'          => null,
        )
    );
}

function api_get_author( $object, $field_name, $request ) {
    return get_post_meta( $object[ 'id' ], $field_name, true );
}

function api_update_author($value, $object, $field_name){
	return update_post_meta( $object->ID, $field_name, strip_tags( $value ) );
}

function api_register_site_name() {
    register_rest_field( 'news',
        'site_name',
        array(
            'get_callback'    => 'api_get_site_name',
            'update_callback' => null,
            'schema'          => null,
        )
    );
}

function api_get_site_name( $object, $field_name, $request ) {
    return get_option('blogname');
}

function api_register_featured_media() {
    register_rest_field( 'news',
        'featured_media',
        array(
            'get_callback'    => 'api_get_featured_media',
            'update_callback' => null,
            'schema'          => null,
        )
    );
}

function api_get_featured_media( $object, $field_name, $request ) {
    return wp_get_attachment_url($object["featured_media"]);
}


/*----- Shortcode Functions ------*/
add_shortcode('news', 'news_func');
add_filter('widget_text', 'do_shortcode');

function news_func($atts = [], $content = null, $tag = '') {
	$json = array();
	$urls = explode(";", get_option('news_list_option'));

	// normalize attribute keys, lowercase
    $atts = array_change_key_case((array)$atts, CASE_LOWER);

    // override default attributes with user attributes
    $cah_atts = shortcode_atts(['numposts' => '5', 'approval' => '1'], $atts, $tag);
	
	//point to news.cah.ucf.edu
	$urls = array("news.cah.ucf.edu");
	
	foreach($urls as $url){

		if(is_front_page())
		$file = file_get_contents("https://".trim($url, " ")."/wp-json/wp/v2/news?dept=99&per_page=10");
		else
		$file = file_get_contents("https://".trim($url, " ")."/wp-json/wp/v2/news?dept=99&per_page=100");

		if(empty($file)) {
			//echo ("One of the URLs entered is not a valid Wordpress API instance or does not have the CAH news plugin installed.");
			break;
		}

		$result = json_decode($file);

		foreach($result as $post){
			$post->{"date"} = strtotime($post->{"date"});
		}

		$json = array_merge($result, $json);
	}


	usort($json, function($a, $b) {
		    if ($a->{"date"} == $b->{"date"}) {
		        return 0;
		    }
		    return ( $a->{"date"} > $b->{"date"}) ? -1 : 1;
		}
	);

	echo "<div class=\"cah-news\">";

	$post_amount = $cah_atts['numposts'];
	$approval = $cah_atts['approval'];
	$count = 0;

	foreach($json as $post) {

		if($count == $post_amount)
			break;

		$title = $post->{"title"}->{"rendered"};
		$site_name = $post->{"site_name"};
		$excerpt = $post->{"excerpt"}->{"rendered"};
		$publish_date = date("F j", $post->{"date"});
		$thumbnail = $post->{"featured_media"};
		$thumbnail = preg_replace("/^http:/i", "https:", $thumbnail);
		$url = $post->{"link"};
		
		//$url = str_replace("news.cah.ucf.edu", "cah.ucf.edu", $url);

		if($post->{"approved"} < $approval)
			continue; 

		if($count == 0) { ?>
			<div class="cah-news-feature">
				<div class="cah-news-article" onclick="location.href='<?=$url?>'">

					<div class="cah-news-thumbnail"
						style="background-image: url(<?= (empty($thumbnail)) ? plugins_url('images/empty.png', __FILE__) : $thumbnail; ?>);"></div>

					<div class="cah-news-content">
						<h3 class="cah-news-site"><?=$site_name?></h3>
						<h2 class="cah-news-title"><?=$title?></h2>
													<div class="cah-news-date"><?=$publish_date?></div>
						<div class="cah-news-excerpt"><?=$excerpt?></div>
					</div>
				</div>
			</div>
			
			<div class="cah-news-items">
			<?php

		} 

		else { ?>

			<div class="cah-news-article" onclick="location.href='<?=$url?>'">

				<div class="cah-news-thumbnail"
					style="background-image: url(<?= (empty($thumbnail)) ? plugins_url('images/empty.png', __FILE__) : $thumbnail; ?>);"></div>

				<div class="cah-news-content">
					<h3 class="cah-news-site"><?=$site_name?></h3>
					<h2 class="cah-news-title"><?=$title?></h2>
											<div class="cah-news-date"><?=$publish_date?></div>
					<div class="cah-news-excerpt"><?=$excerpt?></div>
				</div>
			</div>

			<?php
		}

		$count++;
	}

	echo "</div>";
	echo "</div>";
}

// add options
function news_list_option_register_settings() {
   add_option( 'news_list_option', 'on');
   register_setting( 'news_option_group', 'news_list_option', 'news_list_option_callback' );
}

function news_style_option_register_settings() {
   add_option( 'news_style_option', '');
   register_setting( 'news_option_group', 'news_style_option', 'news_style_option_callback' );
}

add_action( 'admin_init', 'news_list_option_register_settings' );
add_action( 'admin_init', 'news_style_option_register_settings' );



function news_list_register_option_page() {
  add_options_page('News Configuration', 'News Configuration', 'manage_options', 'news-list', 'news_list_option_page');
}
add_action('admin_menu', 'news_list_register_option_page');



function news_list_option_page() {
?>
  <div>
	  <h2>News Plugin Configuration</h2>
	  <p>Please enter the urls of each Wordpress Site you wish to pull news from separated by semicolons.</p>
	  <p>(ex. arts.cah.ucf.edu;floridareview.cah.ucf.edu)</p>
	  <form method="post" action="options.php">
		  <?php settings_fields( 'news_option_group' ); ?>
		  <label for="news_list_option">URLs: </label>
		  <input type="text" id="news_list_option" name="news_list_option" value="<?php echo get_option('news_list_option'); ?>">
		  <br>

		  <label for="news_style_option">Use default style: </label>
		  <input type="checkbox" id="news_style_option" name="news_style_option" <?php
		  	if(get_option('news_style_option') == "on")
		  		echo "checked=\"checked\"";
		  ?> />
		  <?php  submit_button(); ?>
	  </form>
  </div>
<?php
}



/*------ Metabox Functions --------*/
function news_init() {
	global $current_user;
	
	if($current_user->roles[0] == 'administrator' ||
	strpos($current_user->user_login, 'vi498123') !== false || 
	strpos($current_user->user_login, 'hgibson') !== false ) {
			add_meta_box("news-admin-meta", "Admin Only", "news_meta_admin", "news", "normal", "high");
	}

	add_meta_box("news-author-meta", "Byline Info", "news_meta_author", "news", "normal", "high");
	add_meta_box("news-featured-image-display-meta", "Ignore Featured Image", "news_meta_display_featured_image", "news", "normal", "low");
}

function news_meta_display_featured_image() {
    	// Toggle display of featured image on individual article page
    global $post;
    $custom = get_post_custom($post->ID);
    if (!isset($custom['ignore_feat_img'][0])) {
		update_post_meta($post->ID, 'ignore_feat_img', false);
    }
    include_once('views/ignore_featured_image_option.php');
}

// Meta box functions
function news_meta_admin() {
	global $post; // Get global WP post var
    $custom = get_post_custom($post->ID); // Set our custom values to an array in the global post var

    // Form markup 
    include_once('views/admin.php');
}

function news_meta_author() {
	global $post; // Get global WP post var
    $custom = get_post_custom($post->ID); // Set our custom values to an array in the global post var

    // Form markup
    include_once('views/author.php');
}

// Save our variables
function news_save() {
	global $post;
if(!empty($post)){
	update_post_meta($post->ID, "approved", $_POST["approved"]);
	update_post_meta($post->ID, "author", $_POST["author"]);
	update_post_meta($post->ID, "ignore_feat_img", $_POST["ignore_feat_img"] ? true : false);}
}

// Settings array. This is so I can retrieve predefined wp_editor() settings to keep the markup clean
$settings = array (
	'sm' => array('textarea_rows' => 3),
	'md' => array('textarea_rows' => 6),
);


?>
