/* ------------------------------------------------------------------
 * Name         : RigelHost - HTML Template
 * File         : component.js
 * Version      : 1.2
 * Author       : ScanThemes
 * Author URI   : scanthemes@gmail.com
 *
 * Copyright 2018 All Rights Reserved.
/* ------------------------------------------------------------------*/
/* ------------------------------------------------------------------*/

"use strict";

// Split Module
  var equalheight = function(container){

    var currentTallest = 0,
         currentRowStart = 0,
         rowDivs = new Array(),
         $el,
         topPosition = 0;
    $(container).each(function() {

      $el = $(this);
      $($el).height('auto')
      var topPostion = $el.position().top;

      if (currentRowStart != topPostion) {
        for (var currentDiv = 0 ; currentDiv < rowDivs.length ; currentDiv++) {
          rowDivs[currentDiv].height(currentTallest);
        }
          rowDivs.length = 0; // empty the array
          currentRowStart = topPostion;
          currentTallest = $el.height();
          rowDivs.push($el);
        } else {
          rowDivs.push($el);
          currentTallest = (currentTallest < $el.height()) ? ($el.height()) : (currentTallest);
        }
        for (currentDiv = 0 ; currentDiv < rowDivs.length ; currentDiv++) {
          rowDivs[currentDiv].height(currentTallest);
        }
      });
    }

  $(window).on('load resize', function() {
    var $slt_obj_01 = $('#split-two-wrap h1'),
        $slt_obj_02 = $('#split-two-wrap ul'),

        $slt_obj_03 = $('.generic-fx');

    equalheight($slt_obj_01);
    equalheight($slt_obj_02);
    equalheight($slt_obj_03);

    // HMRmenu resize
    var w_v_hmr = $( window ).width();
    if( matchMedia('only screen and (max-width: 768px)').matches ){
      $('ul#HMRmenu').css('width', w_v_hmr); // matchMedia 
    } else {
      $('ul#HMRmenu').css('width', 'auto'); // auto
    }
  });

  $(function(){
    // Video Play Module
    $('.video-play').on('click', function(){
        var src = $(this).attr('data-vsrc');
        // Adjust the frame settings, if needed.
        var flg = $('<iframe src="" frameborder="0" webkitallowfullscreen mozallowfullscreen allowfullscreen></iframe>').attr('src', src);
        $("#inner-vid").append(flg);
        // Disable Scroll
        $('body').addClass('stop-scrolling');
        return false;
    });

    // Owl Carousel
    $('#testimonial-slider').owlCarousel({
      items: 1,
      itemsDesktop:[1000, 1],
      itemsDesktopSmall:[980, 1],
      itemsTablet:[768, 1],
      itemsMobile:[650, 1],
      pagination: true,
      navigation: false,
      loop:true,
      autoplay: true,
      autoplaySpeed: 1000,
      autoplayTimeout: 3500,
      autoplayHoverPause: true
    });

    // Disable Scroll
    $('.md-trigger').on('click', function(){
      $('body').addClass('stop-scrolling');
    });

  });

// Action Play
  $( ".md-overlay,.vid-close-btn" ).on('click',function(){
    $( "#inner-vid" ).empty();
    // Enable Scroll
    $('body').removeClass('stop-scrolling');
      return false;
  });

// Parallax Background
  /* detect touch */
  if("ontouchstart" in window){
      document.documentElement.className = document.documentElement.className + " touch";
  }
  if(!$("html").hasClass("touch")){
      /* background fix */
      $(".parallax").css("background-attachment", "fixed");
  }

  /* fix vertical when not overflow
  call fullscreenFix() if .fullscreen content changes */
  function fullscreenFix(){
      var h = $('body').height();
      // set .fullscreen height
      $(".content-b").each(function(i){
          if($(this).innerHeight() > h){ $(this).closest(".fullscreen").addClass("overflow");
          }
      });
  }
  $(window).on('resize',fullscreenFix);
  fullscreenFix();

  /* resize background images */
  function backgroundResize(){
    var windowH = $(window).height();
    $(".background").each(function(i){
        var path = $(this);
        // variables
        var contW = path.width();
        var contH = path.height();
        var imgW = path.attr("data-img-width");
        var imgH = path.attr("data-img-height");
        var ratio = imgW / imgH;
        // overflowing difference
        var diff = parseFloat(path.attr("data-diff"));
        diff = diff ? diff : 0;
        // remaining height to have fullscreen image only on parallax
        var remainingH = 0;
        if(path.hasClass("parallax") && !$("html").hasClass("touch")){
            var maxH = contH > windowH ? contH : windowH;
            remainingH = windowH - contH;
        }
        // set img values depending on cont
        imgH = contH + remainingH + diff;
        imgW = imgH * ratio;
        // fix when too large
        if(contW > imgW){
            imgW = contW;
            imgH = imgW / ratio;
        }
        //
        path.data("resized-imgW", imgW);
        path.data("resized-imgH", imgH);
        path.css("background-size", imgW + "px " + imgH + "px");
    });
  }
      $(window).on('resize focus',backgroundResize);
      backgroundResize();

  /* set parallax background-position */
  function parallaxPosition(e){
      var heightWindow = $(window).height();
      var topWindow = $(window).scrollTop();
      var bottomWindow = topWindow + heightWindow;
      var currentWindow = (topWindow + bottomWindow) / 2;
      $(".parallax").each(function(i){
          var path = $(this);
          var height = path.height();
          var top = path.offset().top;
          var bottom = top + height;
          // only when in range
          if(bottomWindow > top && topWindow < bottom){
              var imgW = path.data("resized-imgW");
              var imgH = path.data("resized-imgH");
              // min when image touch top of window
              var min = 0;
              // max when image touch bottom of window
              var max = - imgH + heightWindow;
              // overflow changes parallax
              var overflowH = height < heightWindow ? imgH - height : imgH - heightWindow; // fix height on overflow
              top = top - overflowH;
              bottom = bottom + overflowH;
              // value with linear interpolation
              var value = min + (max - min) * (currentWindow - top) / (bottom - top);
              // set background-position
              var orizontalPosition = path.attr("data-oriz-pos");
              orizontalPosition = orizontalPosition ? orizontalPosition : "50%";
              $(this).css("background-position", orizontalPosition + " " + value + "px");
          }
      });
  }
  if(!$("html").hasClass("touch")){
      $(window).on('resize scroll',parallaxPosition);
      //$(window).focus(parallaxPosition);
      parallaxPosition();
  }
