/* ------------------------------------------------------------------
 * Name         : RigelHost - HTML Template
 * File         : cd-top.js
 * Version      : 1.0
 * Author       : ScanThemes
 * Author URI   : scanthemes@gmail.com
 *
 * Copyright 2018 All Rights Reserved.
/* ------------------------------------------------------------------*/
/* ------------------------------------------------------------------*/

;(function($) {

    'use strict';

    //Default settings:
    var   offset = 400,                 //   px | browser window scroll after which the "back to top" link is shown.
        duration = 500,                 //   ms | duration of the top scrolling animation.
         $cd_top = $('#scrollToTop');   // link | "back to top" button.  
  
    //Check to see if the window is top if not then display button.
    $(window).on('scroll', function() {
      if ($(this).scrollTop() > offset) {
         $cd_top.fadeIn();
      } else {
         $cd_top.fadeOut();
      }
    });
    
    //Click event to scroll to top.
    $cd_top.on('click', function() {
      $('html, body').animate({scrollTop : 0},duration);
      return false;
    });
  
}(window.jQuery));