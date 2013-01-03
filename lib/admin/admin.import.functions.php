<?php


    //ini_set('max_execution_time', 300);
    //set_time_limit(300);
    
    

    function zp_db_prep($input)
    {
        $input = str_replace("%", "%%", $input);
        
        return ($input);
    }
    
    
    
    function zp_extract_year($date)
    {
	preg_match_all( '/(\d{4})/', $date, $matches );
	return $matches[0][0];
    }
    
    
    
    function zp_set_update_time( $time )
    {
        update_option("Zotpress_LastAutoUpdate", $time);
    }
    
    
    
    function zp_get_account ($wpdb, $api_user_id_incoming=false)
    {
        
        // Account set or not
        if (isset($_GET['api_user_id']) && preg_match("/^[0-9]+$/", $_GET['api_user_id']) == 1)
            $api_user_id = htmlentities($_GET['api_user_id']);
        else if ($api_user_id_incoming !== false)
            $api_user_id = $api_user_id_incoming;
        else
            $api_user_id = false;
        
        // Get last added user's info
        if ($api_user_id !== false)
            $zp_account = $wpdb->get_results("SELECT * FROM ".$wpdb->prefix."zotpress WHERE api_user_id='".$api_user_id."'");
        else
            $zp_account = $wpdb->get_results("SELECT * FROM ".$wpdb->prefix."zotpress ORDER BY id DESC LIMIT 1");
        
        return $zp_account;
    }



    function zp_get_accounts ($wpdb)
    {
        $zp_accounts = $wpdb->get_results("SELECT api_user_id FROM ".$wpdb->prefix."zotpress");
        
        return $zp_accounts;
    }



    function zp_get_account_haskey ($zp_account)
    {
        // Key-less or not
        if (is_null($zp_account[0]->public_key) === false && trim($zp_account[0]->public_key) != "")
            $nokey = false;
        else
            $nokey = true;
    }
    
    
    
    function zp_clear_last_import ($wpdb, $zp_account, $step)
    {
        switch ($step)
        {
            case "items":
                $wpdb->query("DELETE FROM ".$wpdb->prefix."zotpress_zoteroItems WHERE api_user_id='".$zp_account[0]->api_user_id."'");
                break;
            case "collections":
                $wpdb->query("DELETE FROM ".$wpdb->prefix."zotpress_zoteroCollections WHERE api_user_id='".$zp_account[0]->api_user_id."'");
                break;
            case "tags":
                $wpdb->query("DELETE FROM ".$wpdb->prefix."zotpress_zoteroTags WHERE api_user_id='".$zp_account[0]->api_user_id."'");
                break;
        }
    }
    
    
    
    function zp_get_item_count ($zp_account, $nokey)
    {
        $zp_import_curl = new CURL();
        
        // If there's no key, it's a group account
        if ($nokey === true) {
            $zp_import_url = "https://api.zotero.org/".$zp_account[0]->account_type."/".$zp_account[0]->api_user_id."/items?format=keys";
        } else {
            $zp_import_url = "https://api.zotero.org/".$zp_account[0]->account_type."/".$zp_account[0]->api_user_id."/items?key=".$zp_account[0]->public_key."&format=keys";
        }
        
        // Import depending on method: cURL or file_get_contents
        if (in_array ('curl', get_loaded_extensions())) {
            $zp_xml = $zp_import_curl->get_curl_contents( $zp_import_url, false );
        } else {
            $zp_xml = $zp_import_curl->get_file_get_contents( $zp_import_url, false );
        }
        
        $zp_all_itemkeys_count = count(array_filter(explode("\n", $zp_xml)));
        
        return $zp_all_itemkeys_count;
    }
    
    
    
    function zp_get_items ($wpdb, $zp_account, $nokey, $zp_all_itemkeys_count)
    {
        $zpi = 0;
        
        // Query each group at Zotero
        while ($zpi < $zp_all_itemkeys_count)
        {
            $zp_import_curl = new CURL();
            
            // See if default exists
            $zp_default_style = "apa";
            if (get_option("Zotpress_DefaultStyle"))
                $zp_default_style = get_option("Zotpress_DefaultStyle");
            
            if ($nokey === true)
                $zp_import_url = "https://api.zotero.org/".$zp_account[0]->account_type."/".$zp_account[0]->api_user_id."/items?";
            else // normal with key
                $zp_import_url = "https://api.zotero.org/".$zp_account[0]->account_type."/".$zp_account[0]->api_user_id."/items?key=".$zp_account[0]->public_key."&";
            $zp_import_url .= "format=atom&content=json,bib&style=".$zp_default_style."&limit=50&start=".$zpi;
            
            
            
            // DEBUGGING: Import URL for this set of 50 items
            //echo $zpi.": import url: " . $zp_import_url . "<br /><br />\n";
            
            
            
            if (in_array ('curl', get_loaded_extensions()))
                $zp_xml = $zp_import_curl->get_curl_contents( $zp_import_url, false );
            else // Use the old way:
                $zp_xml = $zp_import_curl->get_file_get_contents( $zp_import_url, false );
            
            // Make it DOM-traversable 
            $doc_citations = new DOMDocument();
            $doc_citations->loadXML($zp_xml);
            
            $entries = $doc_citations->getElementsByTagName("entry");
            
            
            $query = "";
            $query_params = array();
            $query_total_entries = 0;
            
            
            // PREPARE EACH ENTRY FOR DB INSERT
            // Entries can be items or attachments (e.g. notes)
            
            foreach ($entries as $entry)
            {
                $item_type = $entry->getElementsByTagNameNS("http://zotero.org/ns/api", "itemType")->item(0)->nodeValue;
                
                $item_key = $entry->getElementsByTagNameNS("http://zotero.org/ns/api", "key")->item(0)->nodeValue;
                $retrieved = $entry->getElementsByTagName("updated")->item(0)->nodeValue;
                
                // Get citation content (json and bib)
                
                $citation_content = "";
                $citation_content_temp = new DOMDocument();
                
                foreach($entry->getElementsByTagNameNS("http://zotero.org/ns/api", "subcontent") as $child)
                {
                    if ($child->attributes->getNamedItem("type")->nodeValue == "json")
                    {
                        $json_content = $child->nodeValue;
                    }
                    else // Styled citation
                    {
                        foreach($child->childNodes as $child_content) {
                            $citation_content_temp->appendChild($citation_content_temp->importNode($child_content, true));
                            $citation_content = $citation_content_temp->saveHTML();
                        }
                    }
                }
                
                // Get basic metadata from JSON
                $json_content_decoded = json_decode($json_content);
                
                $author = "";
                $date = "";
                $year = "";
                $title = "";
                $numchildren = 0;
                $parent = "";
                $link_mode = "";
                
                if (count($json_content_decoded->creators) > 0)
                    foreach ( $json_content_decoded->creators as $creator )
                        $author .= $creator->lastName . ", ";
                else
                    $author .= $creator->creators["lastName"] . ", ";
                
                $author = substr ($author, 0, strlen($author)-2);
                
                $date = $json_content_decoded->date;
                $year = zp_extract_year($date);
                
                if (trim($year) == "")
                    $year = "1977";
                
                $title = $json_content_decoded->title;
                
                $numchildren = intval($entry->getElementsByTagNameNS("http://zotero.org/ns/api", "numChildren")->item(0)->nodeValue);
                
                // DOWNLOAD: Find URL
                // for attachments, look at zapi:subcontent zapi:type="json" - linkMode - either imported_file or linked_url
                if ($item_type == "attachment")
                {
                    if (isset($json_content_decoded->linkMode))
                        $link_mode = $json_content_decoded->linkMode;
                }
                
                // PARENT
                foreach($entry->getElementsByTagName("link") as $entry_link)
                {
                    if ($entry_link->getAttribute('rel') == "up") {
                        $temp = explode("items/", $entry_link->getAttribute('href'));
                        $temp = explode("?", $temp[1]);
                        $parent = $temp[0];
                    }
                    
                    // Get download URL
                    if ($link_mode == "imported_file" && $entry_link->getAttribute('rel') == "self") {
                        $citation_content = substr($entry_link->getAttribute('href'), 0, strpos($entry_link->getAttribute('href'), "?"));
                    }
                }
                
                
                
                // Insert into db
                array_push($query_params,
                        $zp_account[0]->api_user_id,
                        $item_key,
                        zp_db_prep($retrieved),
                        zp_db_prep($json_content),
                        zp_db_prep($author),
                        zp_db_prep($date),
                        zp_db_prep($year),
                        zp_db_prep($title),
                        $item_type,
                        $link_mode,
                        zp_db_prep($citation_content),
                        zp_db_prep($zp_default_style),
                        $numchildren,
                        $parent);
                
                $query_total_entries++;
                
                
                
                // DEBUGGING:
                //$zp_set++;
                //echo "item #" . $zp_set . "<br /><br />\n";
                //var_dump($query_params);
                //exit();
                
                
            } // entry
            
            $wpdb->query( $wpdb->prepare( 
                "
                    INSERT INTO ".$wpdb->prefix."zotpress_zoteroItems
                    ( api_user_id, item_key, retrieved, json, author, zpdate, year, title, itemType, linkMode, citation, style, numchildren, parent )
                    VALUES ( %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %d, %s )".str_repeat(", ( %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %d, %s )", $query_total_entries-1), 
                $query_params
            ) );
            
            $wpdb->flush();
            
            unset($query);
            unset($query_params);
            unset($query_total_entries);
            unset($zp_import_curl);
            unset($zp_import_url);
            unset($zp_xml);
            unset($doc_citations);
            unset($entries);
            
            // Move to the next set
            $zpi += 50;
            
            //$wpdb->print_error(); // REMOVE
            
            // DEBUGGING: Stop at 150 items; needs debugging above uncommented
            //if ($zp_set >= 150)
            //    continue 2;
            
            
            
        } // while loop - every 50 items
        
    } // FUNCTION: zp_get_items
    
    
    
    function zp_get_collections ($wpdb, $zp_account, $nokey)
    {
        // IMPORT COLLECTIONS
        
        $zp_import_curl = new CURL();
        
        if ($nokey === true)
            $zp_import_url = "https://api.zotero.org/".$zp_account[0]->account_type."/".$zp_account[0]->api_user_id."/collections?limit=50";
        else
            $zp_import_url = "https://api.zotero.org/".$zp_account[0]->account_type."/".$zp_account[0]->api_user_id."/collections?key=".$zp_account[0]->public_key."&limit=50";
        
        if (in_array ('curl', get_loaded_extensions()))
            $zp_xml = $zp_import_curl->get_curl_contents( $zp_import_url, false );
        else // Use the old way:
            $zp_xml = $zp_import_curl->get_file_get_contents( $zp_import_url, false );
        
        
        
        // Make it DOM-traversable 
        $doc_citations = new DOMDocument();
        $doc_citations->loadXML($zp_xml);
        
        // Get request pages to loop through
        $max_page = "";
        $current_page = 0;
        $links = $doc_citations->getElementsByTagName("link");
        
        foreach ($links as $link)
        {
            if ($link->getAttribute('rel') == "last") {
                $max_page = explode("start=", $link->getAttribute('href'));
                $max_page = intval($max_page[1])+50;
                break;
            }
        }
        
        while ($current_page != $max_page)
        {
            // PREPARE EACH ENTRY FOR DB INSERT
            
            $entries = $doc_citations->getElementsByTagName("entry");
            
            
            // TEST: Multi-query
            $query = "";
            $query_params = array();
            $query_total_entries = 0;
            
            
            foreach ($entries as $entry)
            {
                $title = $entry->getElementsByTagName("title")->item(0)->nodeValue;
                $retrieved = $entry->getElementsByTagName("updated")->item(0)->nodeValue;
                $parent = "";
                
                // Get parent collection
                foreach($entry->getElementsByTagName("link") as $link)
                {
                    if ($link->attributes->getNamedItem("rel")->nodeValue == "up")
                    {
                        $parent_temp = explode("/", $link->attributes->getNamedItem("href")->nodeValue);
                        $parent = $parent_temp[count($parent_temp)-1];
                    }
                }
                
                $item_key = $entry->getElementsByTagNameNS("http://zotero.org/ns/api", "key")->item(0)->nodeValue;
                $numCollections = $entry->getElementsByTagNameNS("http://zotero.org/ns/api", "numCollections")->item(0)->nodeValue;
                $numItems = $entry->getElementsByTagNameNS("http://zotero.org/ns/api", "numItems")->item(0)->nodeValue;
                
                unset($zp_import_curl);
                unset($zp_import_url);
                unset($zp_xml);
                
                // GET LIST OF ITEM KEYS
                $zp_import_curl = new CURL();
                
                if ($nokey === true)
                    $zp_import_url = "https://api.zotero.org/".$zp_account[0]->account_type."/".$zp_account[0]->api_user_id."/collections/".$item_key."/items?format=keys";
                else
                    $zp_import_url = "https://api.zotero.org/".$zp_account[0]->account_type."/".$zp_account[0]->api_user_id."/collections/".$item_key."/items?key=".$zp_account[0]->public_key."&format=keys";
                
                // Import depending on method: cURL or file_get_contents
                if (in_array ('curl', get_loaded_extensions()))
                    $zp_xml = $zp_import_curl->get_curl_contents( $zp_import_url, false );
                else // Use the old way:
                    $zp_xml = $zp_import_curl->get_file_get_contents( $zp_import_url, false );
                
                $zp_collection_itemkeys = rtrim(str_replace("\n", ",", $zp_xml), ",");
                
                
                // Insert into db
                array_push($query_params,
                        $zp_account[0]->api_user_id,
                        zp_db_prep($title),
                        zp_db_prep($retrieved),
                        zp_db_prep($parent),
                        $item_key,
                        $numCollections,
                        $numItems,
                        zp_db_prep($zp_collection_itemkeys));
                
                $query_total_entries++;
                
                unset($title);
                unset($retrieved);
                unset($parent);
                unset($item_key);
                unset($numCollections);
                unset($numItems);
                unset($zp_collection_itemkeys);
                
            } // entry
            
            $wpdb->query( $wpdb->prepare( 
                "
                    INSERT INTO ".$wpdb->prefix."zotpress_zoteroCollections
                    ( api_user_id, title, retrieved, parent, item_key, numCollections, numItems, listItems )
                    VALUES ( %s, %s, %s, %s, %s, %d, %d, %s )".str_repeat(", ( %s, %s, %s, %s, %s, %d, %d, %s )", $query_total_entries-1), 
                $query_params
            ) );
            
            $wpdb->flush();
            
            unset($query);
            unset($query_params);
            unset($query_total_entries);
            
            // TEST: Multi-query
            $query = "";
            $query_params = array();
            $query_total_entries = 0;
            
            // MOVE ON TO THE NEXT REQUEST PAGE
            
            $current_page += 50;
            $zp_import_curl = new CURL();
            
            if ($nokey === true)
                $zp_import_url = "https://api.zotero.org/".$zp_account[0]->account_type."/".$zp_account[0]->api_user_id."/collections?limit=50&start=$current_page";
            else
                $zp_import_url = "https://api.zotero.org/".$zp_account[0]->account_type."/".$zp_account[0]->api_user_id."/collections?key=".$zp_account[0]->public_key."&limit=50&start=$current_page";
            
            if (in_array ('curl', get_loaded_extensions()))
                $zp_xml = $zp_import_curl->get_curl_contents( $zp_import_url, false );
            else // Use the old way:
                $zp_xml = $zp_import_curl->get_file_get_contents( $zp_import_url, false );
            
            // Make it DOM-traversable 
            $doc_citations = new DOMDocument();
            $doc_citations->loadXML($zp_xml);
            
        } // while page
        
        unset($zp_import_query);
        unset($zp_import_curl);
        unset($zp_import_url);
        unset($zp_xml);
        unset($doc_citations);
        unset($entries);
        
    } // FUNCTION: zp_get_collections
    
    
    
    function zp_get_tags ($wpdb, $zp_account, $nokey)
    {
        $zp_import_curl = new CURL();
        
        if ($nokey === true)
            $zp_import_url = "https://api.zotero.org/".$zp_account[0]->account_type."/".$zp_account[0]->api_user_id."/tags?limit=50";
        else
            $zp_import_url = "https://api.zotero.org/".$zp_account[0]->account_type."/".$zp_account[0]->api_user_id."/tags?key=".$zp_account[0]->public_key."&limit=50";
        
        if (in_array ('curl', get_loaded_extensions()))
            $zp_xml = $zp_import_curl->get_curl_contents( $zp_import_url, false );
        else // Use the old way:
            $zp_xml = $zp_import_curl->get_file_get_contents( $zp_import_url, false );
        
        // Make it DOM-traversable 
        $doc_citations = new DOMDocument();
        $doc_citations->loadXML($zp_xml);
        
        // Get request pages to loop through
        $max_page = "";
        $current_page = 0;
        $links = $doc_citations->getElementsByTagName("link");
        
        foreach ($links as $link)
        {
            if ($link->getAttribute('rel') == "last") {
                $max_page = explode("start=", $link->getAttribute('href'));
                $max_page = intval($max_page[1])+50;
                break;
            }
        }
        
        while ($current_page != $max_page)
        {
            // PREPARE EACH ENTRY FOR DB INSERT
            
            $entries = $doc_citations->getElementsByTagName("entry");
            
            
            // TEST: Multi-query
            $query = "";
            $query_params = array();
            $query_total_entries = 0;
            
            
            foreach ($entries as $entry)
            {
                $title = $entry->getElementsByTagName("title")->item(0)->nodeValue;
                $retrieved = $entry->getElementsByTagName("updated")->item(0)->nodeValue;
                $numItems = $entry->getElementsByTagNameNS("http://zotero.org/ns/api", "numItems")->item(0)->nodeValue;
                
                
                unset($zp_import_curl);
                unset($zp_import_url);
                unset($zp_xml);
                
                
                // GET LIST OF ITEM KEYS
                $zp_import_curl = new CURL();
                
                if ($nokey === true)
                    $zp_import_url = "https://api.zotero.org/".$zp_account[0]->account_type."/".$zp_account[0]->api_user_id."/tags/".urlencode($title)."/items?format=keys";
                else
                    $zp_import_url = "https://api.zotero.org/".$zp_account[0]->account_type."/".$zp_account[0]->api_user_id."/tags/".urlencode($title)."/items?key=".$zp_account[0]->public_key."&format=keys";
                
                // Import depending on method: cURL or file_get_contents
                if (in_array ('curl', get_loaded_extensions()))
                    $zp_xml = $zp_import_curl->get_curl_contents( $zp_import_url, false );
                else // Use the old way:
                    $zp_xml = $zp_import_curl->get_file_get_contents( $zp_import_url, false );
                
                $zp_tag_itemkeys = rtrim(str_replace("\n", ",", $zp_xml), ",");
                
                
                // Insert into db
                array_push($query_params,
                        $zp_account[0]->api_user_id,
                        zp_db_prep($title),
                        zp_db_prep($retrieved),
                        $numItems,
                        zp_db_prep($zp_tag_itemkeys));
                
                $query_total_entries++;
                
                unset($title);
                unset($retrieved);
                unset($numItems);
                unset($zp_tag_itemkeys);
                
            } // entry
            
            $wpdb->query( $wpdb->prepare( 
                "
                    INSERT INTO ".$wpdb->prefix."zotpress_zoteroTags
                    ( api_user_id, title, retrieved, numItems, listItems )
                    VALUES ( %s, %s, %s, %d, %s )".str_repeat(", ( %s, %s, %s, %d, %s )", $query_total_entries-1), 
                $query_params
            ) );
            
            $wpdb->flush();
            
            unset($query);
            unset($query_params);
            unset($query_total_entries);
            
            
            // TEST: Multi-query
            $query = "";
            $query_params = array();
            $query_total_entries = 0;
            
            
            // MOVE ON TO THE NEXT REQUEST PAGE
            
            $current_page += 50;
            $zp_import_curl = new CURL();
            
            if ($nokey === true)
                $zp_import_url = "https://api.zotero.org/".$zp_account[0]->account_type."/".$zp_account[0]->api_user_id."/tags?limit=50&start=$current_page";
            else
                $zp_import_url = "https://api.zotero.org/".$zp_account[0]->account_type."/".$zp_account[0]->api_user_id."/tags?key=".$zp_account[0]->public_key."&limit=50&start=$current_page";
            
            if (in_array ('curl', get_loaded_extensions()))
                $zp_xml = $zp_import_curl->get_curl_contents( $zp_import_url, false );
            else // Use the old way:
                $zp_xml = $zp_import_curl->get_file_get_contents( $zp_import_url, false );
            
            // Make it DOM-traversable 
            $doc_citations = new DOMDocument();
            $doc_citations->loadXML($zp_xml);
            
        } // while page
        
        unset($zp_import_query);
        unset($zp_import_curl);
        unset($zp_import_url);
        unset($zp_xml);
        unset($doc_citations);
        unset($entries);
        
    } // FUNCTION: zp_get_tags



?>