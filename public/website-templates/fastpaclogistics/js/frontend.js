var cargohubShortCode = cargohubShortCode || {},
	cargohub = cargohub || {};

( function ( $ ) {
	'use strict';

	$( function() {

		// Counter
		function count($this) {
			var current = parseInt($this.html(), 10);
			current = current + 10;
			$this.html(++current);
			if (current > $this.data('count')) {
				$this.html($this.data('count'));
			}
			else {
				setTimeout(function () {
					count($this);
				}, 5);
			}
		}

		//Section Parallax
		var $parallaxsRow = $('.vc_row.parallax');
		for (var i = 0; i < $parallaxsRow.length; i++) {
			$($parallaxsRow[i]).parallax('50%', 0.6);
		}

		/**
		 * Partner
		 */
		$('.fh-partner').each(function(){
			var columns = parseInt( $( this ).data( 'columns' ), 10 );

			$( this ).find('.list-item').owlCarousel({
				items: columns,
				margin: 30,
				loop: true,
				nav: false,
				autoplay: false,
				dots: false,
				responsive     : {
					0: {
						items: 1
					},
					768: {
						items: 3
					},
					1024 : {
						items: columns
					}
				}
			});
		});

		$('.counter .value').each(function () {
			$(this).data('count', parseInt($(this).html(), 10));
			$(this).html('0');
			count($(this));
		});

		testimonialCarousel();
		postCarousel();
		teamCarousel();

        /**
         * Init testimonials carousel
         */
        function testimonialCarousel() {
            if (cargohubShortCode.length === 0 || typeof cargohubShortCode.testimonial === 'undefined') {
                return;
            }
            $.each(cargohubShortCode.testimonial, function (id, testimonialData) {
	            $(document.getElementById(id)).find( '.testi-list' ).owlCarousel({
		            items          : testimonialData.columns,
		            loop           : true,
		            navText        : ['<i class="fa fa-angle-left" aria-hidden="true"></i>', '<i class="fa fa-angle-right" aria-hidden="true"></i>'],
		            autoplay       : testimonialData.autoplay,
		            autoplayTimeout: testimonialData.autoplay_timeout,
		            autoplaySpeed  : testimonialData.autoplay_speed,
		            nav            : testimonialData.nav,
		            dots           : testimonialData.dot,
		            navSpeed       : 800,
		            dotsSpeed      : 1000,
		            responsive     : {
			            0: {
				            items: 1,
				            dots : true
			            },
			            992 : {
				            items: testimonialData.columns,
				            dots : testimonialData.dot
			            }
		            }
	            });
            });
        }

		/**
		 * Init post carousel
		 */
		function postCarousel() {
			if (cargohubShortCode.length === 0 || typeof cargohubShortCode.post === 'undefined') {
				return;
			}
			$.each(cargohubShortCode.post, function (id, postData) {
				if (postData.is_carousel == 1) {
					$(document.getElementById(id)).owlCarousel({
						items          : postData.columns,
						loop           : true,
						nav            : postData.nav,
						navText        : ['<i class="fa fa-long-arrow-left" aria-hidden="true"></i>', '<i class="fa fa-long-arrow-right" aria-hidden="true"></i>'],
						autoplay       : postData.autoplay,
						autoplayTimeout: postData.autoplay_timeout,
						autoplaySpeed  : postData.autoplay_speed,
						dots           : postData.dot,
						navSpeed       : 800,
						dotsSpeed      : 1000,
						responsive     : {
							0 : {
								items: 1,
								dots : true,
								nav  : false
							},
							600 : {
								items: 2
							},
							991 : {
								dots : postData.dot
							}
						}
					});

				}
			});
		}

		/**
		 * Init post carousel
		 */
		function teamCarousel() {
			if (cargohubShortCode.length === 0 || typeof cargohubShortCode.team === 'undefined') {
				return;
			}
			$.each(cargohubShortCode.team, function (id, teamData) {
				$(document.getElementById(id) ).addClass( 'owl-carousel' ).owlCarousel({
					items          : teamData.columns,
					loop           : true,
					nav            : teamData.nav,
					navText        : ['<i class="fa fa-long-arrow-left" aria-hidden="true"></i>', '<i class="fa fa-long-arrow-right" aria-hidden="true"></i>'],
					autoplay       : teamData.autoplay,
					autoplayTimeout: teamData.autoplay_timeout,
					autoplaySpeed  : teamData.autoplay_speed,
					dots           : teamData.dot,
					navSpeed       : 800,
					dotsSpeed      : 1000,
					responsive     : {
						0 : {
							items: 1,
							dots : true,
							nav  : false
						},
						600 : {
							items: 2
						},
						991 : {
							dots : teamData.dot
						}
					}
				});
			});
		}

		cargohubMaps();

		/**
         * Init Google maps
         */
        function cargohubMaps() {
            if (cargohubShortCode.length === 0 || typeof cargohubShortCode.map === 'undefined') {
                return;
            }

			var mapOptions = {
                scrollwheel: false,
                draggable: true,
                zoom: 10,
                mapTypeId: google.maps.MapTypeId.ROADMAP,
                panControl: false,
                zoomControl: true,
                zoomControlOptions: {
                    style: google.maps.ZoomControlStyle.SMALL
                },
                scaleControl: false,
                streetViewControl: false

            },
            customMap;

            var bounds = new google.maps.LatLngBounds();
            var infoWindow = new google.maps.InfoWindow();


            $.each(cargohubShortCode.map, function (id, mapData) {
	            var color = mapData.color;
	            var style1 =
		            [
			            {
				            'featureType': 'administrative',
				            'elementType': 'labels.text.fill',
				            'stylers': [{'visibility': 'off'}, {'color': '#444444'}]
			            },
			            {
				            'featureType': 'landscape',
				            'elementType': 'all',
				            'stylers': [{'color': '#f2f2f2'}]
			            },
			            {
				            'featureType': 'poi',
				            'elementType': 'all',
				            'stylers': [{'visibility': 'off'}]
			            },
			            {
				            'featureType': 'road',
				            'elementType': 'all',
				            'stylers': [{'saturation': -100}, {'lightness': 45}]
			            },
			            {
				            'featureType': 'road.highway',
				            'elementType': 'all',
				            'stylers': [{'visibility': 'simplified'}]
			            },
			            {
				            'featureType': 'road.highway',
				            'elementType': 'geometry.stroke',
				            'stylers': [{'color': '#cecece'}]
			            },
			            {
				            'featureType': 'road.arterial',
				            'elementType': 'labels.icon',
				            'stylers': [{'visibility': 'off'}]
			            },
			            {
				            'featureType': 'transit',
				            'elementType': 'all',
				            'stylers': [{'visibility': 'off'}]
			            },
			            {
				            'featureType': 'water',
				            'elementType': 'all',
				            'stylers': [{'color': color},{'visibility': 'on'}]
			            }
		            ];
	            var style2 = [];
	            var styles;

	            if (mapData.style == '1') {
		            styles = style1;
	            } else {
		            styles = style2;
	            }

            	customMap = new google.maps.StyledMapType(styles,
                    {name: 'Styled Map'});

                if ( mapData.number > 1 ) {
					mutiMaps(infoWindow, bounds, mapOptions, mapData, id, styles, customMap);
				} else {
					singleMap(mapOptions, mapData, id, styles, customMap);
				}

            });
        }

        function singleMap(mapOptions, mapData, id, styles, customMap) {
			var map,
				marker,
				location = new google.maps.LatLng(mapData.lat, mapData.lng);

			// Update map options
			mapOptions.zoom = parseInt(mapData.zoom, 10);
			mapOptions.center = location;
			mapOptions.mapTypeControlOptions = {
				mapTypeIds: [google.maps.MapTypeId.ROADMAP]
			};

			// Init map
			map = new google.maps.Map(document.getElementById(id), mapOptions);

			// Create marker options
			var markerOptions = {
				map     : map,
				position: location
			};
			if (mapData.marker) {
				markerOptions.icon = {
					url: mapData.marker
				};
			}

			map.mapTypes.set('map_style', customMap);
			map.setMapTypeId('map_style');

			// Init marker
			marker = new google.maps.Marker(markerOptions);

			if (mapData.info) {
				var infoWindow = new google.maps.InfoWindow({
					content : '<div class="info-box fb-map">' + mapData.info + '</div>',
					maxWidth: 600
				});

				google.maps.event.addListener(marker, 'click', function () {
					infoWindow.open(map, marker);
				});
			}
		}

		function mutiMaps(infoWindow, bounds, mapOptions, mapData, id, styles, customMap) {

			// Display a map on the page
			mapOptions.zoom = parseInt(mapData.zoom, 10);
			mapOptions.mapTypeControlOptions = {
				mapTypeIds: [google.maps.MapTypeId.ROADMAP]
			};

			var map = new google.maps.Map(document.getElementById(id), mapOptions);
			map.mapTypes.set('map_style', customMap);
			map.setMapTypeId('map_style');
			for (var i = 0; i < mapData.number; i++) {
				var lats = mapData.lat,
					lng = mapData.lng,
					info = mapData.info;

				var position = new google.maps.LatLng(lats[i], lng[i]);
				bounds.extend(position);

				// Create marker options
				var markerOptions = {
					map     : map,
					position: position
				};
				if (mapData.marker) {
					markerOptions.icon = {
						url: mapData.marker
					};
				}

				// Init marker
				var marker = new google.maps.Marker(markerOptions);

				// Allow each marker to have an info window
				googleMaps(infoWindow, map, marker, info[i]);

				// Automatically center the map fitting all markers on the screen
				map.fitBounds(bounds);
			}
		}

        function googleMaps(infoWindow, map, marker, info) {
            google.maps.event.addListener(marker, 'click', function () {
                infoWindow.setContent(info);
                infoWindow.open(map, marker);
            });
        }

	} );
} )( jQuery );