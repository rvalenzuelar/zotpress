<?php

	// Include WordPress
	require('../../../wp-load.php');
	define('WP_USE_THEMES', false);

	
	
	$xml = "";

	if (isset($_GET['account_type']) && isset($_GET['api_user_id']))
	{
		
		// IMAGES
		
		if (isset($_GET['displayImages']) && $_GET['displayImages'] == "true")
		{
			header('Content-Type: text/xml');
			
			$xml = "<?xml version=\"1.0\" encoding=\"ISO-8859-1\"?>\n";
			
			$image_xml = "";
			
			global $wpdb;
			
			if (isset($_GET['displayImageByCitationID']))
				$images = $wpdb->get_results("SELECT * FROM ".$wpdb->prefix."zotpress_images WHERE citation_id='".$_GET['displayImageByCitationID']."'");
			else
				$images = $wpdb->get_results("SELECT * FROM ".$wpdb->prefix."zotpress_images");
			
			$total = $wpdb->num_rows;
			
			foreach ($images as $image)
				$image_xml .= "	<zpimage citation_id='".$image->citation_id."' account_type='".$image->account_type."' api_user_id='".$image->api_user_id."' image_url='".$image->image."' />\n";
				
			$xml .= "\n<zpimages total=\"".$total."\">\n";
			$xml .= $image_xml;
			$xml .= "</zpimages>";
		}
		else
		{
			// ACOUNT TYPE
			
			if (isset($_GET['account_type']) && $_GET['account_type'] == "groups")
				$urlAccountType = "groups/";
			else
				$urlAccountType = "users/";
			
			
			// DATA TYPE
			
			if (isset($_GET['data_type']))
				$urlDataType = $_GET['data_type'];
			else
				$urlDataType = "items";
			
			
			// LIST
			
			if (isset($_GET['collection_id']) && trim($_GET['collection_id']) != '')
				$urlDataType = "collections/".$_GET['collection_id']."/items";
			
			if (isset($_GET['item_key']) && trim($_GET['item_key']) != '')
				$urlDataType = "items/".$_GET['item_key'];
			
			if (isset($_GET['tag_name']) && trim($_GET['tag_name']) != '')
				$urlDataType = "tags/".urlencode($_GET['tag_name'])."/items";
			
			
			// PARAMETERS
			
			// Content
			if (isset($_GET['content']))
				$content = "&content=" . $_GET['content'];
			else
				$content = "&content=bib";
			
			// Style
			if (isset($_GET['style']))
				$style = "&style=" . trim($_GET['style']);
			else
				$style = "&style=apa";
			
			// Order
			if (isset($_GET['order']) && $_GET['order'] != '')
				$order = "&order=" . $_GET['order'];
			
			// Sort
			if (isset($_GET['sort']) && $_GET['sort'] != '')
				$sort = "&sort=" . $_GET['sort'];
			
			// Limit
			if (isset($_GET['limit']) && $_GET['limit'] != '')
				$limit = "&limit=" . $_GET['limit'];
			
			// Author
			if (isset($_GET['author']) && trim($_GET['author'] != ''))
				$author = trim($_GET['author']);
			
			
			// ASSUMED: &format=bib
			
			// AUTHOR
			if (isset($author) && strlen($author) > 0)
			{
				if (isset($_GET['public_key']) && $_GET['public_key'] != "")
					$url = "https://api.zotero.org/".$urlAccountType.$_GET['api_user_id']."/".$urlDataType."?key=".$_GET['public_key']."&content=html".$style.$order.$sort;
				else
					$url = "https://api.zotero.org/".$urlAccountType.$_GET['api_user_id']."/".$urlDataType."?".str_replace("&","?","&content=html").$style.$order.$sort;
				//echo $url;
			}
			
			// NO AUTHOR
			else
			{
				if (isset($_GET['public_key']) && $_GET['public_key'] != "")
					$url = "https://api.zotero.org/".$urlAccountType.$_GET['api_user_id']."/".$urlDataType."?key=".$_GET['public_key'].$content.$style.$order.$sort.$limit;
				else
					$url = "https://api.zotero.org/".$urlAccountType.$_GET['api_user_id']."/".$urlDataType."?".str_replace("&","?",$content).$style.$order.$sort.$limit;
			}
				
			//echo $url;
			
			
			// DISPLAY
			
			if (isset($_GET['curl']) && $_GET['curl'] == "true")
			{
				// Eek! Perhaps later.
			}
			else // Use the regular way
			{
				$xml =  file_get_contents($url);
				
			}
		}
		
		
		// DISPLAY XML
		
		print $xml;
	}



?>