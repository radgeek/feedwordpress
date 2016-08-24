(function($) {
var fs = {add:'ajaxAdd',del:'ajaxDel',dim:'ajaxDim',process:'process',recolor:'recolor'}, fwpList;

fwpList = {
	settings: {
		url: ajaxurl, type: 'POST',
		response: 'ajax-response',

		what: '',
		alt: 'alternate', altOffset: 0,
		addColor: null, delColor: null, dimAddColor: null, dimDelColor: null,

		confirm: null,
		addBefore: null, addAfter: null,
		delBefore: null, delAfter: null,
		dimBefore: null, dimAfter: null
	},

	nonce: function(e,s) {
		var url = wpAjax.unserialize(e.attr('href'));
		return s.nonce || url._ajax_nonce || $('#' + s.elementbox + ' input[name=_ajax_nonce]').val() || url._wpnonce || $('#' + s.element + ' input[name=_wpnonce]').val() || 0;
	},

	parseClass: function(e,t) {
		var c = [], cl;
		try {
			cl = $(e).attr('class') || '';
			cl = cl.match(new RegExp(t+':[\\S]+'));
			if ( cl ) { c = cl[0].split(':'); }
		} catch(r) {}
		return c;
	},

	pre: function(e,s,a) {
		var bg, r;
		s = $.extend( {}, this.fwpList.settings, {
			element: null,
			nonce: 0,
			target: e.get(0)
		}, s || {} );
		if ( $.isFunction( s.confirm ) ) {
			if ( 'add' != a ) {
				bg = $('#' + s.element).css('backgroundColor');
				$('#' + s.element).css('backgroundColor', '#FF9966');
			}
			r = s.confirm.call(this,e,s,a,bg);
			if ( 'add' != a ) { $('#' + s.element).css('backgroundColor', bg ); }
			if ( !r ) { return false; }
		}
		return s;
	},

	ajaxAdd: function( e, s ) {
		e = $(e);
		s = s || {};
		var list = this, cls = fwpList.parseClass(e,'add'), es, valid, formData;
		s = fwpList.pre.call( list, e, s, 'add' );

		s.element = cls[2] || e.attr( 'id' ) || s.element || null;
		if ( cls[3] ) { s.addColor = '#' + cls[3]; }
		else { s.addColor = s.addColor || '#FFFF33'; }

		if ( !s ) { return false; }

		if ( !e.is('[class^="add:' + list.id + ':"]') ) { return !fwpList.add.call( list, e, s ); }

		if ( !s.element ) { return true; }

		s.action = 'add-' + s.what;

		s.nonce = fwpList.nonce(e,s);

		es = $('#' + s.elementbox + ' :input').not('[name=_ajax_nonce], [name=_wpnonce], [name=action]');
		valid = wpAjax.validateForm( '#' + s.element );
		if ( !valid ) { return false; }

		s.data = $.param( $.extend( { _ajax_nonce: s.nonce, action: s.action }, wpAjax.unserialize( cls[4] || '' ) ) );
		formData = $.isFunction(es.fieldSerialize) ? es.fieldSerialize() : es.serialize();
		if ( formData ) { s.data += '&' + formData; }

		if ( $.isFunction(s.addBefore) ) {
			s = s.addBefore( s );
			if ( !s ) { return true; }
		}
		if ( !s.data.match(/_ajax_nonce=[a-f0-9]+/) ) { return true; }

		s.success = function(r) {
			var res = wpAjax.parseAjaxResponse(r, s.response, s.element), o;
			if ( !res || res.errors ) { return false; }

			if ( true === res ) { return true; }

			jQuery.each( res.responses, function() {
					// FIXME: Causes ownerDocument is undefined breakage in WP3.2
				fwpList.add.call( list, this.data, $.extend( {}, s, { // this.firstChild.nodevalue
					pos: this.position || 0,
					id: this.id || 0,
					oldId: this.oldId || null
				} ) );
			} );

			if ( $.isFunction(s.addAfter) ) {
				o = this.complete;
				this.complete = function(x,st) {
					var _s = $.extend( { xml: x, status: st, parsed: res }, s );
					s.addAfter( r, _s );
					if ( $.isFunction(o) ) { o(x,st); }
				};
			}
			list.fwpList.recolor();
			$(list).trigger( 'fwpListAddEnd', [ s, list.fwpList ] );
			fwpList.clear.call(list,'#' + s.element);
		};

		$.ajax( s );
		return false;
	},

	ajaxDel: function( e, s ) {
		e = $(e); s = s || {};
		var list = this, cls = fwpList.parseClass(e,'delete'), element;
		s = fwpList.pre.call( list, e, s, 'delete' );

		s.element = cls[2] || s.element || null;
		if ( cls[3] ) { s.delColor = '#' + cls[3]; }
		else { s.delColor = s.delColor || '#faa'; }

		if ( !s || !s.element ) { return false; }

		s.action = 'delete-' + s.what;

		s.nonce = fwpList.nonce(e,s);

		s.data = $.extend(
			{ action: s.action, id: s.element.split('-').pop(), _ajax_nonce: s.nonce },
			wpAjax.unserialize( cls[4] || '' )
		);

		if ( $.isFunction(s.delBefore) ) {
			s = s.delBefore( s, list );
			if ( !s ) { return true; }
		}
		if ( !s.data._ajax_nonce ) { return true; }

		element = $('#' + s.element);

		if ( 'none' != s.delColor ) {
			element.css( 'backgroundColor', s.delColor ).fadeOut( 350, function(){
				list.fwpList.recolor();
				$(list).trigger( 'fwpListDelEnd', [ s, list.fwpList ] );
			});
		} else {
			list.fwpList.recolor();
			$(list).trigger( 'fwpListDelEnd', [ s, list.fwpList ] );
		}

		s.success = function(r) {
			var res = wpAjax.parseAjaxResponse(r, s.response, s.element), o;
			if ( !res || res.errors ) {
				element.stop().stop().css( 'backgroundColor', '#faa' ).show().queue( function() { list.fwpList.recolor(); $(this).dequeue(); } );
				return false;
			}
			if ( $.isFunction(s.delAfter) ) {
				o = this.complete;
				this.complete = function(x,st) {
					element.queue( function() {
						var _s = $.extend( { xml: x, status: st, parsed: res }, s );
						s.delAfter( r, _s );
						if ( $.isFunction(o) ) { o(x,st); }
					} ).dequeue();
				};
			}
		};
		$.ajax( s );
		return false;
	},

	ajaxDim: function( e, s ) {
		if ( $(e).parent().css('display') == 'none' ) // Prevent hidden links from being clicked by hotkeys
			return false;
		e = $(e); s = s || {};
		var list = this, cls = fwpList.parseClass(e,'dim'), element, isClass, color, dimColor;
		s = fwpList.pre.call( list, e, s, 'dim' );

		s.element = cls[2] || s.element || null;
		s.dimClass =  cls[3] || s.dimClass || null;
		if ( cls[4] ) { s.dimAddColor = '#' + cls[4]; }
		else { s.dimAddColor = s.dimAddColor || '#FFFF33'; }
		if ( cls[5] ) { s.dimDelColor = '#' + cls[5]; }
		else { s.dimDelColor = s.dimDelColor || '#FF3333'; }

		if ( !s || !s.element || !s.dimClass ) { return true; }

		s.action = 'dim-' + s.what;

		s.nonce = fwpList.nonce(e,s);

		s.data = $.extend(
			{ action: s.action, id: s.element.split('-').pop(), dimClass: s.dimClass, _ajax_nonce : s.nonce },
			wpAjax.unserialize( cls[6] || '' )
		);

		if ( $.isFunction(s.dimBefore) ) {
			s = s.dimBefore( s );
			if ( !s ) { return true; }
		}

		element = $('#' + s.element);
		isClass = element.toggleClass(s.dimClass).is('.' + s.dimClass);
		color = fwpList.getColor( element );
		element.toggleClass( s.dimClass )
		dimColor = isClass ? s.dimAddColor : s.dimDelColor;
		if ( 'none' != dimColor ) {
			element
				.animate( { backgroundColor: dimColor }, 'fast' )
				.queue( function() { element.toggleClass(s.dimClass); $(this).dequeue(); } )
				.animate( { backgroundColor: color }, { complete: function() { $(this).css( 'backgroundColor', '' ); $(list).trigger( 'fwpListDimEnd', [ s, list.fwpList ] ); } } );
		} else {
			$(list).trigger( 'fwpListDimEnd', [ s, list.fwpList ] );
		}

		if ( !s.data._ajax_nonce ) { return true; }

		s.success = function(r) {
			var res = wpAjax.parseAjaxResponse(r, s.response, s.element), o;
			if ( !res || res.errors ) {
				element.stop().stop().css( 'backgroundColor', '#FF3333' )[isClass?'removeClass':'addClass'](s.dimClass).show().queue( function() { list.fwpList.recolor(); $(this).dequeue(); } );
				return false;
			}
			if ( $.isFunction(s.dimAfter) ) {
				o = this.complete;
				this.complete = function(x,st) {
					element.queue( function() {
						var _s = $.extend( { xml: x, status: st, parsed: res }, s );
						s.dimAfter( r, _s );
						if ( $.isFunction(o) ) { o(x,st); }
					} ).dequeue();
				};
			}
		};

		$.ajax( s );
		return false;
	},

	// From jquery.color.js: jQuery Color Animation by John Resig
	getColor: function( el ) {
		if ( el.constructor == Object )
			el = el.get(0);
		var elem = el, color, rgbaTrans = new RegExp( "rgba\\(\\s*0,\\s*0,\\s*0,\\s*0\\s*\\)", "i" );
		do {
			color = jQuery(elem).css('backgroundColor');
			if ( color != '' && color != 'transparent' && !color.match(rgbaTrans) || jQuery.nodeName(elem, "body") )
				break;
		} while ( elem = elem.parentNode );
		return color || '#ffffff';
	},

	add: function( e, s ) {
		e = $(e);
		var list = $(this),
			old = false,
			_s = { pos: 0, id: 0, oldId: null },
			ba, ref, color;

		if ( 'string' == typeof s ) {
			s = { what: s };
		}

		s = $.extend(_s, this.fwpList.settings, s);
		if ( !e.size() || !s.what ) { return false; }
		if ( s.oldId ) { old = $('#' + s.what + '-' + s.oldId); }
		if ( s.id && ( s.id != s.oldId || !old || !old.size() ) ) { $('#' + s.what + '-' + s.id).remove(); }

		if ( old && old.size() ) {
			old.before(e);
			old.remove();
		} else if ( isNaN(s.pos) ) {
			ba = 'after';
			if ( '-' == s.pos.substr(0,1) ) {
				s.pos = s.pos.substr(1);
				ba = 'before';
			}
			ref = list.find( '#' + s.pos );
			if ( 1 === ref.size() ) { ref[ba](e); }
			else { list.append(e); }
		} else if ( s.pos < 0 ) {
			list.prepend(e);
		} else {
			list.append(e);
		}

		if ( s.alt ) {
			if ( ( list.children(':visible').index( e[0] ) + s.altOffset ) % 2 ) { e.removeClass( s.alt ); }
			else { e.addClass( s.alt ); }
		}

		if ( 'none' != s.addColor ) {
			color = fwpList.getColor( e );
			e.css( 'backgroundColor', s.addColor ).animate( { backgroundColor: color }, { complete: function() { $(this).css( 'backgroundColor', '' ); } } );
		}
		list.each( function() { this.fwpList.process( e ); } );
		return e;
	},

	clear: function(e) {
		var list = this, t, tag;
		e = $(e);
		if ( list.fwpList && e.parents( '#' + list.id ).size() ) { return; }
		e.find(':input').each( function() {
			if ( $(this).parents('.form-no-clear').size() )
				return;
			t = this.type.toLowerCase();
			tag = this.tagName.toLowerCase();
			if ( 'text' == t || 'password' == t || 'textarea' == tag ) { this.value = ''; }
			else if ( 'checkbox' == t || 'radio' == t ) { this.checked = false; }
			else if ( 'select' == tag ) { this.selectedIndex = null; }
		});
	},

	process: function(el) {
		var list = this;

		$('[class^="add:' + list.id + ':"]', el || null)
			.filter('form').submit( function() { return list.fwpList.add(this); } ).end()
			.not('form').click( function() { return list.fwpList.add(this); } );
		$('[class^="delete:' + list.id + ':"]', el || null).click( function() { return list.fwpList.del(this); } );
		$('[class^="dim:' + list.id + ':"]', el || null).click( function() { return list.fwpList.dim(this); } );
	},

	recolor: function() {
		var list = this, items, eo;
		if ( !list.fwpList.settings.alt ) { return; }
		items = $('.list-item:visible', list);
		if ( !items.size() ) { items = $(list).children(':visible'); }
		eo = [':even',':odd'];
		if ( list.fwpList.settings.altOffset % 2 ) { eo.reverse(); }
		items.filter(eo[0]).addClass(list.fwpList.settings.alt).end().filter(eo[1]).removeClass(list.fwpList.settings.alt);
	},

	init: function() {
		var lists = this;

		lists.fwpList.process = function(a) {
			lists.each( function() {
				this.fwpList.process(a);
			} );
		};
		lists.fwpList.recolor = function() {
			lists.each( function() {
				this.fwpList.recolor();
			} );
		};
	}
};

$.fn.fwpList = function( settings ) {
	this.each( function() {
		var _this = this;
		this.fwpList = { settings: $.extend( {}, fwpList.settings, { what: fwpList.parseClass(this,'list')[1] || '' }, settings ) };
		$.each( fs, function(i,f) { _this.fwpList[i] = function( e, s ) { return fwpList[f].call( _this, e, s ); }; } );
	} );
	fwpList.init.call(this);
	this.fwpList.process();
	return this;
};

})(jQuery);

/**
 * Admin interface: Uses Username/Parameter UI for Feed Settings.
 *
 */
function feedAuthenticationMethodPress (params) {
	feedAuthenticationMethod({value: 'basic', node: jQuery(this)});
	return false;
}
function feedAuthenticationMethodUnPress (params) {
	feedAuthenticationMethod({value: '-', node: jQuery(this)});
	return false;
}
function feedAuthenticationMethod (params) {
	var s = jQuery.extend({}, {
	init: false,
	value: null,
	node: jQuery(this)
	}, params);
	
	var speed = (s.init ? 0 : 'slow');
	
	var elDiv = jQuery(s.node).closest('.link-rss-authentication');
	var elTable = elDiv.find('table');
	var elMethod = elTable.find('.link-rss-auth-method');
	var elLink = elDiv.find('.link-rss-userpass-use');

	// Set.
	if (s.value != null) {
		elMethod.val(s.value);
	}
	
	if (elMethod.val()=='-') {
		elTable.hide(speed, function () {
			// Just in case. Make sure that we don't duplicate.
			elLink.remove();
			
			jQuery('<a style="display: none" class="add-remove link-rss-userpass-use" href="#">+ Uses username/password</a>')
				.insertAfter(elTable)
				.click(feedAuthenticationMethodPress)
				.show(speed);
		});
	} else {
		elLink.hide(speed, function () { jQuery(this).remove(); } );
		elTable.show(speed);
	} /* if */
} /* function feedAuthenticationMethod () */
 
/**
 * Admin interface: Live category and tag boxes 
 */
 
jQuery(document).ready( function($) {
	// Category boxes
	$('.feedwordpress-category-div').each( function () {
		var this_id = $(this).attr('id');
		var catAddBefore, catAddAfter;
		var taxonomyParts, taxonomy, settingName;
		
		taxonomyParts = this_id.split('-');
		taxonomyParts.shift();	taxonomyParts.shift();
		taxonomy = taxonomyParts.join('-');

		settingName = taxonomy + '_tab';
		if ( taxonomy == 'category' )
			settingName = 'cats';
			
		// No need to worry about tab stuff for our purposes
			
		// Ajax Cat
		var containerId = $(this).attr('id');
		var checkboxId = $(this).find('.categorychecklist').attr('id');
		var newCatId = $(this).find('.new'+taxonomy).attr('id');
		var responseId = $(this).find('.'+taxonomy+'-ajax-response').attr('id');
		var taxAdderId = $(this).find('.'+taxonomy+'-adder').attr('id');

		$(this).find('.new'+taxonomy).one( 'focus', function () { $(this).val('').removeClass('form-input-tip'); } );
		$(this).find('.add-categorychecklist-category-add').click( function() {
			$(this).parent().children('.new'+taxonomy).focus();
		} );
		
		catAddBefore = function (s) {
			if ( !$('#'+newCatId).val() )
				return false;
			s.data += '&' + $( ':checked', '#'+checkboxId ).serialize();
			return s;
		}
		catAddAfter = function (r, s) {
			// Clear out input box
			$('.new' + taxonomy, '#'+this_id).val('');
			
			// Clear out parent dropbox
			var sup, drop = $('.new' + taxonomy + '-parent', '#'+this_id);
			var keep = $('.new' + taxonomy, '#'+this_id);
			
			if ( 'undefined' != s.parsed.responses[0] && (sup = s.parsed.responses[0].supplemental.newcat_parent) ) {
				sup = sup.replace(/id=['"]new[^'"]*_parent['"]/g, 'id="' + keep.attr('id') + '-parent"');
				drop.before(sup);
				$('#'+keep.attr('id')+'-parent').addClass('new' + taxonomy + '-parent');
				drop.remove();
			}
		};
		
		$('#' + checkboxId).fwpList({
			alt: '',
			elementbox: taxAdderId,
			response: responseId,
			addBefore: catAddBefore,
			addAfter: catAddAfter
		});
		
		$(this).find('.category-add-toggle').click( function () {
			$('#' + taxAdderId).toggleClass('wp-hidden-children');
			$('#' + newCatId).focus();
			return false;
		} ); /* $(this).find('.category-add-toggle').click() */

	} ); /* $('.feedwordpress-category-div').each() */
} ); /* jQuery(document).ready() */


function fwp_feedspiper () {
	var data = {
		action: 'fwp_feeds'
	};
	
	return jQuery.ajax({
		type: "POST",
		url: ajaxurl,
		data: data
	});
}

function fwp_feedcontentspiper (feed_id, callbackOK, callbackFail) {
	var data = {
		action: 'fwp_feedcontents',
		feed_id: feed_id
	};
	
	jQuery.ajax({
		type: "POST",
		url: ajaxurl,
		data: data
	})
	.done(function (response) { callbackOK(response); })
	.fail(function (response) { callbackFail(response); });
}

function fwp_feedcontentspicker (feed_id, destination, pickCallback, closeCallback) {
	var picker_id = 'fwp-feed-contents-picker-' + feed_id;
	
	jQuery('<div class="fwp-feed-contents-picker" style="display: none;" id="'+picker_id+'"><p>Loading...</p></div>').insertAfter(destination);
	jQuery('#'+picker_id).show(500);
	
	fwp_feedcontentspiper(feed_id, function (response) {
		var ul = '<h4>Using post...</h4><ul>';
		for (var i=0; i < response.length; i++) {
			ul += '<li><a class="fwp-feed-contents-picker-pick-'+feed_id+'" href="'
				+response[i].guid
				+'">' + response[i].post_title + '</a></li>';
		}
		ul += '</ul>';
		ul += '<a class="fwp-feed-contents-picker-close" href="#" id="fwp-feed-contents-picker-' + feed_id + '-close">x</a>';

		jQuery('#fwp-feed-contents-picker-' + feed_id).html(ul);

		// Set up event handlers.
		jQuery('#fwp-feed-contents-picker-' + feed_id + '-close').click(function (e) {
			jQuery('#fwp-feed-contents-picker-' + feed_id).hide(500, function () { jQuery('#fwp-feed-contents-picker-' + feed_id).remove(); });
			if (typeof(closeCallback)=='function') {
				closeCallback(feed_id);
			}
			e.preventDefault();
			return false;
		});
		jQuery('.fwp-feed-contents-picker-pick-' + feed_id).click(function (e) {
			jQuery('#fwp-feed-contents-picker-' + feed_id).hide(500, function () { jQuery('#fwp-feed-contents-picker-' + feed_id).remove(); });
			if (typeof(pickCallback)=='function') {
				pickCallback(feed_id, jQuery(this).attr('href'));
			}
			e.preventDefault();
			return false;
		});
	},
	function (response) {
		jQuery('#' + picker_id).addClass('error').html('There was a problem getting a listing of the feed. Sorry!').delay(5000).hide(500, function () { jQuery(this).remove(); });
	});
}

function fwp_feedspicker (destination, pickCallback, closeCallback) {
	var dabber = jQuery(destination).attr('id');
	var picker_id = 'fwp-feeds-picker-' + dabber;
	
	jQuery('<div class="fwp-feeds-picker" style="display: none;" id="'+picker_id+'"><p>Loading...</p></div>').insertAfter(destination);
	jQuery('#'+picker_id).show(500);
	
	fwp_feedspiper()
	.done(function (response) {
		var ul = '<h4>Using subscription...</h4><ul>';
		for (var i=0; i < response.length; i++) {
			ul += '<li><a class="fwp-feeds-picker-pick-'+dabber+'" href="#feed-'
				+response[i].id
				+'">' + response[i].name + '</a></li>';
		}
		ul += '</ul>';
		ul += '<a class="fwp-feeds-picker-close" href="#" id="fwp-feeds-picker-' + dabber + '-close">x</a>';

		jQuery('#fwp-feeds-picker-' + dabber).html(ul);;
		
		// Set up event handlers.
		jQuery('#fwp-feeds-picker-' + dabber + '-close').click(function (e) {
			jQuery('#fwp-feeds-picker-' + dabber).hide(500, function () { jQuery('#fwp-feeds-picker-' + dabber).remove(); });
			if (typeof(closeCallback)=='function') {
				closeCallback(destination);
			}
			e.preventDefault();
			return false;
		});
		jQuery('.fwp-feeds-picker-pick-' + dabber).click(function (e) {
			jQuery('#fwp-feeds-picker-' + dabber).hide(500, function () {
				jQuery('#fwp-feeds-picker-' + dabber).remove();
			});
			
			var feed_id = jQuery(this).attr('href').replace(/^#feed-/, '');
			
			if (typeof(pickCallback)=='function') {
				pickCallback(feed_id, jQuery(this));
			}
			e.preventDefault();
			return false;
		});
	})
	.fail(function (response) {
		jQuery('#' + picker_id).addClass('error').html('There was a problem getting a listing of your subscriptions. Sorry!').delay(5000).hide(500, function () { jQuery(this).remove(); });
	});
}

function fwp_xpathtest_ajax (expression, feed_id, post_id) {
	var data = {
	action: 'fwp_xpathtest',
	xpath: expression,
	feed_id: feed_id,
	post_id: post_id
	};
			
	return jQuery.ajax({
		type: "GET",
		url: ajaxurl,
		data: data
	});
}

function fwp_xpathtest_fail (response, result_id, destination) {
	jQuery('<div class="fwp-xpath-test-results error" style="display: none;" id="'+result_id+'">There was a problem communicating with the server.<p>result = <code>'+response+'</code></p></div>').insertAfter(destination);
	jQuery('#'+result_id).show(500).delay(3000).hide(500, function () { jQuery(this).remove(); });
}

function fwp_xpathtest_ok (response, result_id, destination) {
	var dabber = jQuery(destination).attr('id');
	var resultsHtml = '<ul>';
	
	if (response.results instanceof Array) {
		for (var i = 0; i < response.results.length; i++) {
			resultsHtml += '<li>result['+(i+1).toString()+'] = <code>'+response.results[i]+'</code></li>';
		}
	} else {
		resultsHtml += '<li>result = <code>' + response.results + '</code></li>';
	} /* if */
	resultsHtml += '</ul>';
	
	jQuery('<div class="fwp-xpath-test-results" style="display: none;" id="'+result_id+'"><h4>'+response.expression+'</h4>'+resultsHtml+'</code> <a class="fwp-xpath-test-results-close">x</a></div>').insertAfter(destination);
	
	var link_id = 'fwp-xpath-test-results-post-'+dabber; 
	if (jQuery('#'+link_id).length > 0) {
		jQuery('#'+link_id).attr('href', response.guid).html(response.post_title);
	} else {
		jQuery('<div id="contain-'+link_id+'" class="fwp-xpath-test-results-post fwp-xpath-test-setting">Using post: <a id="'+link_id+'" href="'+response.guid+'"> '+response.post_title+'</a> (<a href="#" class="fwp-xpath-test-results-post-change">reset</a>)</div>').insertAfter('#'+result_id);
	} /* if */
	
	jQuery('#'+result_id).find('.fwp-xpath-test-results-close').click(function (e) {
		e.preventDefault();
		
		jQuery('#'+result_id).hide(500, function () { jQuery(this).remove(); });
		return false;
	});
	jQuery('#contain-'+link_id).find('.fwp-xpath-test-results-post-change').click(function (e) {
		e.preventDefault();
		jQuery('#contain-'+link_id).remove();

		return false;
	});
	jQuery('#'+result_id).show(500);
}

function fwp_xpathtest (expression, destination, feed_id) {
	var dabber = jQuery(destination).attr('id');
	var result_id = 'fwp-xpath-test-results-'+dabber;
	var preset_post_id = 'fwp-xpath-test-results-post-'+dabber; 
	var post_id = jQuery('#'+preset_post_id).attr('href');
	
	// Clear out any previous results.
	jQuery('#'+result_id).remove();
	
	if (jQuery('#xpath-test-feed-id-'+dabber).length > 0) {
		feed_id = jQuery('#xpath-test-feed-id-'+dabber).val();		
	}
	
	if ('*' == feed_id) {
	
		fwp_feedspicker(destination, function (feed_id, a) {
			var href = a.attr('href');
			var text = a.text();
			
			jQuery('<div class="fwp-xpath-test-feed-id fwp-xpath-test-setting" id="contain-xpath-test-feed-id-'+dabber+'">Using sub: <a href="'+href+'">'+text+'</a><input type="hidden" id="xpath-test-feed-id-'+dabber+'" name="xpath_test_feed_id" value="'+feed_id+'" /> (<a href="#" class="fwp-xpath-test-feed-id-change">reset</a>)</div>').insertAfter(destination);
			
			jQuery('#contain-xpath-test-feed-id-'+dabber).find('.fwp-xpath-test-feed-id-change').click(function (e) {
				e.preventDefault();
				
				// If there is a post set, we need to reset that
				console.log(('#contain-fwp-xpath-test-results-post-'+dabber), jQuery('#contain-fwp-xpath-test-results-post-'+dabber));
				jQuery('#contain-fwp-xpath-test-results-post-'+dabber).remove();
				
				// Show yourself out.
				jQuery('#contain-xpath-test-feed-id-'+dabber).remove();
				return false;
			});
			
			// Now recursively call the function in order to force
			// a post-picker.
			fwp_xpathtest(expression, destination, feed_id);
		});
	}
	
	// Check for a pre-selected post GUID.
	else if (post_id) {
		fwp_xpathtest_ajax(expression, feed_id, post_id)
			.done( function (response) { fwp_xpathtest_ok(response, result_id, destination); } )
			.fail( function (response) { fwp_xpathtest_fail(response, result_id, destination); } );
	}
	else {
		// Pop up the feed content picker
		fwp_feedcontentspicker(feed_id, destination, function (feed_id, post_id) {
			fwp_xpathtest_ajax(expression, feed_id, post_id)
			.done( function (response) { fwp_xpathtest_ok(response, result_id, destination); } )
			.fail( function (response) { fwp_xpathtest_fail(response, result_id, destination); } );			
		});
	}
	
}

jQuery(document).ready(function($){
	if ( $('.xpath-test').length ) {
		$('.xpath-test').click ( function (e) {
			e.preventDefault();

			// Pull the local expression from the text box
			var expr = jQuery(this).closest('tr').find('textarea').val();
			
			// Check to see if we are on a Feed settings page or
			// on the global settings page;
			var feed_id = jQuery('input[name="save_link_id"]').val();
			
			fwp_xpathtest(expr, jQuery(this), feed_id);
			return false;
		});
	}
	if ( $('.jaxtag').length ) {
		tagBox.init();
	}

	$('.fwpfs').toggle(
		function(){$('.fwpfs').removeClass('slideUp').addClass('slideDown'); setTimeout(function(){if ( $('.fwpfs').hasClass('slideDown') ) { $('.fwpfs').addClass('slide-down'); }}, 10) },
		function(){$('.fwpfs').removeClass('slideDown').addClass('slideUp'); setTimeout(function(){if ( $('.fwpfs').hasClass('slideUp') ) { $('.fwpfs').removeClass('slide-down'); }}, 10) }
	);
	$('.fwpfs').bind(
		'change',
		function () { this.form.submit(); }
	);
	$('#fwpfs-button').css( 'display', 'none' );
	
	$('table.twofer td.active input[type="radio"], table.twofer td.inactive input[type="radio"]').each( function () {
		$(this).click( function () {
			var name = $(this).attr('name');
			var table = $(this).closest('table');
			table.find('td').removeClass('active').addClass('inactive');
			table.find('td:has(input[name="'+name+'"]:checked)').removeClass('inactive').addClass('active');
		} );
		
		var name = $(this).attr('name');
		var table = $(this).closest('table');
		table.find('td').removeClass('active').addClass('inactive');
		table.find('td:has(input[name="'+name+'"]:checked)').removeClass('inactive').addClass('active');
	} );
	
	$('#turn-on-multiple-sources').click ( function () {
		$('#add-single-uri').hide();
		$('#add-multiple-uri').show(600);
		return false;
;
	} );
	$('#turn-off-multiple-sources').click ( function () {
		$('#add-multiple-uri').hide(600);
		$('#add-single-uri').show();
		return false;
	} );
	$('#turn-on-opml-upload').click ( function () {
		$('#add-single-uri').hide();
		$('#upload-opml').show(600);
		return false;
	} );
	$('#turn-off-opml-upload').click ( function () {
		$('#upload-opml').hide(600);
		$('#add-single-uri').show();
		return false;
	} );
});

