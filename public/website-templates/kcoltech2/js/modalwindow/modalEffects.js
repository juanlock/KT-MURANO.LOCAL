/* ------------------------------------------------------------------
 * Name         : RigelHost - HTML Template
 * File         : modalEffects.js
 * Version      : 1.0
 * Author       : ScanThemes
 * Author URI   : scanthemes@gmail.com
 *
 * Copyright 2018 All Rights Reserved.
/* ------------------------------------------------------------------*/
/* ------------------------------------------------------------------*/

"use strict";

var ModalEffects = (function() {

	function init() {

		var overlay = document.querySelector( '.md-overlay' );

		[].slice.call( document.querySelectorAll( '.md-trigger' ) ).forEach( function( el, i ) {

			var modal = document.querySelector( '#' + el.getAttribute( 'data-modal' ) ),
				  close = modal.querySelector( '.md-close' );

			function removeModal( hasPerspective ) {
				classie.remove( modal, 'md-show' );

				if( hasPerspective ) {
					classie.remove( document.documentElement, 'md-perspective' );
				}
			}

			function removeModalHandler() {
				removeModal( classie.has( el, 'md-setperspective' ) );
			}

			el.addEventListener( 'click', function( ev ) {
				classie.add( modal, 'md-show' );
				overlay.removeEventListener( 'click', removeModalHandler );
				overlay.addEventListener( 'click', removeModalHandler );

				if( classie.has( el, 'md-setperspective' ) ) {
					setTimeout( function() {
						classie.add( document.documentElement, 'md-perspective' );
					}, 25 );
				}
			});

			close.addEventListener( 'click', function( ev ) {
				ev.stopPropagation();
				removeModalHandler();
			});

		} );

	}

	init();

})();

// Modal Content
	var $content1 = $('.md-content .content-1');
	var $content2 = $('.md-content .content-2');

	var c = ($content2.height() > $content1.height()) ? $content2.height() : $content1.height();
	$('.md-content .tabs .content').height(c);

	// Blur Bug Fix
		var
			modal = $('.md-content .content');
	  	if (modal.height() % 2 != 0) { modal.css({ 'height': modal.height() + 1 }); }

// Mini-Tabs Cont
	$(function() {
			$(window).on('resize', function() {
		    var blocks = $('.mini-tabs [class*="content-"]');
		    var maxHeight = 0;

		    blocks.each(function(i, elem){
		        if ($(this).outerHeight(true) > maxHeight) {
		            maxHeight = $(this).outerHeight(true);
		        }

		    });

		    $('.mini-tabs .content').height(maxHeight);
			}).trigger('resize');
	});

// Main-Tabs Cont
	$(function() {
			$(window).on('resize', function() {
		    var blocks = $('.main-tabs [class*="content-"]');
		    var maxHeight = 0;

		    blocks.each(function(i, elem){
		        if ($(this).outerHeight(true) > maxHeight) {
		            maxHeight = $(this).outerHeight(true);
		        }

		    });

		    $('.main-tabs .content').height(maxHeight);
			}).trigger('resize');
	});

