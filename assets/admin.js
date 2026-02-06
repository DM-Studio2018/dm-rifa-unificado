(function ($) {
    $(document).on('click', '.dm-upload-btn', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var target = $btn.data('target');
        var preview = $btn.data('preview');

        var frame = wp.media({
            title: 'Elegir Imagen de Fondo',
            button: { text: 'Usar esta imagen' },
            multiple: false
        });

        frame.on('select', function () {
            var attachment = frame.state().get('selection').first().toJSON();
            $(target).val(attachment.id);
            if (preview) {
                $(preview).html('<img src="' + attachment.url + '" style="max-width:200px; height:auto; border:1px solid #ccc;">');
            }
        });

        frame.open();
    });

    $(document).on('change', '#dm-checkall', function () {
        var v = $(this).prop('checked');
        $('input[name="ids[]"]').prop('checked', v);
    });
})(jQuery);
