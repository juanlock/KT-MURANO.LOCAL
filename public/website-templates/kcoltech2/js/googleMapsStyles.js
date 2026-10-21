/* ------------------------------------------------------------------
 * Name         : RigelHost - HTML Template
 * File         : googleMapsStyles.js
 * Version      : 1.0
 * Author       : ScanThemes
 * Author URI   : scanthemes@gmail.com
 *
 * Copyright 2018 All Rights Reserved.
/* ------------------------------------------------------------------*/
/* ------------------------------------------------------------------*/

  // animation. Clicking on the marker will toggle the animation between a BOUNCE
  // animation and no animation.

  var marker;

  function initMap() {

    var customMapType = new google.maps.StyledMapType(

    // Styles for Google Maps.
    [
      {"featureType": "administrative.province","elementType": "all","stylers": [{"visibility": "off"}]},
      {"featureType": "landscape","elementType": "all","stylers": [{"saturation": -100},{"lightness": 65},{"visibility": "on"}]},
      {"featureType": "poi","elementType": "all","stylers": [{"saturation": -100},{"lightness": 51},{"visibility": "simplified"}]},
      {"featureType": "road.highway","elementType": "all","stylers": [{"saturation": -100},{"visibility": "simplified"}]},
      {"featureType": "road.highway","elementType": "geometry.fill","stylers": [{"color": "#E45243"}]},
      {"featureType": "road.arterial","elementType": "all","stylers": [{"saturation": -100},{"lightness": 30},{"visibility": "on"}]},
      {"featureType": "road.local","elementType": "all","stylers": [{"saturation": -100},{"lightness": 40},{"visibility": "on"}]},
      {"featureType": "transit","elementType": "all","stylers": [{"saturation": -100},{"visibility": "simplified"}]},
      {"featureType": "water","elementType": "geometry","stylers": [{"hue": "#ffff00"},{"lightness": -25},{"saturation": -97}]},
      {"featureType": "water","elementType": "labels","stylers": [{"visibility": "on"},{"lightness": -25},{"saturation": -100}]}
    ]
    );
    var customMapTypeId = 'custom_style';

    var map = new google.maps.Map(document.getElementById('map'), {
      zoom: 12,
      center: {lat: -37.817214, lng: 144.955925},  // Set your location.
      mapTypeControl: false,
      scrollwheel: false
    });

    google.maps.event.addDomListener(window, "resize", function() {
      var center = map.getCenter();
      google.maps.event.trigger(map, "resize");
      map.setCenter(center);
    });

    map.mapTypes.set(customMapTypeId, customMapType);
    map.setMapTypeId(customMapTypeId);

    marker = new google.maps.Marker({
      map: map,
      draggable: true,
      animation: google.maps.Animation.DROP,
      position: {lat: -37.817214, lng: 144.955925}
    });
    marker.addListener('click', toggleBounce);
  }

  function toggleBounce() {
    if (marker.getAnimation() !== null) {
    marker.setAnimation(null);
    } else {
      marker.setAnimation(google.maps.Animation.BOUNCE);
    }
  }
