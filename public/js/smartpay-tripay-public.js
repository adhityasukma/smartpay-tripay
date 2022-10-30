(function( $ ) {
	'use strict';

	/**
	 * All of the code for your public-facing JavaScript source
	 * should reside in this file.
	 *
	 * Note: It has been assumed you will write jQuery code here, so the
	 * $ function reference has been prepared for usage within the scope
	 * of this function.
	 *
	 * This enables you to define handlers, for when the DOM is ready:
	 *
	 * $(function() {
	 *
	 * });
	 *
	 * When the window is loaded:
	 *
	 * $( window ).load(function() {
	 *
	 * });
	 *
	 * ...and/or other possibilities.
	 *
	 * Ideally, it is not considered best practise to attach more than a
	 * single DOM-ready or window-load handler for a particular page.
	 * Although scripts in the WordPress core, Plugins and Themes may be
	 * practising this, we should strive to set a better example in our own work.
	 */
	$(document).ready(function ($) {
		function getUrlParameter(sParam) {
			var sPageURL = window.location.search.substring(1),
				sURLVariables = sPageURL.split('&'),
				sParameterName,
				i;

			for (i = 0; i < sURLVariables.length; i++) {
				sParameterName = sURLVariables[i].split('=');

				if (sParameterName[0] === sParam) {
					return typeof sParameterName[1] === undefined ? true : decodeURIComponent(sParameterName[1]);
				}
			}
			return false;
		}
		var renewal_order = getUrlParameter('renewal');
		function check_field_smartpay(){
			var smartpay_gateway = $('input[name="smartpay_gateway"]:checked').val();
			if(smartpay_gateway=="tripay"){
				$(".smartpay_tripay_field").removeClass("smartpay-tripay-d-none").addClass("d-flex");
			}else{
				$('input[name="smartpay_payment_mobile"]').val("");
				$(".smartpay_tripay_field").removeClass("d-flex").addClass("smartpay-tripay-d-none");
			}
		}
		function smartpay_tripay_set_price(){
			var smartpay_gateway = $('input[name="smartpay_gateway"]:checked').val();
			var smartpay_tripay_set_price = pricing_sale_smartpay;
			if(smartpay_gateway=="tripay"){
				smartpay_tripay_set_price = pricing_sale_smartpay_tripay;
			}
			return smartpay_tripay_set_price;
		}
		function set_badge_renewal(){
			var badge='';
			if(renewal_order){
				badge='<span class="badge badge-primary">Renewal</span>';
			}
			return badge;
		}
		check_field_smartpay();
		smartpay_tripay_set_price();
		$('input[name="smartpay_gateway"]').on('click',function(){
			check_field_smartpay();
			if($(this).val()=="tripay"){
				$('.modal-title .payment-modal--title.amount').html(pricing_sale_smartpay_tripay+ " "+set_badge_renewal());
			}else{
				$('.modal-title .payment-modal--title.amount').html(pricing_sale_smartpay+ " "+set_badge_renewal());
				$('input[name="smartpay_payment_channel"]').prop('checked', false);
			}
		});

		$('.payment-modal').on('shown.bs.modal', function (event) {
			var relatedTarget_payment  = $(event.relatedTarget); // Button that triggered the modal
			var modal       = $(this);
			modal.find('.payment-modal--title.amount').remove();
			modal.find('.modal-title').append('<h2 class="payment-modal--title amount m-0">'+smartpay_tripay_set_price()+ " "+set_badge_renewal()+'</h2>');
		})
	});
})( jQuery );
