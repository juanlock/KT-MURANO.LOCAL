(function($) {
    "use strict"; // Start of use strict

    // jQuery for page scrolling feature - requires jQuery Easing plugin
    $('.page-scroll a').bind('click', function(event) {
        var $anchor = $(this);
        $('html, body').stop().animate({
            scrollTop: ($($anchor.attr('href')).offset().top - 50)
        }, 1250, 'easeInOutExpo');
        event.preventDefault();
    });

    // Highlight the top nav as scrolling occurs
    $('body').scrollspy({
        target: '.navbar-fixed-top',
        offset: 51
    });

    // Closes the Responsive Menu on Menu Item Click
    $('.navbar-collapse ul li a').click(function(){ 
        $('.navbar-toggle:visible').click();
    });

    // Offset for Main Navigation
    $('#mainNav').affix({
        offset: {
          top: 100
        }
    })

    $('.parallax-window-slider').parallax({imageSrc: 'img/slider-1.jpg'});
    $('.parallax-window-mapa').parallax({imageSrc: 'img/mapa.jpg'});
    $('.parallax-window-blog').parallax({imageSrc: 'img/slider-blog.jpg'});

})(jQuery); // End of use strict
