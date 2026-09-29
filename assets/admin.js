(function ($) {
    // WordPress Media Uploader
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
            $(target).val(attachment.id).trigger('change');
            if (preview) {
                $(preview).html('<img src="' + attachment.url + '" style="max-width:300px; display:block; border: 2px solid #ccc;">');
            }
        });

        frame.open();
    });

    // Preview Generation
    $(document).on('click', '#dm-btn-preview', function () {
        generatePreview();
    });

    function generatePreview() {
        console.log('=== Iniciando Preview ===');
        var $loading = $('.dm-loading-preview').show();
        var $img = $('#dm-preview-img').hide();
        var $hint = $('.preview-hint').hide();
        var formData = $('#dm-boleta-form').serialize();

        console.log('Form data:', formData);
        console.log('Nonce:', $('#_wpnonce').val());

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'dm_boleta_preview',
                form_data: formData,
                _ajax_nonce: $('#_wpnonce').val()
            },
            success: function (resp) {
                console.log('Response:', resp);
                if (resp.success) {
                    var cachedUrl = resp.data.url + (resp.data.url.indexOf('?') > -1 ? '&' : '?') + 't=' + new Date().getTime();
                    console.log('Loading image:', cachedUrl);
                    $img.attr('src', cachedUrl).show();
                    // Scroll to preview
                    $('html, body').animate({
                        scrollTop: $("#dm-ticket-preview-container").offset().top - 50
                    }, 500);
                } else {
                    console.error('Preview Error:', resp.data);
                    alert('Error generando previsualización: ' + (typeof resp.data === 'string' ? resp.data : JSON.stringify(resp.data)));
                    $hint.show();
                }
            },
            error: function (xhr, status, error) {
                console.error('AJAX Error:', { xhr, status, error });
                console.error('Response Text:', xhr.responseText);
                alert('Error de conexión: ' + error);
                $hint.show();
            },
            complete: function () {
                $loading.hide();
            }
        });
    }
    // Select All Numbers
    $(document).on('click', '#dm-rifa-checkall', function () {
        var isChecked = $(this).is(':checked');
        console.log('DM RIFA: Seleccionando todos:', isChecked);
        $('.dm-num-checkbox, input[name="numeros[]"]').prop('checked', isChecked);
    });
})(jQuery);
