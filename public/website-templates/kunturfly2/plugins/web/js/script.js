$(document).ready(function() {

    $(window).scroll(function() {
        if ($(this).scrollTop() > 100) {
            $('.scrollup').fadeIn();
        } else {
            $('.scrollup').fadeOut();
        }
    });

    $('.scrollup').click(function() {
        $("html, body").animate({scrollTop: 0}, 600);
        return false;
    });

    $("#v_basico").on("click", function(){
    	$("#vuelo").prop("checked", true);
    });

    $("#v_vip").on("click", function(){
    	$("#vuelo").prop("checked", true);
    });
    $('select').material_select();

    /*
    $("#fecha").change(function () {
        $.post( "action.php", {
            action: 'fecha_vuelo',
            vuelo: $("input[name=vuelo]:checked").val(),
            fecha: $(this).val()
        })
        .done(function( data ) {
            console.log(data['15:00']);
            console.log(data);
          });
    });
    */
    $("#fecha").on("change", function() {
        $.post('action.php',
                {action: 'fecha_vuelo', vuelo: $("input[name=vuelo]:checked").val(), fecha: $(this).val()},
        function(response) {
            console.log(response);
            console.log(response['6:00 pm']);
            d9 = '';
            d10 = '';
            d11 = '';
            d12 = '';
            d13 = '';
            d14 = '';
            d15 = '';
            d16 = '';
            d17 = '';
            d18 = '';

            if(response['09:00 am'] >= 2){
                d9 = 'disabled';
            }

            if(response['10:00 am'] >= 2){
                d10 = 'disabled';
            }

            if(response['11:00 am'] >= 2){
                d11 = 'disabled';
            }

            if(response['12:00 pm'] >= 2){
                d12 = 'disabled';
            }

            if(response['01:00 pm'] >= 2){
                d13 = 'disabled';
            }

            if(response['02:00 pm'] >= 2){
                d14 = 'disabled';
            }

            if(response['03:00 pm'] >= 2){
                d15 = 'disabled';
            }

            if(response['04:00 pm'] >= 2){
                d16 = 'disabled';
            }

            if(response['05:00 pm'] >= 2){
                d17 = 'disabled';
            }

            if(response['06:00 pm'] >= 2){
                d18 = 'disabled';
            }

            $("#hor").html('<div class="slct-1wrp1"><i class="material-icons prefix">query_builder</i><select class="select-dropdown" name="horario" id="horario"><option value="" disabled selected>Seleccionar horario</option><option value="9" ' + d9 + '>09:00 am</option><option value="10" ' + d10 + '>10:00 am</option><option value="11" ' + d11 + '>11:00 am</option><option value="12" ' + d12 + '>12:00 pm</option><option value="13" ' + d13 + '>01:00 pm</option><option value="14" ' + d14 + '>02:00 pm</option><option value="15" ' + d15 + '>03:00 pm</option><option value="16" ' + d16 + '>04:00 pm</option><option value="17" ' + d17 + '>05:00 pm</option><option value="18" ' + d18 + '>06:00 pm</option></select></div>');

            
            //$("#horario").append('<option value="18" ' + d18 + '>18:00</option>');
            
            //$(".r_color").empty();
            /*
            var total = Object.keys(response).length;
            var html = '';
            for (var i = 0; i < total; i++) {
                if (response[i].id_color == 1) {
                    html += '<input type="radio" class="color" name="color" value="' + response[i].id_color + '"  onclick="javascript:showPrecio()"/><img style="width: 22px;" src="' + base_url + 'public/img/colores/colores.png">';
                } else {
                    html += '<input type="radio" class="color" name="color" value="' + response[i].id_color + '"  onclick="javascript:showPrecio()"/><span style=" background: ' + response[i].color + '; color:' + response[i].id_color + '">___</span>';
                }
            }
            $(".r_color").wrapInner(html);
            */
            //$('#p_precio').text('S/' + response.precio);
            //$('#p_oferta').text('S/' + (100 - response.oferta) * response.precio / 100 + ' ');
        }, 'json');
    });


});

/*LOADING PAGE*/
$(document).ready(function () {
    setTimeout(function () {
        $('body').addClass('loaded');
    }, 2000);

});


/*GOOGLE MAPS*/
$('.maps').click(function () {
    $('.maps iframe').css("pointer-events", "auto");
});

$(".maps").mouseleave(function () {
    $('.maps iframe').css("pointer-events", "none");
});


/*-- MENU - FIXED --*/
var altura = ($('.navigation-allgs').offset() || {"top": NaN}).top;
$(window).on('scroll', function () {
    if ($(window).scrollTop() > altura) {
        $('.eonav-cntfluid').addClass('menu-fixed');
    } else {
        $('.eonav-cntfluid').removeClass('menu-fixed');
    }
});

var mediaquery = window.matchMedia("(max-width: 992px)");
if (mediaquery.matches) {
   $(function () {
	    $('a[href*=#]').click(function () {
	        if (location.pathname.replace(/^\//, '') == this.pathname.replace(/^\//, '')
	                && location.hostname == this.hostname) {
	            var $target = $(this.hash);
	            $target = $target.length && $target || $('[name=' + this.hash.slice(1) + ']');

	            if ($('.menu-fixed').css('position') === 'fixed') {
	                if ($target.length) {
	                    var targetOffset = $target.offset().top - 100;
	                    $('html,body').animate({scrollTop: targetOffset}, 1000);
$('.btn-colp').sideNav('hide');
	                    return false;
	                }
	            } else {
	                if ($target.length) {
	                    var targetOffset = $target.offset().top - 195;
	                    $('html,body').animate({scrollTop: targetOffset}, 1000);
$('.btn-colp').sideNav('hide');
	                    return false;
	                }
	            }

	        }
	    });
	});
} else {
	$(function () {
	    $('a[href*=#]').click(function () {
	        if (location.pathname.replace(/^\//, '') == this.pathname.replace(/^\//, '')
	                && location.hostname == this.hostname) {
	            var $target = $(this.hash);
	            $target = $target.length && $target || $('[name=' + this.hash.slice(1) + ']');

	            if ($('.menu-fixed').css('position') === 'fixed') {
	                if ($target.length) {
	                    var targetOffset = $target.offset().top - 110;
	                    $('html,body').animate({scrollTop: targetOffset}, 1000);
	                    return false;
	                }
	            } else {
	                if ($target.length) {
	                    var targetOffset = $target.offset().top - 215;
	                    $('html,body').animate({scrollTop: targetOffset}, 1000);
	                    return false;
	                }
	            }

	        }
	    });
	});
}

$(document).ready(function () {
    /* MENU MOBILE */
    $('.button-collapse').sideNav({
        menuWidth: 300, // Default is 300
        edge: 'left', // Choose the horizontal origin
        closeOnClick: true, // Closes side-nav on <a> clicks, useful for Angular/Meteor
        draggable: true // Choose whether you can drag to open on touch screens
    }
    );



    $('.carousel.carousel-slider').carousel({
        fullWidth: true,
        indicators: true});


    $('.carousel.carousel-slider').carousel();
    setInterval(function () {
        $('.carousel').carousel('next');
    }, 3000); // every 3 seconds
    // DATEPICKER
    $('.datepicker').pickadate({
        selectMonths: true,
        selectYears: 15,
        format: 'dd/mm/yyyy',
        min: true,
    });

    $("#fecha_mod").change(function () {
        var date = $(this).val();
        var email = $("#f_email").val();
        var nombre = $("#f_nombre").val();
        var celular = $("#f_celular").val();
        //var cantidad = $("#f_cantidad").val();
        var vuelo = $("#f_vuelo").val();
        window.location.href = "disponibilidad.php?fecha=" + date + "&email=" + email + "&nombre=" + nombre + "&celular=" + celular + "&cantidad=&vuelo=" + vuelo;
    });

    $("#fecha_reporte").change(function () {
        var date = $(this).val();
        window.location.href = "reporte.php?fecha=" + date;
    });

    $('.btn-pagar').on('click', function () {
        var id = $(this).attr('data-id');
        var url = 'action.php?action=pagar_reserva&id=' + id;

        $.post(url,
                {},
                function (response) {
                    $('.response').html(response);
                });

    });

    $('.btn-anular').on('click', function () {
        var id = $(this).attr('data-id');
        var url = 'action.php?action=anular_reserva&id=' + id;
        $.post(url,
                {},
                function (response) {
                    $('.response').html(response);
                });
    });

    $('.btn-realizado').on('click', function () {
        var id = $(this).attr('data-id');
        var url = 'action.php?action=realizar_vuelo&id=' + id;
        $.post(url,
                {},
                function (response) {
                    $('.response').html(response);
                });
    });

    $('.modal').modal({
        dismissible: true, // Modal can be dismissed by clicking outside of the modal
        opacity: .5, // Opacity of modal background
        inDuration: 300, // Transition in duration
        outDuration: 200, // Transition out duration
        startingTop: '4%', // Starting top style attribute
        endingTop: '10%'
    }
    );

});


/*NIVO SLIDER*/
$(window).load(function () {
    $('#slider').nivoSlider({
        effect: 'slideInLeft',
        slices: 15,
        boxCols: 8,
        boxRows: 4,
        animSpeed: 500,
        pauseTime: 4000,
        startSlide: 0,
        directionNav: true,
        controlNav: true,
        controlNavThumbs: false,
        pauseOnHover: true,
        manualAdvance: false,
        prevText: 'Prev',
        nextText: 'Next',
        randomStart: false
    });

});

$(window).load(function () {
    $('#slider-aepubli').nivoSlider({
        effect: 'slideInLeft',
        slices: 15,
        boxCols: 8,
        boxRows: 4,
        animSpeed: 500,
        pauseTime: 4000,
        startSlide: 0,
        directionNav: true,
        controlNav: true,
        controlNavThumbs: false,
        pauseOnHover: true,
        manualAdvance: false,
        prevText: 'Prev',
        nextText: 'Next',
        randomStart: false
    });

});

/* --OWL CAROUSEL-- */
$('.galleryf-1').owlCarousel({
    loop:true,
    margin:25,
    nav:false,
    responsive:{
        0:{
            items:1
        },
        400:{
            items:2
        },
        600:{
            items:2
        },
        1000:{
            items:3
        }
    },
    autoplay: true,
    autoplayTimeout: 3000,
    autoplayHoverPause: true
});

$('.vuelo1car1').owlCarousel({
    loop: true,
    margin: 10,
    nav: true,
    responsive: {
        0: {
            items: 1
        },
        600: {
            items: 1
        },
        1000: {
            items: 1
        }
    },
    autoplay: true,
    autoplayTimeout: 2000,
    autoplayHoverPause: true
});

/* --OWL CAROUSEL-- */
$('.vuelo1car2').owlCarousel({
    loop: true,
    margin: 10,
    nav: true,
    responsive: {
        0: {
            items: 1
        },
        600: {
            items: 1
        },
        1000: {
            items: 1
        }
    },
    autoplay: true,
    autoplayTimeout: 3000,
    autoplayHoverPause: true
});
/* --OWL CAROUSEL-- */
$('.vuelo1car3').owlCarousel({
    loop: true,
    margin: 10,
    nav: true,
    responsive: {
        0: {
            items: 1
        },
        600: {
            items: 1
        },
        1000: {
            items: 1
        }
    },
    autoplay: true,
    autoplayTimeout: 2500,
    autoplayHoverPause: true
});
/* --FACEBOOK -- */
(function (d, s, id) {
    var js, fjs = d.getElementsByTagName(s)[0];
    if (d.getElementById(id))
        return;
    js = d.createElement(s);
    js.id = id;
    js.src = "//connect.facebook.net/es_LA/sdk.js#xfbml=1&version=v2.8";
    fjs.parentNode.insertBefore(js, fjs);
}(document, 'script', 'facebook-jssdk'));

//TWITTER

!function (d, s, id) {
    var js, fjs = d.getElementsByTagName(s)[0], p = /^http:/.test(d.location) ? 'http' : 'https';
    if (!d.getElementById(id)) {
        js = d.createElement(s);
        js.id = id;
        js.src = p + '://platform.twitter.com/widgets.js';
        fjs.parentNode.insertBefore(js, fjs);
    }
}(document, 'script', 'twitter-wjs');