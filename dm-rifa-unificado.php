<?php
/**
 * Plugin Name: DM Rifa Unificado
 * Description: Selector de números, reservas y página de confirmación con WhatsApp + panel de gestión en el admin (todo en un solo plugin).
 * Version: 1.1.0
 * Author: DM Studio SAS
 * License: GPL2
 */

if ( ! defined('ABSPATH') ) { exit; }

class DM_Rifa_Unificado {
    private static $instance = null;
    private $version = '1.1.0';
    private $tbl_rifas;
    private $tbl_numeros;
    private $tbl_reservas;

    public static function instance() {
        if ( self::$instance === null ) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->tbl_rifas    = $wpdb->prefix . 'dm_rifas';
        $this->tbl_numeros  = $wpdb->prefix . 'dm_rifa_numeros';
        $this->tbl_reservas = $wpdb->prefix . 'dm_rifa_reservas';

        register_activation_hook(__FILE__, array($this, 'on_activate'));
        add_action('admin_menu', array($this, 'admin_menu'));
        add_shortcode('rifa_selector', array($this, 'sc_rifa_selector'));
        add_shortcode('rifa_confirm', array($this, 'sc_rifa_confirm'));

        add_action('wp_enqueue_scripts', array($this, 'enqueue_front'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin'));

        add_action('wp_ajax_dm_rifa_reservar', array($this, 'ajax_reservar'));
        add_action('wp_ajax_nopriv_dm_rifa_reservar', array($this, 'ajax_reservar'));

        add_action('admin_post_dm_rifa_export_csv', array($this, 'admin_post_export_csv'));
        add_action('admin_post_dm_rifa_update_reserva', array($this, 'admin_post_update_reserva'));
    }

    public function on_activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        $sql_rifas = "CREATE TABLE {$this->tbl_rifas} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            nombre VARCHAR(255) NOT NULL,
            descripcion LONGTEXT NULL,
            fecha DATETIME NULL,
            loteria VARCHAR(120) NULL,
            total_numeros INT NOT NULL DEFAULT 0,
            precio INT NOT NULL DEFAULT 0,
            wa_e164 VARCHAR(20) NULL,
            nequi_qr_url TEXT NULL,
            css_activo TINYINT(1) NOT NULL DEFAULT 0,
            gracias_page_id BIGINT UNSIGNED NULL,
            PRIMARY KEY (id)
        ) $charset;";

        $sql_numeros = "CREATE TABLE {$this->tbl_numeros} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            rifa_id BIGINT UNSIGNED NOT NULL,
            numero VARCHAR(4) NOT NULL,
            estado VARCHAR(12) NOT NULL DEFAULT 'disponible',
            reserva_id BIGINT UNSIGNED NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY rifa_num (rifa_id, numero),
            KEY idx_rifa (rifa_id),
            PRIMARY KEY (id)
        ) $charset;";

        $sql_reservas = "CREATE TABLE {$this->tbl_reservas} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            rifa_id BIGINT UNSIGNED NOT NULL,
            nombre VARCHAR(120) NOT NULL,
            email VARCHAR(120) NULL,
            telefono VARCHAR(30) NULL,
            numeros_csv LONGTEXT NOT NULL,
            precio_unit INT NOT NULL DEFAULT 0,
            total INT NOT NULL DEFAULT 0,
            status VARCHAR(12) NOT NULL DEFAULT 'reservado',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NULL,
            token VARCHAR(64) NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            KEY idx_rifa (rifa_id),
            KEY idx_token (token)
        ) $charset;";

        dbDelta($sql_rifas);
        dbDelta($sql_numeros);
        dbDelta($sql_reservas);
    }

    /* ---------------------- Assets ---------------------- */
    public function enqueue_front() {
        if ( is_singular() ) {
            global $post;
            if ( $post && ( has_shortcode($post->post_content, 'rifa_selector') || has_shortcode($post->post_content, 'rifa_confirm') ) ) {
                wp_enqueue_script('dm-rifa-front', plugins_url('assets/frontend.js', __FILE__), array('jquery'), $this->version, true);
                wp_localize_script('dm-rifa-front', 'DMRIFA', array(
                    'ajax'  => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('dm_rifa_nonce')
                ));
                wp_enqueue_style('dm-rifa-front', plugins_url('assets/style.css', __FILE__), array(), $this->version);
            }
        }
    }

    public function enqueue_admin($hook) {
        if ( strpos($hook, 'dm-rifa') !== false ) {
            wp_enqueue_style('dm-rifa-front', plugins_url('assets/style.css', __FILE__), array(), $this->version);
            wp_enqueue_script('dm-rifa-admin', plugins_url('assets/admin.js', __FILE__), array('jquery'), $this->version, true);
            wp_localize_script('dm-rifa-admin', 'DMRIFA', array(
                'ajax'  => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('dm_rifa_nonce')
            ));
        }
    }

    /* ---------------------- Admin Menu ---------------------- */
    public function admin_menu() {
        add_menu_page(
            'DM Rifas', 'DM Rifas', 'manage_options', 'dm-rifa',
            array($this, 'page_rifas'), 'dashicons-tickets', 25
        );
        add_submenu_page('dm-rifa', 'Compradores', 'Compradores', 'manage_options', 'dm-rifa-compradores', array($this, 'page_compradores'));
    }

    /* ---------------------- Helpers ---------------------- */
    private function pad3($n) {
        $n = intval($n);
        if ($n < 0) $n = 0;
        if ($n > 9999) $n = 9999;
        return str_pad((string)$n, 3, '0', STR_PAD_LEFT);
    }

    private function fetch_rifa($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tbl_rifas} WHERE id = %d", $id));
    }

    private function fetch_reserva($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tbl_reservas} WHERE id = %d", $id));
    }

    private function fetch_reserva_by_token($token) {
        global $wpdb;
        $token = sanitize_text_field($token);
        if ($token === '') return null;
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->tbl_reservas} WHERE token = %s LIMIT 1", $token)
        );
    }

    // Calcular el estado real de una reserva basado en el estado de sus números
    private function calcular_estado_reserva($reserva, $rifa_id) {
        global $wpdb;
        
        $numeros_arr = array_map('trim', explode(',', $reserva->numeros_csv));
        if (empty($numeros_arr)) return 'reservado';
        
        $place = implode(',', array_fill(0, count($numeros_arr), '%s'));
        $estados = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT estado FROM {$this->tbl_numeros} WHERE rifa_id = %d AND numero IN ($place)",
            array_merge(array($rifa_id), $numeros_arr)
        ));
        
        // Si todos están pagados
        if (count($estados) === 1 && $estados[0] === 'pagado') {
            return 'pagado';
        }
        
        // Si hay al menos uno pagado y otros reservados (pago parcial)
        if (in_array('pagado', $estados) && in_array('reservado', $estados)) {
            return 'pago parcial';
        }
        
        // Si hay alguno disponible (fue liberado)
        if (in_array('disponible', $estados)) {
            return 'parcialmente liberado';
        }
        
        // Por defecto, reservado
        return 'reservado';
    }

    /* ---------------------- Admin: Rifas ---------------------- */
    public function page_rifas() {
        if ( ! current_user_can('manage_options') ) { return; }
        global $wpdb;

        // Crear rifa (POST)
        if ( isset($_POST['dm_crear_rifa']) && check_admin_referer('dm_crear_rifa_nonce') ) {
            $nombre   = sanitize_text_field($_POST['nombre'] ?? '');
            $desc     = wp_kses_post($_POST['descripcion'] ?? '');
            $fecha    = sanitize_text_field($_POST['fecha'] ?? '');
            $loteria  = sanitize_text_field($_POST['loteria'] ?? '');
            $total    = intval($_POST['total_numeros'] ?? 0);
            $precio   = intval($_POST['precio'] ?? 0);
            $wa       = preg_replace('/[^0-9]/', '', $_POST['wa_e164'] ?? '');
            $css      = isset($_POST['css_activo']) ? 1 : 0;
            $gracias  = intval($_POST['gracias_page_id'] ?? 0);

            $wpdb->insert($this->tbl_rifas, array(
                'nombre' => $nombre,
                'descripcion' => $desc,
                'fecha' => $fecha ? date('Y-m-d H:i:s', strtotime($fecha)) : NULL,
                'loteria' => $loteria,
                'total_numeros' => $total,
                'precio' => $precio,
                'wa_e164' => $wa,
                'nequi_qr_url' => NULL,
                'css_activo' => $css,
                'gracias_page_id' => $gracias,
            ));

            $rifa_id = intval($wpdb->insert_id);
            if ($rifa_id > 0 && $total > 0) {
                $values = array();
                for ($i = 0; $i < $total; $i++) {
                    $values[] = $wpdb->prepare("(%d,%s,'disponible',NULL,NOW())", $rifa_id, $this->pad3($i));
                }
                if ( ! empty($values) ) {
                    $sql = "INSERT INTO {$this->tbl_numeros} (rifa_id, numero, estado, reserva_id, updated_at) VALUES " . implode(',', $values);
                    $wpdb->query($sql);
                }
                echo '<div class="notice notice-success"><p>Rifa creada. Shortcode: <code>[rifa_selector id="'.esc_attr($rifa_id).'"]</code></p></div>';
            } else {
                echo '<div class="notice notice-error"><p>No se pudo crear la rifa.</p></div>';
            }
        }

        $rifas = $wpdb->get_results("SELECT * FROM {$this->tbl_rifas} ORDER BY id DESC");
        ?>
        <div class="wrap">
            <h1>DM Rifas</h1>
            <h2>Crear nueva rifa</h2>
            <form method="post">
                <?php wp_nonce_field('dm_crear_rifa_nonce'); ?>
                <table class="form-table">
                    <tr><th>Nombre</th><td><input type="text" name="nombre" class="regular-text" required></td></tr>
                    <tr><th>Descripción</th><td><textarea name="descripcion" class="large-text" rows="3"></textarea></td></tr>
                    <tr><th>Fecha (opcional)</th><td><input type="datetime-local" name="fecha"></td></tr>
                    <tr><th>Lotería</th><td><input type="text" name="loteria" class="regular-text"></td></tr>
                    <tr><th>Total de números</th><td><input type="number" name="total_numeros" value="470" min="1" required> <em>(ej: 470 crea 000–469)</em></td></tr>
                    <tr><th>Precio por boleta</th><td><input type="number" name="precio" value="60000" min="0" required></td></tr>
                    <tr><th>WhatsApp (E.164)</th><td><input type="text" name="wa_e164" value="573123625582" required></td></tr>
                    <tr><th>Activar CSS del plugin</th><td><label><input type="checkbox" name="css_activo" value="1"> Sí</label></td></tr>
                    <tr><th>Página de Gracias</th><td>
                        <?php
                        wp_dropdown_pages(array(
                            'name' => 'gracias_page_id',
                            'selected' => 0,
                            'show_option_none' => '(sin redirección)',
                            'option_none_value' => 0
                        ));
                        ?>
                    </td></tr>
                </table>
                <p><button type="submit" class="button button-primary" name="dm_crear_rifa" value="1">Crear rifa</button></p>
            </form>

            <h2>Rifas existentes</h2>
            <table class="widefat striped">
                <thead><tr><th>ID</th><th>Nombre</th><th>Total</th><th>Precio</th><th>WhatsApp</th><th>Shortcode</th><th>Acciones</th></tr></thead>
                <tbody>
                <?php if ($rifas): foreach($rifas as $r): ?>
                    <tr>
                        <td><?php echo esc_html($r->id); ?></td>
                        <td><?php echo esc_html($r->nombre); ?></td>
                        <td><?php echo esc_html($r->total_numeros); ?></td>
                        <td><?php echo esc_html(number_format($r->precio,0,',','.')); ?></td>
                        <td><?php echo esc_html($r->wa_e164); ?></td>
                        <td><code>[rifa_selector id="<?php echo esc_attr($r->id); ?>"]</code></td>
                        <td>
                            <a class="button" href="<?php echo admin_url('admin.php?page=dm-rifa-compradores&rifa_id='.intval($r->id)); ?>">Ver compradores</a>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="7">No hay rifas.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /* ---------------------- Admin: Compradores ---------------------- */
    public function page_compradores() {
        if ( ! current_user_can('manage_options') ) { return; }
        global $wpdb;
        $rifa_id = intval($_GET['rifa_id'] ?? 0);
        $view_reserva = intval($_GET['view'] ?? 0);

        $rifas = $wpdb->get_results("SELECT id,nombre FROM {$this->tbl_rifas} ORDER BY id DESC");
        if (!$rifas) { 
            echo '<div class="wrap"><h1>Compradores</h1><p>Primero crea una rifa.</p></div>'; 
            return; 
        }

        if (!$rifa_id) { $rifa_id = intval($rifas[0]->id); }
        $rifa = $this->fetch_rifa($rifa_id);

        // Vista detalle de una reserva
        if ($view_reserva > 0) {
            $this->render_reserva_detail($view_reserva, $rifa_id);
            return;
        }

        // Lista de compradores con estado calculado en tiempo real
        $res = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->tbl_reservas} WHERE rifa_id = %d ORDER BY id DESC", $rifa_id));
        
        // Calcular el estado real de cada reserva basado en sus números
        foreach($res as $reserva) {
            $reserva->status_real = $this->calcular_estado_reserva($reserva, $rifa_id);
        }
        
        $export_url = wp_nonce_url(admin_url('admin-post.php?action=dm_rifa_export_csv&rifa_id='.$rifa_id), 'dm_rifa_export_csv');

        ?>
        <div class="wrap">
            <h1>Compradores - <?php echo esc_html($rifa->nombre); ?></h1>
            <form method="get" style="margin:10px 0;">
                <input type="hidden" name="page" value="dm-rifa-compradores">
                <select name="rifa_id">
                    <?php foreach($rifas as $r): ?>
                        <option value="<?php echo esc_attr($r->id); ?>" <?php selected(intval($r->id), $rifa_id); ?>><?php echo esc_html($r->nombre); ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="button">Ver</button>
                <a class="button button-primary" href="<?php echo esc_url($export_url); ?>">Exportar CSV</a>
            </form>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nombre</th>
                        <th>Email</th>
                        <th>Teléfono</th>
                        <th>Números</th>
                        <th>Total</th>
                        <th>Estatus</th>
                        <th>Creado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($res): foreach($res as $row): ?>
                    <tr>
                        <td><?php echo esc_html($row->id); ?></td>
                        <td><?php echo esc_html($row->nombre); ?></td>
                        <td><?php echo esc_html($row->email); ?></td>
                        <td><?php echo esc_html($row->telefono); ?></td>
                        <td><?php echo esc_html($row->numeros_csv); ?></td>
                        <td><?php echo esc_html(number_format($row->total,0,',','.')); ?></td>
                        <td>
                            <span style="padding:3px 8px;border-radius:3px;background:<?php 
                                echo $row->status === 'pagado' ? '#d9f7d9' : ($row->status === 'reservado' ? '#fff5cc' : '#f0f0f0'); 
                            ?>">
                                <?php echo esc_html($row->status); ?>
                            </span>
                        </td>
                        <td><?php echo esc_html($row->created_at); ?></td>
                        <td>
                            <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=dm-rifa-compradores&rifa_id='.$rifa_id.'&view='.$row->id)); ?>">
                                Gestionar números
                            </a>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="9">Sin compradores aún.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function render_reserva_detail($reserva_id, $rifa_id) {
        global $wpdb;
        $reserva = $this->fetch_reserva($reserva_id);
        
        if (!$reserva || intval($reserva->rifa_id) !== $rifa_id) {
            echo '<div class="wrap"><h1>Error</h1><p>Reserva no encontrada.</p></div>';
            return;
        }

        $rifa = $this->fetch_rifa($rifa_id);
        $numeros_arr = array_map('trim', explode(',', $reserva->numeros_csv));
        
        // Obtener estado actual de cada número
        $numeros_estado = array();
        if (!empty($numeros_arr)) {
            $place = implode(',', array_fill(0, count($numeros_arr), '%s'));
            $query = $wpdb->prepare(
                "SELECT numero, estado FROM {$this->tbl_numeros} WHERE rifa_id = %d AND numero IN ($place)",
                array_merge(array($rifa_id), $numeros_arr)
            );
            $results = $wpdb->get_results($query);
            foreach($results as $r) {
                $numeros_estado[$r->numero] = $r->estado;
            }
        }

        ?>
        <div class="wrap">
            <h1>Gestionar Reserva #<?php echo esc_html($reserva_id); ?></h1>
            <p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=dm-rifa-compradores&rifa_id='.$rifa_id)); ?>" class="button">
                    ← Volver a compradores
                </a>
            </p>

            <div style="background:#fff;border:1px solid #ccc;padding:15px;margin:15px 0;">
                <h2>Información del comprador</h2>
                <p><strong>Nombre:</strong> <?php echo esc_html($reserva->nombre); ?></p>
                <p><strong>Email:</strong> <?php echo esc_html($reserva->email); ?></p>
                <p><strong>Teléfono:</strong> <?php echo esc_html($reserva->telefono); ?></p>
                <p><strong>Total:</strong> $<?php echo esc_html(number_format($reserva->total,0,',','.')); ?></p>
                <p><strong>Estado global:</strong> <?php echo esc_html($reserva->status); ?></p>
                <p><strong>Creado:</strong> <?php echo esc_html($reserva->created_at); ?></p>
            </div>

            <h2>Números de esta reserva</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('dm_rifa_update_reserva'); ?>
                <input type="hidden" name="action" value="dm_rifa_update_reserva">
                <input type="hidden" name="reserva_id" value="<?php echo esc_attr($reserva_id); ?>">
                <input type="hidden" name="rifa_id" value="<?php echo esc_attr($rifa_id); ?>">
                
                <div style="margin:10px 0;">
                    <button class="button" type="submit" name="cambiar_estado" value="reservado">Marcar seleccionados como Reservado</button>
                    <button class="button button-primary" type="submit" name="cambiar_estado" value="pagado">Marcar seleccionados como Pagado</button>
                    <button class="button" type="submit" name="cambiar_estado" value="disponible">Liberar seleccionados (Disponible)</button>
                </div>

                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th style="width:50px;"><input type="checkbox" id="dm-checkall"></th>
                            <th>Número</th>
                            <th>Estado actual</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach($numeros_arr as $num): 
                        $num = trim($num);
                        if ($num === '') continue;
                        $estado_actual = $numeros_estado[$num] ?? 'desconocido';
                        $bg_color = $estado_actual === 'pagado' ? '#d9f7d9' : ($estado_actual === 'reservado' ? '#fff5cc' : '#f0f0f0');
                    ?>
                        <tr>
                            <td><input type="checkbox" name="numeros[]" value="<?php echo esc_attr($num); ?>"></td>
                            <td><strong><?php echo esc_html($num); ?></strong></td>
                            <td>
                                <span style="padding:3px 8px;border-radius:3px;background:<?php echo $bg_color; ?>">
                                    <?php echo esc_html($estado_actual); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </form>
        </div>
        <?php
    }

    public function admin_post_update_reserva() {
        if ( ! current_user_can('manage_options') ) { wp_die('Permisos insuficientes'); }
        check_admin_referer('dm_rifa_update_reserva');
        
        global $wpdb;
        $reserva_id = intval($_POST['reserva_id'] ?? 0);
        $rifa_id = intval($_POST['rifa_id'] ?? 0);
        $estado = sanitize_text_field($_POST['cambiar_estado'] ?? '');
        $numeros = array_map('sanitize_text_field', $_POST['numeros'] ?? array());

        if (!$reserva_id || !$rifa_id || !in_array($estado, array('disponible','reservado','pagado')) || empty($numeros)) {
            wp_redirect(admin_url('admin.php?page=dm-rifa-compradores&rifa_id='.$rifa_id.'&view='.$reserva_id));
            exit;
        }

        $place = implode(',', array_fill(0, count($numeros), '%s'));
        
        if ($estado === 'disponible') {
            // Liberar números: quitar reserva_id
            $wpdb->query($wpdb->prepare(
                "UPDATE {$this->tbl_numeros} SET estado = %s, reserva_id = NULL, updated_at = NOW() WHERE rifa_id = %d AND numero IN ($place)",
                array_merge(array($estado, $rifa_id), $numeros)
            ));
        } else {
            // Reservar o pagar: mantener/establecer reserva_id
            $wpdb->query($wpdb->prepare(
                "UPDATE {$this->tbl_numeros} SET estado = %s, reserva_id = %d, updated_at = NOW() WHERE rifa_id = %d AND numero IN ($place)",
                array_merge(array($estado, $reserva_id, $rifa_id), $numeros)
            ));
        }

        // Actualizar estado global de la reserva basado en todos sus números
        $reserva = $this->fetch_reserva($reserva_id);
        $nuevo_status = $this->calcular_estado_reserva($reserva, $rifa_id);

        $wpdb->update(
            $this->tbl_reservas,
            array('status' => $nuevo_status),
            array('id' => $reserva_id)
        );

        wp_redirect(admin_url('admin.php?page=dm-rifa-compradores&rifa_id='.$rifa_id.'&view='.$reserva_id.'&updated=1'));
        exit;
    }

    public function admin_post_export_csv() {
        if ( ! current_user_can('manage_options') ) { wp_die('Permisos insuficientes'); }
        check_admin_referer('dm_rifa_export_csv');
        global $wpdb;
        $rifa_id = intval($_GET['rifa_id'] ?? 0);
        $rifa = $this->fetch_rifa($rifa_id);
        if (!$rifa) { wp_die('Rifa no encontrada'); }

        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->tbl_reservas} WHERE rifa_id = %d ORDER BY id DESC", $rifa_id), ARRAY_A);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=compradores_rifa_'.$rifa_id.'.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, array('ID','Nombre','Email','Telefono','Numeros','Precio_Unit','Total','Status','Creado','Vence'));
        foreach($rows as $r) {
            fputcsv($out, array($r['id'],$r['nombre'],$r['email'],$r['telefono'],$r['numeros_csv'],$r['precio_unit'],$r['total'],$r['status'],$r['created_at'],$r['expires_at']));
        }
        fclose($out);
        exit;
    }

    /* ---------------------- Shortcode: Selector ---------------------- */
    public function sc_rifa_selector($atts) {
        global $wpdb;
        $atts = shortcode_atts(array('id' => 0), $atts);
        $rifa_id = intval($atts['id']);
        if (!$rifa_id) return '<div class="dm-rifa-error">Falta el atributo id en el shortcode.</div>';
        $rifa = $this->fetch_rifa($rifa_id);
        if (!$rifa) return '<div class="dm-rifa-error">Rifa no encontrada.</div>';

        $nums = $wpdb->get_results($wpdb->prepare("SELECT numero, estado FROM {$this->tbl_numeros} WHERE rifa_id = %d ORDER BY numero ASC", $rifa_id));
        $map = array();
        foreach($nums as $n) { $map[$n->numero] = $n->estado; }

        ob_start();
        ?>
        <div class="dm-rifa-wrap" data-rifa="<?php echo esc_attr($rifa_id); ?>" data-precio="<?php echo esc_attr($rifa->precio); ?>">
            <div class="dm-rifa-controls">
                <input type="text" class="dm-buscar" placeholder="Buscar número (ej. 023)">
                <select class="dm-filtro-estado">
                    <option value="">Todos</option>
                    <option value="disponible">Disponibles</option>
                    <option value="reservado">Reservados</option>
                    <option value="pagado">Pagados</option>
                </select>
                <div class="dm-contador"><strong>Seleccionaste 0</strong> – Total: $0</div>
            </div>

            <div class="dm-grid" role="grid" aria-label="Números de la rifa"></div>

            <div class="dm-form">
                <h3>Datos del comprador</h3>
                <label>Nombre<br><input type="text" class="dm-nombre"></label>
                <label>Email (opcional)<br><input type="email" class="dm-email"></label>
                <label>Teléfono<br><input type="tel" class="dm-telefono"></label>
                <button type="button" class="button button-primary dm-continuar">Continuar</button>
                <div class="dm-msg" aria-live="polite"></div>
            </div>
        </div>
        <script>
        window.DM_RIFA_STATE = window.DM_RIFA_STATE || {};
        window.DM_RIFA_STATE[<?php echo intval($rifa_id); ?>] = <?php echo wp_json_encode($map); ?>;
        </script>
        <?php
        return ob_get_clean();
    }

    /* ---------------------- Shortcode: Confirmación ---------------------- */
    public function sc_rifa_confirm($atts) {
        $rifa_id = intval($_GET['rifa'] ?? 0);
        $token   = sanitize_text_field($_GET['t'] ?? '');

        if (!$rifa_id || $token === '') {
            return '<div class="dm-rifa-error">Faltan parámetros de confirmación.</div>';
        }
        $rifa = $this->fetch_rifa($rifa_id);
        $res  = $this->fetch_reserva_by_token($token);
        if (!$rifa || !$res || intval($res->rifa_id) !== $rifa_id) {
            return '<div class="dm-rifa-error">No se encontró la reserva.</div>';
        }

        $nums  = esc_html($res->numeros_csv);
        $total = number_format(intval($res->total), 0, ',', '.');
        $wa    = preg_replace('/[^0-9]/','', (string)$rifa->wa_e164);
        $msg   = rawurlencode("Hola, soy {$res->nombre}. Acabo de pagar mis boletas: {$nums}. Total: $".$total.". Adjunto comprobante de Nequi.");
        $wa_url = "https://wa.me/{$wa}?text={$msg}";

        ob_start(); ?>
        <div class="dm-rifa-confirm">
            <h2>¡Tus números han sido reservados!</h2>
            <p><strong>Nombre:</strong> <?php echo esc_html($res->nombre); ?><br>
               <strong>Teléfono:</strong> <?php echo esc_html($res->telefono); ?><br>
               <strong>Email:</strong> <?php echo esc_html($res->email); ?></p>
            <p><strong>Números:</strong> <?php echo $nums; ?><br>
               <strong>Precio unitario:</strong> $<?php echo number_format(intval($res->precio_unit),0,',','.'); ?><br>
               <strong>Total:</strong> $<?php echo $total; ?></p>
            <p>Envía el comprobante de Nequi lo antes posible para asegurar tu participación.</p>
            <button type="button" class="button button-primary" onclick="window.location.href='<?php echo esc_url($wa_url); ?>'">Enviar comprobante por WhatsApp</button>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ---------------------- AJAX: Reservar ---------------------- */
    public function ajax_reservar() {
        check_ajax_referer('dm_rifa_nonce','nonce');
        global $wpdb;

        $rifa_id = intval($_POST['rifa_id'] ?? 0);
        $nlist   = $_POST['numeros'] ?? array();
        $nombre  = sanitize_text_field($_POST['nombre'] ?? '');
        $email   = sanitize_text_field($_POST['email'] ?? '');
        $tel     = sanitize_text_field($_POST['telefono'] ?? '');

        if (!$rifa_id || empty($nlist) || !$nombre || !$tel) {
            wp_send_json_error(array('message'=>'Datos incompletos'), 400);
        }
        $rifa = $this->fetch_rifa($rifa_id);
        if (!$rifa) {
            wp_send_json_error(array('message'=>'Rifa no encontrada'), 404);
        }

        $nums = array();
        foreach($nlist as $k=>$v){
            $v = strtoupper(trim($v));
            $v = str_pad(preg_replace('/\D/','',$v), 3, '0', STR_PAD_LEFT);
            if ($v !== '') $nums[] = $v;
        }
        $nums = array_values(array_unique($nums));
        if (empty($nums)) {
            wp_send_json_error(array('message'=>'No se enviaron números válidos'), 400);
        }

        $place = implode(',', array_fill(0,count($nums), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare("SELECT numero, estado FROM {$this->tbl_numeros} WHERE rifa_id = %d AND numero IN ($place)", array_merge(array($rifa_id), $nums)));
        $no_disp = array();
        $found = array();
        foreach($rows as $r){ $found[$r->numero] = $r->estado; }
        foreach($nums as $n){
            if ( ! isset($found[$n]) || $found[$n] !== 'disponible') { $no_disp[] = $n; }
        }
        if (!empty($no_disp)) {
            wp_send_json_error(array('message'=>'No disponibles: '.implode(', ', $no_disp)), 409);
        }

        $precio_unit = intval($rifa->precio);
        $total   = $precio_unit * count($nums);
        $expires = date('Y-m-d H:i:s', time() + 24*3600);
        $token   = function_exists('random_bytes')
            ? bin2hex(random_bytes(16))
            : wp_generate_password(32, false);

        $wpdb->insert($this->tbl_reservas, array(
            'rifa_id'      => $rifa_id,
            'nombre'       => $nombre,
            'email'        => $email,
            'telefono'     => $tel,
            'numeros_csv'  => implode(', ', $nums),
            'precio_unit'  => $precio_unit,
            'total'        => $total,
            'status'       => 'reservado',
            'created_at'   => current_time('mysql'),
            'expires_at'   => $expires,
            'token'        => $token,
        ));
        $reserva_id = intval($wpdb->insert_id);
        if ($reserva_id <= 0) {
            wp_send_json_error(array('message'=>'No se pudo crear la reserva'), 500);
        }

        $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$this->tbl_numeros} WHERE rifa_id = %d AND numero IN ($place)", array_merge(array($rifa_id), $nums)));
        if ($ids) {
            $place2 = implode(',', array_fill(0, count($ids), '%d'));
            $wpdb->query($wpdb->prepare("UPDATE {$this->tbl_numeros} SET estado = 'reservado', reserva_id = %d, updated_at = NOW() WHERE id IN ($place2)", array_merge(array($reserva_id), $ids)));
        }

        $confirm_url = '';
        if (intval($rifa->gracias_page_id) > 0) {
            $confirm_url = add_query_arg(
                array('rifa'=>$rifa_id,'t'=>$token),
                get_permalink(intval($rifa->gracias_page_id))
            );
        }

        wp_send_json_success(array(
            'reserva_id'  => $reserva_id,
            'confirm_url' => $confirm_url,
        ));
    }
}

DM_Rifa_Unificado::instance();