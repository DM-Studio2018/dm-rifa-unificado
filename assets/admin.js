(function($){
    $(document).on('change', '#dm-checkall', function(){
        var v = $(this).prop('checked');
        $('input[name="ids[]"]').prop('checked', v);
    });
})(jQuery);
