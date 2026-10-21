/*
	For Edit This File Please Read Documentation
*/

var myPlaylist = [
	
	{
		mp3:'music/1.mp3',
		title:'Pierde el Control',
		artist:'Kynto Elemento',
		rating:5,
		buy:'#',
		price:'17',
		duration:'0:38',
		cover:'music/1.jpg'	
	},
	{
		mp3:'music/2.mp3',
		title:'Palante',
		artist:'Kynto Elemento',
		rating:4,
		buy:'#',
		price:'17',
		duration:'2:51',
		cover:'music/2.jpg'	
	},
	{
		mp3:'music/3.mp3',
		title:'The Party Dont Stop',
		artist:'Kynto Elemento',
		rating:5,
		buy:'#',
		price:'17',
		duration:'0:38',
		cover:'music/3.jpg'	
	},
	{
		mp3:'music/2.mp3',
		title:'Track 4',
		artist:'BlueFoxMusic',
		rating:4,
		buy:'#',
		price:'17',
		duration:'2:51',
		cover:'music/2.jpg'	
	},
	{
		mp3:'music/1.mp3',
		title:'Track 5',
		artist:'Alexandra',
		rating:5,
		buy:'#',
		price:'17',
		duration:'0:38',
		cover:'music/1.jpg'	
	},
	{
		mp3:'music/2.mp3',
		title:'Track 6',
		artist:'BlueFoxMusic',
		rating:4,
		buy:'#',
		price:'17',
		duration:'2:51',
		cover:'music/2.jpg'	
	},
	{
		mp3:'music/1.mp3',
		title:'Track 7',
		artist:'Alexandra',
		rating:5,
		buy:'#',
		price:'17',
		duration:'0:38',
		cover:'music/1.jpg'	
	},
	{
		mp3:'music/2.mp3',
		title:'Track 8',
		artist:'BlueFoxMusic',
		rating:4,
		buy:'#',
		price:'17',
		duration:'2:51',
		cover:'music/2.jpg'	
	},
];
jQuery(document).ready(function ($) {
	$('.music-player-list').ttwMusicPlayer(myPlaylist, {
		currencySymbol:'$',
		buyText:'BUY',
		tracksToShow:3,
		autoplay:true,
		ratingCallback:function(index, playlistItem, rating){
			//some logic to process the rating, perhaps through an ajax call
		},
		jPlayer:{
			swfPath: "http://www.jplayer.org/2.7.0/js/",
			supplied: "mp3",
			volume:  0.8,
			wmode:"window",
			solution: "html,flash",
			errorAlerts: true,
			warningAlerts: true
		}
	});
});