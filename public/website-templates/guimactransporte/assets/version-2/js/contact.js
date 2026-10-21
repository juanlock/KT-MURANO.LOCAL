
(function ($) {
    "use strict";
    jQuery(document).ready(function ($) {
        $(document).on('submit', '#get_in_touch', function (e) {
            e.preventDefault();
            var firstName = $('#first_name').val();
            var email = $('#email').val();
            var subject = $('#subject').val();
            var message = $('#message').val();

            // Validación de campos
            var isValid = true;

            if (!firstName) {
                $('#first_name').addClass('error').attr('placeholder', 'First Name is required');
                isValid = false;
            } else {
                $('#first_name').removeClass('error');
            }

            if (!email) {
                $('#email').addClass('error').attr('placeholder', 'Enter Your Email');
                isValid = false;
            } else {
                $('#email').removeClass('error');
            }

            if (!subject) {
                $('#subject').addClass('error').attr('placeholder', 'Subject is required');
                isValid = false;
            } else {
                $('#subject').removeClass('error');
            }

            if (!message) {
                $('#message').addClass('error').attr('placeholder', 'Write Your Message');
                isValid = false;
            } else {
                $('#message').removeClass('error');
            }

            if (isValid) {
                $.ajax({
                    type: "POST",
                    url: 'contact.php',
                    data: {
                        'first_name': firstName,
                        'email': email,
                        'subject': subject,
                        'message': message
                    },
                    success: function (data) {
                        $('#get_in_touch').prepend('<div class="alert alert-success email-success">' + data + '</div>');
                        
                        $('#first_name').val('');
                        $('#email').val('');
                        $('#subject').val('');
                        $('#message').val('');
                        $('.email-success').fadeOut(5000);
                    },
                    error: function (res) {
                        $('#get_in_touch').prepend('<div class="alert alert-danger email-success">Something went wrong. Please try again later.</div>');
                        $('.email-success').fadeOut(5000);
                    }
                });
            } else {
                $('#get_in_touch').prepend('<div class="alert alert-danger email-success">Please fill in all fields correctly.</div>');
                $('.email-success').fadeOut(5000);
            }
        });
    });
})(jQuery);