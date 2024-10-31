(function ($) {
    var body = $('body');

    $('#password-span').append('<span id="password-interval-span"><label for="post_password_interval">' + __jsVars.l10n.reset_password_label + '</label> <input type="number" style="width: 94%;margin-bottom: 5px;" min="1" name="post_password_interval" id="post_password_interval" value="' + __jsVars.post_password_interval + '" /><br /></span>');
})(jQuery);