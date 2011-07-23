<?php

	// Include WordPress
	if (!isset( $include ) || $include == false)
		require('../../../wp-load.php');

	if (!defined('WP_USE_THEMES'))
		define('WP_USE_THEMES', false);

	function MakeZotpressRequest(
			$mzr_account_type=false,
			$mzr_api_user_id=false,
			$mzr_data_type=false,
			$mzr_collection_id=false,
			$mzr_item_key=false,
			$mzr_tag_name=false,
			$mzr_limit=false,
			$mzr_displayImages=false,
			$mzr_include=false,
			$mzr_force_recache=false,
			$mzr_instance_id=false,
			$mzr_get_meta=false,
			$mzr_get_children=false,
			$mzr_get_style=false
			)
	{
		// Access Wordpress db
		global $wpdb;
		
		// Include Special cURL
		require('zotpress.rss.curl.php');
		
		// Set up vars
		$zp_xml = "";
		$zp_shortcode_request = "UPDATE ".$wpdb->prefix."zotpress_cache SET ";
		
		
		// SET UP VARS
		
		// API User ID
		if ($mzr_api_user_id == false && $mzr_include == false && isset($_GET['api_user_id'])) {
			$mzr_api_user_id = trim($_GET['api_user_id']);
		}
		$zp_shortcode_request .= "api_user_id='".$mzr_api_user_id."', ";
		
		// Account Type
		if ($mzr_account_type == false && $mzr_include == false && isset($_GET['account_type'])) {
			$mzr_account_type = trim($_GET['account_type']);
		}
		//$zp_shortcode_request .= "account_type='".$mzr_account_type."', ";
		
		// Display Images
		if ($mzr_displayImages !== false) {
			$zp_shortcode_request .= "image='".$mzr_displayImages."', ";
		}
		if ($mzr_displayImages == false && $mzr_include == false && isset($_GET['displayImages'])) {
			$zp_shortcode_request .= "image='".trim($_GET['displayImages'])."', ";
			if (trim($_GET['displayImages']) == "true") {
				$mzr_displayImages = true;
			}
			else {
				$mzr_displayImages = false;
			}
		}
		
		
		
		// MAKE THE REQUEST
		if (isset($mzr_account_type) && isset($mzr_api_user_id))
		{
			
			// IMAGES
			
			if ($mzr_displayImages == true)
			{
				//header('Content-Type: text/xml');
				
				$zp_xml = "<?xml version=\"1.0\" encoding=\"ISO-8859-1\"?>\n";
				
				$image_xml = "";
				
				global $wpdb;
				
				if (isset($_GET['displayImageByCitationID']))
					$images = $wpdb->get_results("SELECT * FROM ".$wpdb->prefix."zotpress_images WHERE citation_id='".trim($_GET['displayImageByCitationID'])."'");
				else
					$images = $wpdb->get_results("SELECT * FROM ".$wpdb->prefix."zotpress_images");
				
				$total = $wpdb->num_rows;
				
				foreach ($images as $image)
					$image_xml .= "	<zpimage citation_id='".$image->citation_id."' account_type='".$image->account_type."' api_user_id='".$image->api_user_id."' image_url='".$image->image."' />\n";
					
				$zp_xml .= "\n<zpimages total=\"".$total."\">\n";
				$zp_xml .= $image_xml;
				$zp_xml .= "</zpimages>";
				
			}
			else
			{
				
				// DATA TYPE
				
				if ($mzr_data_type == false) {
					if (isset($_GET['data_type']) && $mzr_include == false) {
						$urlDataType = trim($_GET['data_type']);
					}
					else {
						$urlDataType = "items/top";
					}
				}
				else {
					$urlDataType = $mzr_data_type;
					if ($urlDataType == "items")
						$urlDataType = "items/top";
				}
				$zp_shortcode_request .= "data_type='".$urlDataType."', ";
				
				
				// LIST
				
				// Collection ID
				if ($mzr_collection_id == false) {
					if (isset($_GET['collection_id']) && $mzr_include == false && trim($_GET['collection_id']) != '') {
						$urlDataType = "collections/".trim($_GET['collection_id'])."/items";
						$zp_shortcode_request .= "collection_id='".trim($_GET['collection_id'])."', ";
					}
					else {
						//$zp_shortcode_request .= "collection_id IS NULL, ";
					}
				}
				else {
					$urlDataType = "collections/".$mzr_collection_id."/items";
					$zp_shortcode_request .= "collection_id='".$mzr_collection_id."', ";
				}
				
				// Item Key
				if ($mzr_item_key != false) {
					$urlDataType = "items/".$mzr_item_key;
					$zp_shortcode_request .= "item_key='".$mzr_item_key."', ";
				}
				else if (isset($_GET['item_key']) && $mzr_include == false && trim($_GET['item_key']) != '') {
					$urlDataType = "items/".trim($_GET['item_key']);
					$zp_shortcode_request .= "item_key='".trim($_GET['item_key'])."', ";
				}
				else {
					//$zp_shortcode_request .= "item_key IS NULL, ";
				}
				
				// Children
				if ($mzr_get_children === true)
				{
					$urlDataType .= "/children";
					$zp_shortcode_request .= "download='yes', ";
				}
				
				
				// Tag Name
				if ($mzr_tag_name == false) {
					if (isset($_GET['tag_name']) && $mzr_include == false && trim($_GET['tag_name']) != '') {
						$urlDataType = "tags/".urlencode(trim($_GET['tag_name']))."/items";
						$zp_shortcode_request .= "tag_name='".trim($_GET['tag_name'])."', ";
					}
					else {
						//$zp_shortcode_request .= "tag_name IS NULL, ";
					}
				}
				else {
					//$urlDataType = "tags/".urlencode($mzr_tag_name)."/items";
					$urlDataType = "tags/".$mzr_tag_name."/items";
					$zp_shortcode_request .= "tag_name='".$mzr_tag_name."', ";
				}
				
				
				// PARAMETERS
				
				// Author
				if (isset($_GET['author']) && trim($_GET['author'] != '')) {
					$author = trim($_GET['author']);
					$zp_shortcode_request .= "author='".trim($_GET['author'])."', ";
				}
				else {
					$author = false;
					//$zp_shortcode_request .= "author IS NULL, ";
				}
				
				// Year
				if (isset($_GET['year']) && trim($_GET['year'] != '')) {
					$year = trim($_GET['year']);
					$zp_shortcode_request .= "year='".trim($_GET['year'])."', ";
				}
				else {
					$year = false;
					//$zp_shortcode_request .= "year IS NULL, ";
				}
				
				// Content
				if (isset($_GET['content'])) {
					$content = "&content=" . $_GET['content'];
					$zp_shortcode_request .= "content='".$_GET['content']."', ";
				}
				else {
					$content = "&content=bib";
					$zp_shortcode_request .= "content='bib', ";
				}
				if ($mzr_get_meta == true) {
					$content = "&content=json";
					$zp_shortcode_request .= "content='json', ";
				}
				
				// Style
				if (isset($_GET['style'])) {
					$style = "&style=" . trim($_GET['style']);
					$zp_shortcode_request .= "style='".trim($_GET['style'])."', ";
				}
				else {
					$style = "&style=apa";
					$zp_shortcode_request .= "style='apa', ";
				}
				if ($mzr_get_style == true) {
					$style = "&style=".$mzr_get_style;
					$zp_shortcode_request .= "style='".$mzr_get_style."', ";
				}
				
				// Order
				if (isset($_GET['order']) && $_GET['order'] != '') {
					$order = "&order=" . $_GET['order'];
					$zp_shortcode_request .= "zporder='".trim($_GET['order'])."', ";
				}
				else {
					$order = false;
					//$zp_shortcode_request .= "zporder IS NULL, ";
				}
				
				// Sort
				if (isset($_GET['sort']) && $_GET['sort'] != '') {
					$sort = "&sort=" . $_GET['sort'];
					$zp_shortcode_request .= "sort='".trim($_GET['sort'])."', ";
				}
				else {
					$sort = false;
					//$zp_shortcode_request .= "sort IS NULL, ";
				}
				
				// Limit
				$zp_shortcode_request_limit = "";
				if ($mzr_limit != false) {
					if ($mzr_limit == -1) {
						$zp_shortcode_request_limit = "zplimit='50'";
						$mzr_limit = false;
					}
					else {
						$zp_shortcode_request_limit = "zplimit='".$mzr_limit."'";
						$mzr_limit = "&limit=".$mzr_limit;
					}
				}
				else {
					if (isset($_GET['limit']) && $mzr_include == false && $_GET['limit'] != '') {
						$mzr_limit = "&limit=".$_GET['limit'];
						$zp_shortcode_request_limit = "zplimit='".$_GET['limit']."'";
					}
					else {
						$zp_shortcode_request_limit = "zplimit='50'";
					}
				}
				
				if ($author || $year) {
					$mzr_limit = false;
					$zp_shortcode_request_limit = "zplimit='50'";
				}
				$zp_shortcode_request .= $zp_shortcode_request_limit;
				
				
				
				// PUBLIC KEY
				$zp_account = $wpdb->get_results("SELECT * FROM ".$wpdb->prefix."zotpress WHERE api_user_id='".$mzr_api_user_id."'");
				$public_key = $zp_account[0]->public_key;
				
				
				
				// GENERATE URL: Users & Groups [& Children]		ASSUMED TO BE SET AS: &format=bib
				
				if (isset( $_GET['children'] ))
					$zp_url = "https://api.zotero.org/".$mzr_account_type."/".$mzr_api_user_id."/".$urlDataType."/".$_GET['children']."/children?key=".$public_key;
				else
					$zp_url = "https://api.zotero.org/".$mzr_account_type."/".$mzr_api_user_id."/".$urlDataType."?key=".$public_key.$content.$style.$order.$sort.$mzr_limit;
				
				//echo "<br />" . $zp_url . "<br />";
				//echo $zp_shortcode_request. "<br /><br /><br />";
				
				
				
				// GET & DISPLAY CITATIONS
				
				$curl = new CURL();
				
				$curl->setRequestUri( $zp_shortcode_request );
				
				if (!$mzr_instance_id)
					$curl->setInstanceId( $_GET['instance_id'] );
				else
					$curl->setInstanceId( $mzr_instance_id );
				
				if (in_array ('curl', get_loaded_extensions()))
					$zp_xml = $curl->get_curl_contents( $zp_url, $mzr_force_recache );
				else // Use the old way:
					$zp_xml = $curl->get_file_get_contents( $zp_url, $mzr_force_recache );
			}
			
			return $zp_xml;
		}
	}
	
	
	
	// DISPLAY XML
	
	if (!isset($include))
		print MakeZotpressRequest();



?>