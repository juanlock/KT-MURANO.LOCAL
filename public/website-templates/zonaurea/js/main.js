$(document).ready(function () {
    $('.btn-close').fadeOut(200);
    var open = false;
    $('.button a').on('click', function () {
        if (open == false) {
            $('.overlay, .btn-close').fadeIn(200);
            $('nav .btn-open').fadeOut(200);
            open = true;
        } else {
            $('.overlay, .btn-close').fadeOut(200)
            $('nav .btn-open').fadeIn(200);
            open = false;
        }
    });
    $('.overlay').on('mouseup', function () {
        $('.overlay, .btn-close').fadeOut(200);
        $('nav ul li, .btn-open').fadeIn(200);
        open = false;
    });
    $('.wrap').on('mouseup', function () {
        return false;
    });
});


$(window).scroll(function(){
    if($(window).scrollTop()>250)
    {
        $(".barra-nav-scroll").addClass("menu-fixed");
    }
    else{
        $(".barra-nav-scroll").removeClass("menu-fixed");
    }
});

$(document).ready(function() {
  $("#galeria-home").owlCarousel({
      autoPlay: 3000, //Set AutoPlay to 3 seconds 3000
      items : 3,
      itemsDesktop : [1199,3],
      itemsDesktopSmall : [979,3],
  });
});

$(document).ready(function() {
    $("#galeria-blog").owlCarousel({
        autoPlay: false, //Set AutoPlay to 3 seconds
        items : 2,
        itemsDesktop : [1199,2],
        itemsDesktopSmall : [979,2],
        itemsTablet : [768,1]
    });
});