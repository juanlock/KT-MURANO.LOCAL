//jQuery to collapse the navbar on scroll
$(window).scroll(function() {
    if ($(".navbar").offset().top > 60) {
        $(".navbar-fixed-top").addClass("top-nav-collapse");
    } else {
        $(".navbar-fixed-top").removeClass("top-nav-collapse");
    }
});

$(window).scroll(function() {
    if ($(".navbar").offset().top > 60) {
        $(".logo").addClass("logo-margen");
    } else {
        $(".logo").removeClass("logo-margen");
    }
});


//jQuery for page scrolling feature - requires jQuery Easing plugin
$(function() {
    $('a.page-scroll').bind('click', function(event) {
        var $anchor = $(this);
        $('html, body').stop().animate({
            scrollTop: $($anchor.attr('href')).offset().top
        }, 1000, 'easeInOutExpo');
        event.preventDefault();
    });
});


//Ocultar el menu responsive al hacer clic
$(function(){
    $(".quitar-barra").click(function(){
        $("#barra-resp").removeClass("in");
    });
});


//Owl-carousel
$(function() {
  $("#owl-demo").owlCarousel({
      navigation : false, // Show next and prev buttons
      slideSpeed : 800,
      paginationSpeed : 800,
      singleItem:true,
      autoPlay: true
  });
});