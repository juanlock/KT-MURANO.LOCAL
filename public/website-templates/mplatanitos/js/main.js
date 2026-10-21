/* ---- Slider Principal ----*/
$(document).ready(function() {
  $(".owl-carousel").owlCarousel({
    navigation: false,
    slideSpeed: 300,
    paginationSpeed: 400,
    singleItem: true,
    items: 1,
    autoPlay: true,
  });
  
  $(".carousel-3").owlCarousel({
      autoPlay: false, //Set AutoPlay to 2 seconds 2000
      items: 5,
      itemsMobile: [480,3],
      itemsTablet: [640,3],
      itemsDesktopSmall: [991,3],
      itemsDesktop: [1199,5],
      pagination: true,
      stopOnHover: true,
  });
});
/* ---- Slider - coleccion ----*/