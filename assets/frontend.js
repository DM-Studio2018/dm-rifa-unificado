(function($){
  function money(n){ n = parseInt(n||0,10); return n.toLocaleString('es-CO'); }

  function normalizePad3(v){
    v = (v||'').toString().trim();
    v = v.replace(/\D/g,''); // solo dígitos
    if (v === '') return '';
    return v.padStart(3,'0');
  }

  function buildGrid($wrap, map){
    var $grid = $wrap.find('.dm-grid');
    $grid.empty();
    var keys = Object.keys(map).sort();
    keys.forEach(function(k){
      var st = map[k];
      var $b = $('<button type="button" class="dm-cell" aria-pressed="false"></button>');
      $b.text(k).attr('data-num', k).attr('data-estado', st);
      if(st==='reservado') $b.addClass('is-reservado').attr('aria-disabled','true');
      if(st==='pagado')    $b.addClass('is-pagado').attr('aria-disabled','true');
      $grid.append($b);
    });
  }

  function refreshTotals($wrap){
    var precio = parseInt($wrap.data('precio'),10)||0;
    var sel = $wrap.data('sel')||[];
    var total = precio * sel.length;
    $wrap.find('.dm-contador').html('<strong>Seleccionaste '+sel.length+'</strong> – Total: $'+money(total));
  }

  // FIX: restablecer visibilidad SIEMPRE antes de aplicar nuevos filtros
  function filterGrid($wrap){
    var qRaw = $wrap.find('.dm-buscar').val();
    var q = normalizePad3(qRaw);
    var f = $wrap.find('.dm-filtro-estado').val();

    var $cells = $wrap.find('.dm-cell');
    $cells.stop(true, true).show(); // restablece: muestra todo
    if(!q && !f){ return; }         // sin filtros activos -> todo visible

    $cells.each(function(){
      var $c = $(this);
      var n  = $c.attr('data-num');
      var st = $c.attr('data-estado');
      var ok = true;
      if(q && n!==q) ok = false;
      if(f && st!==f) ok = false;
      $c.toggle(ok);
    });
  }

  $(document).on('ready', function(){
    $('.dm-rifa-wrap').each(function(){
      var $wrap = $(this);
      var rifa  = parseInt($wrap.data('rifa'),10);
      var map   = (window.DM_RIFA_STATE && window.DM_RIFA_STATE[rifa]) ? window.DM_RIFA_STATE[rifa] : {};
      $wrap.data('sel', []);
      buildGrid($wrap, map);
      refreshTotals($wrap);

      // Selección de números disponibles
      $wrap.on('click', '.dm-cell', function(){
        var $c = $(this);
        var st = $c.attr('data-estado');
        if(st!=='disponible'){ return; } // no permitir reservado/pagado
        var num = $c.data('num');
        var sel = $wrap.data('sel')||[];
        if($c.hasClass('is-sel')){
          $c.removeClass('is-sel').attr('aria-pressed','false');
          sel = sel.filter(function(x){ return x!==num; });
        }else{
          sel.push(num);
          $c.addClass('is-sel').attr('aria-pressed','true');
        }
        $wrap.data('sel', sel);
        refreshTotals($wrap);
      });

      // Filtros: input + change
      $wrap.find('.dm-buscar').on('input', function(){
        // cuando limpian el campo en Chrome, el input a '' ahora sí muestra todo
        filterGrid($wrap);
      });
      $wrap.find('.dm-filtro-estado').on('change', function(){
        filterGrid($wrap);
      });

      // UX: tecla ESC limpia el buscador y re-aplica filtros
      $wrap.find('.dm-buscar').on('keydown', function(e){
        if(e.key === 'Escape'){ $(this).val(''); filterGrid($wrap); }
      });

      // Continuar -> reserva vía AJAX
      $wrap.find('.dm-continuar').on('click', function(){
        var sel = $wrap.data('sel')||[];
        var nombre = $wrap.find('.dm-nombre').val().trim();
        var email  = $wrap.find('.dm-email').val().trim();
        var tel    = $wrap.find('.dm-telefono').val().trim();
        if(sel.length===0){ $wrap.find('.dm-msg').text('Selecciona al menos un número.'); return; }
        if(!nombre){ $wrap.find('.dm-msg').text('Ingresa tu nombre.'); return; }
        if(!tel){ $wrap.find('.dm-msg').text('Ingresa tu teléfono.'); return; }

        $.post(DMRIFA.ajax, {
          action: 'dm_rifa_reservar',
          nonce: DMRIFA.nonce,
          rifa_id: rifa,
          numeros: sel,
          nombre: nombre,
          email: email,
          telefono: tel
        }).done(function(resp){
          if(resp && resp.success){
            var url = resp.data.confirm_url;
            if(url){ window.location.href = url; }
            else { $wrap.find('.dm-msg').text('Reserva creada. (No hay página de gracias configurada)'); }
          }else{
            var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Error al reservar';
            $wrap.find('.dm-msg').text('No se pudo reservar: '+msg);
          }
        }).fail(function(xhr){
          var msg = 'Error';
          try{ var j = JSON.parse(xhr.responseText); msg = j.data && j.data.message ? j.data.message : xhr.statusText; }catch(e){}
          $wrap.find('.dm-msg').text('No se pudo reservar: '+msg);
        });
      });
    });
  });
})(jQuery);