<?php
/*
Plugin Name: CF Search Highlight
Plugin URI: http://crowdfavorite.com
Description: Plugin that augments searches by highlighting the searched term in the resulting pages.
Version: 1.0.1
Author: Crowd Favorite
Author URI: http://crowdfavorite.com 
*/

// Init/Config
	/**
	 * Toggle search highlighting
	 */
	define('CFHS_HIGHLIGHTSEARCH',apply_filters('cfhs_do_search_highlight',true));

	if(CFHS_HIGHLIGHTSEARCH) {
		define('CFHS_HIGHLIGHT_HASH_PREFIX',apply_filters('cfhs_search_hightlight_hash_prefix','hl-'));
		wp_enqueue_script('jquery-highlight','/index.php?cfhs-search-js',array('jquery'),3);
		wp_enqueue_style('cfs-search-box','/index.php?cfhs-search-css',array(),1,'screen');
	}

	function cfhs_highlight_init() {
		if(isset($_GET['cfhs-search-js'])) {
			cfhs_js();
			exit;
		}
		elseif(isset($_GET['cfhs-search-css'])) {
			cfhs_css();
			exit;
		}
	}
	add_action('init','cfhs_highlight_init');

// Helpers
	
	/**
	 * Convert a search string to an array, honoring quotes as phrase delimiters
	 * This is a direct copy of cfs_search_string_to_array from the CF Advanced Search Plugin
	 *
	 * @TODO better guesswork closing of open quotes?
	 *
	 * @param string $string 
	 * @param bool $search_optimized - if set to false no search augmented parameters are added
	 * @return array
	 */
	function cfhs_search_string_to_array($string,$search_optimized=true) {
		$terms = array();
	
		// handle slashes
		if(!get_magic_quotes_gpc()) {
			$string = stripslashes($string);
		}

		// ghetto solution: simply close an open quote at the end of the search string
		// maybe this should strip out the last one found instead? I don't know.
		if(substr_count($string,'"')%2) {
			$string .= '"';
		}
	
		// grab quoted strings
		$n = preg_match_all('/(".*?")/',$string,$matches);
		$terms = array_merge($terms,$matches[0]);
		$string = preg_replace('/(".*?")/','',$string);
	
		// by default increase a quoted term's relevance
		// don't modify it if a modifier has been supplied
		if($search_optimized) {
			foreach($terms as $key => $term) {
				if($term[0] != '>' && $term[0] != '<') {
					$term = '>'.$term;
				}
				$terms[$key] = '('.$term.')';
			}
		}
	
		// final extraction by space-delimination
		$terms = array_merge($terms,explode(' ',$string));
	
		// trim & yank empty array elements
		$terms = array_map('trim',$terms);
		$terms = array_filter($terms);
	
		return $terms;
	}

// Search Term Highlighting

	/**
	 * Add search term to peramlinks for in-post highlighting
	 *
	 * @param string $permalink 
	 * @return string
	 */
	function cfhs_search_term_in_permalink($permalink) {
		// try to relegate to main body, this could still fire in the sidebar & nav...
		if(defined('CFHS_HIGHLIGHTSEARCH') && CFHS_HIGHLIGHTSEARCH && is_search()) {		
			$terms = cfhs_search_string_to_array($_GET['s'],false);
			foreach($terms as $key => $term) {
				if(strpos($term,'"') !== false) {
					$terms[$key] = urlencode($term);
				}
			}
			$permalink .= '#'.CFHS_HIGHLIGHT_HASH_PREFIX.implode('+',$terms);
		}
		return $permalink;
	}
	add_filter('the_permalink','cfhs_search_term_in_permalink',1000);

	/**
	 * Add javascript to search results page
	 * currently only used to handle search highlighting
	 *
	 * @return void
	 */
	function cfhs_js() {
		header('Content-type: text/javascript');
		if(CFHS_HIGHLIGHTSEARCH) {
			$js .= file_get_contents(WP_PLUGIN_DIR.'/cf-search-highlight/js/jquery.highlight.js');
			$js .= '
jQuery(function($){
	if(window.location.hash && window.location.hash.match(/#'.CFHS_HIGHLIGHT_HASH_PREFIX.'/)) {
		// do highlight
		var cfhs_terms_pre = unescape(window.location.hash.replace("#'.CFHS_HIGHLIGHT_HASH_PREFIX.'","")).split(/(".*?"|\+)/g);
		cfhs_terms = [];
		$(cfhs_terms_pre).each(function(i) {
			if(this.length != 0 && this != undefined && this != "+") {
				cfhs_terms.push(this.replace(/(\\")/g,"").replace(/(^\s+|\s+$|\+)/g," "));
			}
		});
		$(".entry-content, .entry-title, .entry-summary, .entry, .title").highlight(cfhs_terms);

		// search bar
		cfhs_searchbar = $("<div id=\'cfs-search-bar\'></div>");
		$("<span id=\'cfs-search-cancel\' />").append($("<a href=\'3\'>close</a>").click(function(){
			$(".entry-content, .entry-title, .entry-summary, .entry, .title").unhighlight();
			$("#cfs-search-bar").hide();
			$("body").removeClass("cfs-search");
			return false;
		})).appendTo(cfhs_searchbar);
		$("<b>Search:</b>").appendTo(cfhs_searchbar);
		$("<a id=\'cfs-search-previous\'>&laquo; Previous</a>").click(function(){
			cfhs_next_highlight("prev");
			return false;
		}).appendTo(cfhs_searchbar);
		$("<a id=\'cfs-search-next\'>Next &raquo;</a>").click(function(){
			cfhs_next_highlight("next");
			return false;
		}).appendTo(cfhs_searchbar);
		$("<span id=\'cfs-search-notice\' />").appendTo(cfhs_searchbar);
		cfhs_searchbar.wrapInner(\'<div id="cfs-search-bar-inside">\');
		
		$("body").addClass("cfs-search").prepend(cfhs_searchbar);
		
		// Fix this thing to the viewport if it is IE.
		if($.browser.msie && $.browser.version < 7.0) {
			function cfsFixSearchBarToViewPortInIE() {
				$(cfhs_searchbar).css({
					"position": "absolute",
					"top": $(window).scrollTop() + "px"
				});
			}
			cfsFixSearchBarToViewPortInIE();
			$(window).scroll(cfsFixSearchBarToViewPortInIE);
		}
		
		highlighted_items = $(".highlight");
		$(highlighted_items[0]).attr("id","highlight-active")
		cfhs_current_highlight = 0;

		// opera fix for scrolling
		if($.browser.opera) {
			cfhs_scroll_tgt = "html";
		}
		else {
			cfhs_scroll_tgt = "body,html";
		}

		function cfhs_next_highlight(dir) {
			if(dir == "next" || dir == "prev") {		
				var next_highlight = dir == "next" ? parseInt(cfhs_current_highlight)+1 : parseInt(cfhs_current_highlight)-1;

				var _this = $(highlighted_items[cfhs_current_highlight]);
				var _next = $(highlighted_items[next_highlight]);
				
				if (dir == "next" && !_next.hasClass("highlight")) { 
					$("#cfs-search-notice").html("No more results. You are at the last item.");
				}
				else if (dir == "prev" && !_next.hasClass("highlight")) {
					$("#cfs-search-notice").html("No more results. You are at the first item.");
				}
				else {
					$("#cfs-search-notice").html("");
					_this.attr("id","");
					_next.attr("id","highlight-active");
					if(dir == "next") {
						cfhs_current_highlight++;
					}
					else {
						cfhs_current_highlight--;
					}

					$(cfhs_scroll_tgt).animate({ scrollTop: _next.offset().top-100 });
				}
			}
			return;
		}
	}
});
			';
		}
		echo $js;
	}
	
	function cfhs_css() {
		header('Content-type: text/css');
		
		$header_gradient_base64 = 'iVBORw0KGgoAAAANSUhEUgAAABAAAAAkCAMAAAC3xkroAAAAGXRFWHRTb2Z0d2FyZQBBZG9iZSBJbWFnZVJlYWR5ccllPAAAAEtQTFRF3Nzc6enp4+Pj4ODg7Ozs7+/v2NjY7+/v2dnZ5eXl5ubm39/f3t7e5+fn7u7u4uLi7e3t3d3d6urq2tra5OTk4eHh6+vr29vb6OjoozrPHwAAAGlJREFUeNpkyAkOwjAMRNGhoKRN0p3t/ifFFCHQnydLtr8yaHrLU445zk/4owU8XEB38LCBhxPoCR5GUBnL74tHBTSAruChA83g4QYezqAKarXVmPbdaqAVPJgHeNjBQ+pTH5MOcbwEGABy/iBtDF/dCwAAAABJRU5ErkJggg==';
		
		$highlight_gradient_base64 = 'iVBORw0KGgoAAAANSUhEUgAAAAQAAAAYCAMAAADqO6ysAAAAGXRFWHRTb2Z0d2FyZQBBZG9iZSBJbWFnZVJlYWR5ccllPAAAAEhQTFRF/PWu+ON49ddS9dpd/POo+++b/fez88019dNJ+/Gi8cgj8cYb9+Bv/fi4+umL++yT8MQT778I9NA/78EM9t1m+eaC7r4G8sotyrFsAAAAADxJREFUeNocwQcSgCAAwLC6EAcgzv//lJ4JSQTxG8UiJlFEFo/oxC5uMYheRHGKWXxiFZs4xCWqeNUEGADYpARRkbkfzAAAAABJRU5ErkJggg==';
		
		$lt_gray = '#ccc';
		$dk_gray = '#555';
		$height = 33;
		$highlight_h_padding = '4px';
		$highlight_v_padding = '2px';
		
		$css = '
body.cfs-search {
	margin-top: '.$height.'px;
}
#cfs-search-bar {
	background: #ccc url(data:image/png;base64,'.$header_gradient_base64.') top left repeat-x;
	border-top: 1px solid '.$st_gray.';
	border-bottom: 1px solid '.$dk_gray.';
	-moz-box-shadow: 0 0 5px #000;
	-webkit-box-shadow: 0 0 5px #000;
	box-shadow: 0 0 5px #000;
	height: '.$height.'px;
	left: 0;
	line-height: '.$height.'px;
	margin: 0;
	overflow: hidden;
	position: fixed;
	top: 0;
	width: 100%;
	z-index: 9999;
}
#cfs-search-bar * {
	margin: 0;
	padding: 0;
}
#cfs-search-bar-inside {
	padding:0 20px;
}
#cfs-search-bar a,
#cfs-search-bar a:visited{
	color:#000;
	font-weight: bold;
}
a#cfs-search-previous,
a#cfs-search-next {
	background: #eee;
	border: 1px solid #bbb;
	padding: 3px;
	margin-left: 10px;
	cursor: pointer;
	-moz-border-radius:5px;
	-webkit-border-radius:5px;
	-khtml-border-radius:5px;
	border-radius:5px;
}
a#cfs-search-previous:hover,
a#cfs-search-next:hover {
	border-color: #777;
}
a#cfs-search-previous:active,
a#cfs-search-next:active {
	background-color: #ccc;
}
#cfs-search-bar span {
	color: black;
}
#cfs-search-notice {
	margin-left: 10px;
}
#cfs-search-cancel {
	float:right;
}
span.highlight {
	background-color: #fdf8b8;
	padding: '.$highlight_v_padding.' '.$highlight_h_padding.';
	margin: 0 -'.$highlight_h_padding.';
	border: 1px solid #f1c823;
	border-radius:3px;
	-webkit-border-radius:3px;
	-moz-border-radius:3px;
	-khtml-border-radius:3px;
	white-space: nowrap;
}
span#highlight-active {
	background: #eebe06 url(data:image/png;base64,'.$highlight_gradient_base64.') top left repeat-x;
}
';
		
		echo trim($css);
	}

?>