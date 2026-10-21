 /*
 * Webslide - v3.1
 * Web Slide - Responsive Mega Menu for Bootstrap 3+
 *
 * Copyright 2016 webthemex
 * http://codecanyon.net/user/webthemex?ref=webthemex
 *
 * Licensed under Envato licenses
 * http://codecanyon.net/licenses/standard
 */
 
 
//=====//General JS//=====//
	$(function() {

	$('#wsnavtoggle').click(function () {
	$('.wsmenucontainer').toggleClass('wsoffcanvasopener');
	$('.wsmenucontainer').removeClass('wsoffcanvasopener02');
	});

	$('.overlapblackbg').click(function () {
		$('.wsmenucontainer').removeClass('wsoffcanvasopener');
		$('.wsmenucontainer').removeClass('wsoffcanvasopener02');
	});
	
	$('#wsnavtoggle02').click(function () {
	$('.wsmenucontainer').toggleClass('wsoffcanvasopener02');
	$('.wsmenucontainer').removeClass('wsoffcanvasopener');
	});
	
	
//=====//First Menu CSS//=====//

$('.wsmenu-list> li').has('.wsmenu-submenu').prepend('<span class="wsmenu-click"><i class="wsmenu-arrow fa icon-icon-50"></i></span>');
$('.wsmenu-list > li').has('.megamenu').prepend('<span class="wsmenu-click"><i class="wsmenu-arrow fa icon-icon-50"></i></span>');


$('.wsmenu-click').click(function(){
  	$(this).toggleClass('ws-activearrow')
    .parent().siblings().children().removeClass('ws-activearrow');

	$(".wsmenu-submenu, .megamenu").not($(this).siblings('.wsmenu-submenu, .megamenu')).slideUp('slow');
	$(this).siblings('.wsmenu-submenu').slideToggle('slow');
	$(this).siblings('.megamenu').slideToggle('slow');	

});

//SUB Menu UL SHOW JS
$('.wsmenu-list > li > ul > li').has('.wsmenu-submenu-sub').prepend('<span class="wsmenu-click02"><i class="wsmenu-arrow fa icon-icon-50"></i></span>');
$('.wsmenu-list > li > ul > li > ul > li').has('.wsmenu-submenu-sub-sub').prepend('<span class="wsmenu-click02"><i class="wsmenu-arrow fa icon-icon-50"></i></span>');

$('.wsmenu-click02').click(function(){
	$(this).children('.wsmenu-arrow').toggleClass('wsmenu-rotate');
	$(this).siblings('.wsmenu-submenu-sub').slideToggle('slow');
	$(this).siblings('.wsmenu-submenu-sub-sub').slideToggle('slow');

});
 	
	


//=====///Second menu JS//=====//

$('.wsmenu02-list> li').has('.wsmenu02-submenu').prepend('<span class="wsmenu02-click"><i class="wsmenu-arrow fa icon-icon-50"></i></span>');
$('.wsmenu02-list > li').has('.megamenu02').prepend('<span class="wsmenu02-click"><i class="wsmenu-arrow fa icon-icon-50"></i></span>');

$('.wsmenu02-click').click(function(){
  	$(this).toggleClass('ws-activearrow02')
    .parent().siblings().children().removeClass('ws-activearrow02');

	$(".wsmenu02-submenu, .megamenu02").not($(this).siblings('.wsmenu02-submenu, .megamenu02')).slideUp('slow');
	$(this).siblings('.wsmenu02-submenu').slideToggle('slow');
	$(this).siblings('.megamenu02').slideToggle('slow');	

});

//MAIN Menu UL SHOW/HIDE JS

//SUB Menu UL SHOW JS
$('.wsmenu02-list > li > ul > li').has('.wsmenu02-submenu-sub').prepend('<span class="wsmenu02-click02"><i class="wsmenu-arrow fa icon-icon-50"></i></span>');
$('.wsmenu02-list > li > ul > li > ul > li').has('.wsmenu02-submenu-sub-sub').prepend('<span class="wsmenu02-click02"><i class="wsmenu-arrow fa icon-icon-50"></i></span>');

$('.wsmenu02-click02').click(function(){
	$(this).children('.wsmenu02-arrow').toggleClass('wsmenu02-rotate');
	$(this).siblings('.wsmenu02-submenu-sub').slideToggle('slow');
	$(this).siblings('.wsmenu02-submenu-sub-sub').slideToggle('slow');

});
	//SUB Menu UL SHOW JS	
	});