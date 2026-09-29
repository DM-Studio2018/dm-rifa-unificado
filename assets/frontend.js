(function ($) {
  function money(n) { n = parseInt(n || 0, 10); return n.toLocaleString('es-CO'); }

  function normalizePad3(v) {
    v = (v || '').toString().trim();
    v = v.replace(/\D/g, ''); // solo dígitos
    if (v === '') return '';
    return v.padStart(3, '0');
  }

  function buildGrid($wrap, map) {
    var $grid = $wrap.find('.dm-grid');
    $grid.empty();
    var keys = Object.keys(map).sort();
    keys.forEach(function (k) {
      var st = (map[k] || '').toLowerCase().trim();
      var $b = $('<button type="button" class="dm-cell" aria-pressed="false"></button>');
      $b.text(k).attr('data-num', k).attr('data-estado', st);

      if (st === 'reservado') $b.addClass('is-reservado').attr('aria-disabled', 'true');
      if (st === 'pagado') $b.addClass('is-pagado').attr('aria-disabled', 'true');
      // Cualquier otro estado no disponible se muestra bloqueado
      if (st && st !== 'disponible' && st !== 'reservado' && st !== 'pagado') $b.addClass('is-pagado').attr('aria-disabled', 'true');

      $grid.append($b);
    });
  }

  function refreshTotals($wrap) {
    var precio = parseInt($wrap.data('precio'), 10) || 0;
    var sel = $wrap.data('sel') || [];
    var total = precio * sel.length;
    $wrap.find('.dm-contador').html('<strong>Seleccionaste ' + sel.length + '</strong> – Total: $' + money(total));
  }

  // FIX: restablecer visibilidad SIEMPRE antes de aplicar nuevos filtros
  function filterGrid($wrap) {
    var qRaw = $wrap.find('.dm-buscar').val();
    var q = normalizePad3(qRaw);
    var f = $wrap.find('.dm-filtro-estado').val();

    var $cells = $wrap.find('.dm-cell');
    $cells.stop(true, true).show(); // restablece: muestra todo
    if (!q && !f) { return; }         // sin filtros activos -> todo visible

    $cells.each(function () {
      var $c = $(this);
      var n = $c.attr('data-num');
      var st = $c.attr('data-estado');
      var ok = true;
      if (q && n !== q) ok = false;
      if (f && st !== f) ok = false;
      $c.toggle(ok);
    });
  }

  // ---------- Buscador de vendedor (nombre o apellido, sin importar tildes) ----------
  function normTxt(s) {
    return (s || '').toString().normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
  }

  function initVendPicker($wrap) {
    var $p = $wrap.find('.dm-vend-picker');
    if (!$p.length) { return; }

    var lista = $p.data('vendedores') || [];
    if (typeof lista === 'string') { try { lista = JSON.parse(lista); } catch (e) { lista = []; } }
    lista.forEach(function (v) { v.k = normTxt(v.n); v.w = v.k.split(/\s+/); });

    var $q = $p.find('.dm-vend-q');
    var $ul = $p.find('.dm-vend-lista');
    var $hid = $p.find('.dm-vendedor');
    var $ninguno = $p.find('.dm-vend-ninguno');
    var $elegido = $p.find('.dm-vend-elegido');
    var $buscar = $p.find('.dm-vend-buscar');
    var activo = -1;
    var resultados = [];

    function cerrar() {
      $ul.attr('hidden', true).empty();
      $q.attr('aria-expanded', 'false').removeAttr('aria-activedescendant');
      activo = -1;
    }

    function buscar(texto) {
      var t = normTxt(texto);
      if (!t) { return []; }
      var partes = t.split(/\s+/);
      var out = [];
      lista.forEach(function (v) {
        // Cada palabra escrita debe aparecer en el nombre; puntúa más si empieza una palabra
        var puntos = 0;
        for (var i = 0; i < partes.length; i++) {
          var p = partes[i];
          if (v.k.indexOf(p) === -1) { return; }
          var inicio = v.w.some(function (w) { return w.indexOf(p) === 0; });
          puntos += inicio ? 2 : 1;
        }
        if (v.k.indexOf(t) === 0) { puntos += 3; }
        out.push({ v: v, puntos: puntos });
      });
      out.sort(function (a, b) { return b.puntos - a.puntos || a.v.k.localeCompare(b.v.k); });
      return out.slice(0, 8).map(function (x) { return x.v; });
    }

    function pintar() {
      $ul.empty();
      var base = $ul.attr('id');
      if (!resultados.length) {
        $ul.append($('<li class="dm-vend-vacio" role="option" aria-disabled="true"></li>').text('No encontramos ese vendedor'));
      }
      resultados.forEach(function (v, i) {
        $('<li role="option" class="dm-vend-op"></li>')
          .attr('id', base + '-op' + i)
          .attr('data-i', i)
          .attr('aria-selected', i === activo ? 'true' : 'false')
          .toggleClass('is-activo', i === activo)
          .text(v.n)
          .appendTo($ul);
      });
      $ul.removeAttr('hidden');
      $q.attr('aria-expanded', 'true');
      if (activo >= 0) { $q.attr('aria-activedescendant', base + '-op' + activo); }
    }

    function elegir(v) {
      $hid.val(v.id);
      $ninguno.prop('checked', false);
      $elegido.find('.dm-vend-nombre').text(v.n);
      $elegido.removeAttr('hidden');
      $buscar.attr('hidden', true);
      $q.val('');
      cerrar();
      $wrap.find('.dm-msg').text('');
    }

    $q.on('input', function () {
      $hid.val('');
      if ($q.val().trim()) { $ninguno.prop('checked', false); }
      resultados = buscar($q.val());
      activo = resultados.length ? 0 : -1;
      if (!$q.val().trim()) { cerrar(); return; }
      pintar();
    });

    $q.on('keydown', function (e) {
      if ($ul.is('[hidden]')) { return; }
      if (e.key === 'ArrowDown') { e.preventDefault(); activo = Math.min(activo + 1, resultados.length - 1); pintar(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); activo = Math.max(activo - 1, 0); pintar(); }
      else if (e.key === 'Enter') { e.preventDefault(); if (resultados[activo]) { elegir(resultados[activo]); } }
      else if (e.key === 'Escape') { cerrar(); }
    });

    // mousedown para elegir antes de que el blur cierre la lista
    $ul.on('mousedown', '.dm-vend-op', function (e) {
      e.preventDefault();
      var v = resultados[parseInt($(this).attr('data-i'), 10)];
      if (v) { elegir(v); }
    });

    $q.on('blur', function () { setTimeout(cerrar, 150); });

    $ninguno.on('change', function () {
      if (this.checked) { $hid.val(''); $q.val(''); cerrar(); $wrap.find('.dm-msg').text(''); }
    });

    $p.find('.dm-vend-cambiar').on('click', function () {
      $hid.val('');
      $elegido.attr('hidden', true);
      $buscar.removeAttr('hidden');
      $q.trigger('focus');
    });
  }

  // Devuelve '' si todo bien, o el mensaje de error
  function validarVendedor($wrap) {
    var $p = $wrap.find('.dm-vend-picker');
    if (!$p.length) { return ''; }
    if ($p.find('.dm-vendedor').val()) { return ''; }
    if ($p.find('.dm-vend-ninguno').is(':checked')) { return ''; }
    return $p.find('.dm-vend-q').val().trim()
      ? 'Elige al vendedor de la lista de sugerencias, o marca "Compro sin vendedor".'
      : 'Escribe el nombre de tu vendedor, o marca "Compro sin vendedor".';
  }

  $(function () {
    $('.dm-rifa-wrap').each(function () {
      var $wrap = $(this);
      var rifa = parseInt($wrap.data('rifa'), 10);
      $wrap.data('sel', []);
      initVendPicker($wrap);

      // Función para renderizar con datos frescos
      var render = function (map) {
        buildGrid($wrap, map);
        refreshTotals($wrap);
        filterGrid($wrap); // Re-aplicar filtros si los había
      };

      // Intentar usar estado inicial (inyectado por PHP)
      var initialMap = (window.DM_RIFA_STATE && window.DM_RIFA_STATE[rifa]) ? window.DM_RIFA_STATE[rifa] : null;
      if (initialMap) {
        render(initialMap);
      }

      // SIEMPRE solicitar estados frescos vía AJAX para evitar CACHÉ de página
      $.ajax({
        url: DMRIFA.ajax,
        type: 'GET',
        cache: false,
        data: {
          action: 'dm_rifa_get_states',
          rifa_id: rifa
        },
        success: function (resp) {
          if (resp && resp.success && resp.data) {
            render(resp.data);
          }
        }
      });

      // Selección de números disponibles
      $wrap.on('click', '.dm-cell', function () {
        var $c = $(this);
        var st = $c.attr('data-estado');
        if (st !== 'disponible') { return; } // no permitir reservado/pagado
        var num = $c.data('num');
        var sel = $wrap.data('sel') || [];
        if ($c.hasClass('is-sel')) {
          $c.removeClass('is-sel').attr('aria-pressed', 'false');
          sel = sel.filter(function (x) { return x !== num; });
        } else {
          sel.push(num);
          $c.addClass('is-sel').attr('aria-pressed', 'true');
        }
        $wrap.data('sel', sel);
        refreshTotals($wrap);
      });

      // Filtros: input + change
      $wrap.find('.dm-buscar').on('input', function () {
        // cuando limpian el campo en Chrome, el input a '' ahora sí muestra todo
        filterGrid($wrap);
      });
      $wrap.find('.dm-filtro-estado').on('change', function () {
        filterGrid($wrap);
      });

      // UX: tecla ESC limpia el buscador y re-aplica filtros
      $wrap.find('.dm-buscar').on('keydown', function (e) {
        if (e.key === 'Escape') { $(this).val(''); filterGrid($wrap); }
      });

      // Continuar -> reserva vía AJAX
      $wrap.find('.dm-continuar').on('click', function () {
        var sel = $wrap.data('sel') || [];
        var nombre = $wrap.find('.dm-nombre').val().trim();
        var email = $wrap.find('.dm-email').val().trim();
        var tel = $wrap.find('.dm-telefono').val().trim();
        var venId = $wrap.find('.dm-vendedor').val() || 0;
        var formaPago = $wrap.find('input[name="dm-forma-pago"]:checked').val() || 'transferencia';

        if (sel.length === 0) { $wrap.find('.dm-msg').text('Selecciona al menos un numero.').css('color', 'red'); return; }
        if (!nombre) { $wrap.find('.dm-msg').text('Ingresa tu nombre.').css('color', 'red'); return; }
        if (!tel) { $wrap.find('.dm-msg').text('Ingresa tu telefono.').css('color', 'red'); return; }
        var errVend = validarVendedor($wrap);
        if (errVend) {
          $wrap.find('.dm-msg').text(errVend).css('color', 'red');
          var $vq = $wrap.find('.dm-vend-q:visible');
          if ($vq.length) { $vq.trigger('focus'); }
          return;
        }

        $wrap.find('.dm-msg').text('Procesando reserva...').css('color', '#666').show();
        $wrap.find('.dm-continuar').prop('disabled', true).css('opacity', '0.5');

        $.ajax({
          url: DMRIFA.ajax,
          type: 'POST',
          dataType: 'json',
          cache: false,
          data: {
            action: 'dm_rifa_reservar',
            nonce: DMRIFA.nonce,
            rifa_id: rifa,
            numeros: sel,
            nombre: nombre,
            email: email,
            telefono: tel,
            vendedor_id: venId,
            forma_pago: formaPago
          },
          success: function (resp) {
            if (resp && resp.success) {
              var url = resp.data.confirm_url;
              if (url) {
                window.location.replace(url);
              } else {
                $wrap.find('.dm-msg').text('Reserva creada.').css('color', 'green');
              }
            } else {
              var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Error al reservar';
              $wrap.find('.dm-msg').text('No se pudo reservar: ' + msg).css('color', 'red');
              $wrap.find('.dm-continuar').prop('disabled', false).css('opacity', '1');
            }
          },
          error: function (xhr) {
            console.error("DM RIFA AJAX FAIL:", xhr);
            var msg = 'Error de conexion';
            try {
              var j = JSON.parse(xhr.responseText);
              msg = j.data && j.data.message ? j.data.message : xhr.statusText;
            } catch (e) {
              if (xhr.status === 403) msg = 'Sesion expirada, por favor recarga la pagina.';
            }
            $wrap.find('.dm-msg').text('No se pudo reservar: ' + msg).css('color', 'red');
            $wrap.find('.dm-continuar').prop('disabled', false).css('opacity', '1');
          }
        });
      });
    });
  });
})(jQuery);