<?php
/**
 * Plugin Name: DM Rifa Unificado
 * Description: Selector de números, reservas y página de confirmación con WhatsApp + panel de gestión en el admin (todo en un solo plugin).
 * Version: 2.0.0
 * Author: DM Studio SAS
 * License: GPL2
 */

if (!defined('ABSPATH')) {
    exit;
}

class DM_Rifa_Unificado
{
    private static $instance = null;
    private $version = '2.0.0';
    private $tbl_rifas;
    private $tbl_numeros;
    private $tbl_reservas;
    private $tbl_vendedores;
    private $tbl_boletas;
    private $tbl_arqueos;

    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        global $wpdb;
        $this->tbl_rifas = $wpdb->prefix . 'dm_rifas';
        $this->tbl_numeros = $wpdb->prefix . 'dm_rifa_numeros';
        $this->tbl_reservas = $wpdb->prefix . 'dm_rifa_reservas';
        $this->tbl_vendedores = $wpdb->prefix . 'dm_rifa_vendedores';
        $this->tbl_boletas = $wpdb->prefix . 'dm_rifa_boletas';
        $this->tbl_arqueos = $wpdb->prefix . 'dm_rifa_arqueos';

        register_activation_hook(__FILE__, array($this, 'on_activate'));
        add_action('admin_menu', array($this, 'admin_menu'));
        add_shortcode('rifa_selector', array($this, 'sc_rifa_selector'));
        add_shortcode('rifa_confirm', array($this, 'sc_rifa_confirm'));

        add_action('wp_enqueue_scripts', array($this, 'enqueue_front'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin'));

        add_action('wp_ajax_dm_rifa_reservar', array($this, 'ajax_reservar'));
        add_action('wp_ajax_nopriv_dm_rifa_reservar', array($this, 'ajax_reservar'));
        add_action('wp_ajax_dm_rifa_get_states', array($this, 'ajax_get_states'));
        add_action('wp_ajax_nopriv_dm_rifa_get_states', array($this, 'ajax_get_states'));

        add_action('admin_post_dm_rifa_export_csv', array($this, 'admin_post_export_csv'));
        add_action('admin_post_dm_rifa_update_reserva', array($this, 'admin_post_update_reserva'));
        add_action('admin_post_dm_rifa_manual_cleanup', array($this, 'admin_post_manual_cleanup'));
        add_action('admin_post_dm_rifa_export_report', array($this, 'admin_post_export_report'));
        add_action('admin_post_dm_rifa_export_vendedor', array($this, 'admin_post_export_vendedor'));
        add_action('admin_post_dm_rifa_print_ticket', array($this, 'admin_post_print_ticket'));
        add_action('admin_post_dm_rifa_delete_reserva', array($this, 'admin_post_delete_reserva'));
        add_action('admin_post_dm_rifa_liberar_reserva', array($this, 'admin_post_liberar_reserva'));
        add_action('admin_post_dm_rifa_restore_data', array($this, 'admin_post_restore_data'));
        add_action('wp_ajax_dm_boleta_preview', array($this, 'ajax_boleta_preview'));

        // Cron logic - Disabled by user request for manual control
        // add_action('dm_rifa_cleanup_expired_hook', array($this, 'cron_cleanup_expired'));

        // Ensure columns
        add_action('admin_init', array($this, 'ensure_rifas_columns'));
        add_action('admin_init', array($this, 'ensure_reservas_columns'));
        add_action('admin_init', array($this, 'ensure_numeros_columns'));
        add_action('admin_init', array($this, 'ensure_arqueos_table'));
    }

    public function on_activate()
    {
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
            background_id BIGINT UNSIGNED NULL,
            ticket_config LONGTEXT NULL,
            boleta_id BIGINT UNSIGNED NULL,
            meta_recaudo INT NOT NULL DEFAULT 0,
            modo_venta VARCHAR(20) NOT NULL DEFAULT 'mixto',
            activo TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id)
        ) $charset;";

        $sql_numeros = "CREATE TABLE {$this->tbl_numeros} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            rifa_id BIGINT UNSIGNED NOT NULL,
            numero VARCHAR(4) NOT NULL,
            estado VARCHAR(12) NOT NULL DEFAULT 'disponible',
            reserva_id BIGINT UNSIGNED NULL,
            vendedor_id BIGINT UNSIGNED NULL,
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
            vendedor_id BIGINT UNSIGNED NULL,
            forma_pago VARCHAR(20) NULL DEFAULT 'transferencia',
            impreso TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_rifa (rifa_id),
            KEY idx_token (token)
        ) $charset;";

        $sql_vendedores = "CREATE TABLE {$this->tbl_vendedores} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            nombre VARCHAR(120) NOT NULL,
            email VARCHAR(120) NULL,
            telefono VARCHAR(30) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset;";

        $sql_boletas = "CREATE TABLE {$this->tbl_boletas} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            nombre VARCHAR(255) NOT NULL,
            background_id BIGINT UNSIGNED NULL,
            ticket_config LONGTEXT NULL,
            PRIMARY KEY (id)
        ) $charset;";

        $sql_arqueos = "CREATE TABLE {$this->tbl_arqueos} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            vendedor_id BIGINT UNSIGNED NOT NULL,
            rifa_id BIGINT UNSIGNED NULL,
            monto INT NOT NULL DEFAULT 0,
            fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            observaciones TEXT NULL,
            PRIMARY KEY (id),
            KEY idx_vendedor (vendedor_id)
        ) $charset;";

        dbDelta($sql_rifas);
        dbDelta($sql_numeros);
        dbDelta($sql_reservas);
        dbDelta($sql_vendedores);
        dbDelta($sql_boletas);
        dbDelta($sql_arqueos);

        // Limpiar cron antiguo si existe (el usuario prefiere control manual)
        wp_clear_scheduled_hook('dm_rifa_cleanup_expired_hook');
    }

    public function ensure_rifas_columns()
    {
        global $wpdb;
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$this->tbl_rifas}");
        if (is_array($columns) && !empty($columns)) {
            if (!in_array('meta_recaudo', $columns)) {
                $wpdb->query("ALTER TABLE {$this->tbl_rifas} ADD COLUMN meta_recaudo INT NOT NULL DEFAULT 0 AFTER boleta_id");
            }
            if (!in_array('modo_venta', $columns)) {
                $wpdb->query("ALTER TABLE {$this->tbl_rifas} ADD COLUMN modo_venta VARCHAR(20) NOT NULL DEFAULT 'mixto' AFTER meta_recaudo");
            }
            if (!in_array('url_rifa', $columns)) {
                $wpdb->query("ALTER TABLE {$this->tbl_rifas} ADD COLUMN url_rifa VARCHAR(255) NULL AFTER boleta_id");
            }
            if (!in_array('activo', $columns)) {
                $wpdb->query("ALTER TABLE {$this->tbl_rifas} ADD COLUMN activo TINYINT(1) NOT NULL DEFAULT 1 AFTER modo_venta");
            }
        }
    }

    public function ensure_reservas_columns()
    {
        global $wpdb;
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$this->tbl_reservas}");
        if (is_array($columns) && !empty($columns)) {
            if (!in_array('vendedor_id', $columns)) {
                $wpdb->query("ALTER TABLE {$this->tbl_reservas} ADD COLUMN vendedor_id BIGINT UNSIGNED NULL AFTER token");
            }
            if (!in_array('forma_pago', $columns)) {
                $wpdb->query("ALTER TABLE {$this->tbl_reservas} ADD COLUMN forma_pago VARCHAR(20) NULL DEFAULT 'transferencia' AFTER vendedor_id");
            }
            if (!in_array('impreso', $columns)) {
                $wpdb->query("ALTER TABLE {$this->tbl_reservas} ADD COLUMN impreso TINYINT(1) NOT NULL DEFAULT 0 AFTER forma_pago");
            }
            if (!in_array('comprobante_url', $columns)) {
                $wpdb->query("ALTER TABLE {$this->tbl_reservas} ADD COLUMN comprobante_url TEXT NULL AFTER impreso");
            }
        }
    }

    public function ensure_numeros_columns()
    {
        global $wpdb;
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$this->tbl_numeros}");
        if (is_array($columns) && !empty($columns)) {
            if (!in_array('vendedor_id', $columns)) {
                $wpdb->query("ALTER TABLE {$this->tbl_numeros} ADD COLUMN vendedor_id BIGINT UNSIGNED NULL AFTER reserva_id");
            }
        }
    }

    public function ensure_arqueos_table()
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->tbl_arqueos}'");
        if ($table_exists) {
            return;
        }

        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$this->tbl_arqueos} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            vendedor_id BIGINT UNSIGNED NOT NULL,
            rifa_id BIGINT UNSIGNED NULL,
            monto INT NOT NULL DEFAULT 0,
            fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            observaciones TEXT NULL,
            PRIMARY KEY (id),
            KEY idx_vendedor (vendedor_id)
        ) $charset;";

        dbDelta($sql);
        error_log('DM Rifa: tabla ' . $this->tbl_arqueos . ' creada automaticamente.');
    }

    /* ---------------------- Assets ---------------------- */
    public function enqueue_front()
    {
        wp_enqueue_script('dm-rifa-front', plugins_url('assets/frontend.js', __FILE__), array('jquery'), '1.2.5', true);
        wp_localize_script('dm-rifa-front', 'DMRIFA', array(
            'ajax' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dm_rifa_nonce')
        ));
        wp_enqueue_style('dm-rifa-style', plugins_url('assets/style.css', __FILE__), array(), '1.2.5');
    }

    public function enqueue_admin($hook)
    {
        if (strpos($hook, 'dm-rifa') !== false) {
            wp_enqueue_media();
            wp_enqueue_style('dm-rifa-front', plugins_url('assets/style.css', __FILE__), array(), $this->version);
            wp_enqueue_style('dm-rifa-admin', plugins_url('assets/admin.css', __FILE__), array(), $this->version);
            wp_enqueue_script('dm-rifa-admin', plugins_url('assets/admin.js', __FILE__), array('jquery'), $this->version, true);
            wp_localize_script('dm-rifa-admin', 'DMRIFA', array(
                'ajax' => admin_url('admin-ajax.php'),
                'ajaxurl' => admin_url('admin-ajax.php'), // Añadido para compatibilidad
                'nonce' => wp_create_nonce('dm_rifa_nonce')
            ));
        }
    }

    /* ---------------------- Admin Menu ---------------------- */
    public function admin_menu()
    {
        add_menu_page(
            'DM Rifas',
            'DM Rifas',
            'manage_options',
            'dm-rifa',
            array($this, 'page_rifas'),
            'dashicons-tickets',
            25
        );
        add_submenu_page('dm-rifa', 'Reservas', 'Reservas y Ventas', 'manage_options', 'dm-rifa-compradores', array($this, 'page_compradores'));
        add_submenu_page('dm-rifa', 'Vendedores', 'Vendedores', 'manage_options', 'dm-rifa-vendedores', array($this, 'page_vendedores'));

        add_submenu_page('dm-rifa', 'Dashboard', 'Dashboard', 'manage_options', 'dm-rifa-dashboard', array($this, 'page_dashboard'));
        add_submenu_page('dm-rifa', 'Boletas', 'Diseñador Boletas', 'manage_options', 'dm-rifa-boletas', array($this, 'page_boletas'));
    }

    /* ---------------------- Helpers ---------------------- */
    private function pad3($n)
    {
        $n = intval($n);
        if ($n < 0)
            $n = 0;
        if ($n > 9999)
            $n = 9999;
        return str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    }

    private function fetch_rifa($id)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tbl_rifas} WHERE id = %d", $id));
    }

    private function get_context_rifa_id()
    {
        $rifa_id = isset($_GET['rifa_id']) ? intval($_GET['rifa_id']) : 0;
        $user_id = get_current_user_id();

        if ($rifa_id > 0) {
            update_user_meta($user_id, 'dm_rifa_last_id', $rifa_id);
            return $rifa_id;
        }

        $last_id = get_user_meta($user_id, 'dm_rifa_last_id', true);
        if ($last_id) {
            // Verificar que la rifa existe antes de devolverla
            global $wpdb;
            $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->tbl_rifas} WHERE id = %d", $last_id));
            if ($exists) {
                return intval($last_id);
            }
        }

        return 0;
    }

    private function fetch_reserva($id)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tbl_reservas} WHERE id = %d", $id));
    }

    private function fetch_reserva_by_token($token)
    {
        global $wpdb;
        $token = sanitize_text_field($token);
        if ($token === '')
            return null;
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->tbl_reservas} WHERE token = %s LIMIT 1", $token)
        );
    }

    // Calcular el estado real de una reserva basado en el estado de sus números
    private function calcular_estado_reserva($reserva, $rifa_id)
    {
        global $wpdb;

        $numeros_arr = array_filter(array_map('trim', explode(',', $reserva->numeros_csv)));
        if (empty($numeros_arr)) {
            return 'reservado';
        }

        $normalized_nums = array_map(array($this, 'pad3'), $numeros_arr);
        $place = implode(',', array_fill(0, count($normalized_nums), '%s'));
        $query = $wpdb->prepare(
            "SELECT numero, estado FROM {$this->tbl_numeros} WHERE rifa_id = %d AND numero IN ($place)",
            array_merge(array($rifa_id), $normalized_nums)
        );
        $rows = $wpdb->get_results($query);

        if (empty($rows)) {
            return 'reservado';
        }

        $estados = array();
        foreach ($rows as $r) {
            $estados[] = $r->estado;
        }

        // Si TODOS están pagados o asignados → pagado
        $completados = array('pagado', 'asignado');
        foreach ($estados as $est) {
            if (!in_array($est, $completados)) {
                return 'reservado'; // Basta un número no pagado para quedar como reservado
            }
        }

        return 'pagado';
    }
    /**
     * Genera la URL de WhatsApp para enviar la boleta
     */
    public function get_whatsapp_ticket_url($reserva, $rifa)
    {
        $vendedor = null;
        if ($reserva->vendedor_id) {
            global $wpdb;
            $vendedor = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tbl_vendedores} WHERE id = %d", $reserva->vendedor_id));
        }

        $numeros = $reserva->numeros_csv;
        $msj = "Hola " . $reserva->nombre . ",\n\n";
        $msj .= "Aquí tienes tu boleta para " . $rifa->nombre . ".\n";
        $msj .= "Números: " . $numeros . "\n";
        $msj .= "Estado: " . strtoupper($reserva->status) . "\n\n";

        $ticket_url = admin_url('admin-post.php?action=dm_rifa_print_ticket&reserva_id=' . $reserva->id . '&t=' . $reserva->token);
        $msj .= "Puedes ver/descargar tu boleta aquí: " . $ticket_url . "\n\n";

        if ($vendedor) {
            $msj .= "Vendedor: " . $vendedor->nombre . " (" . $vendedor->telefono . ")\n";
        }

        return "https://wa.me/" . preg_replace('/[^0-9]/', '', $reserva->telefono) . "/?text=" . urlencode($msj);
    }
    /* ---------------------- Admin: Dashboard ---------------------- */
    public function page_dashboard()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        global $wpdb;

        // Rifas activas para selección
        $active_rifas = $wpdb->get_results("SELECT id, nombre, modo_venta FROM {$this->tbl_rifas} WHERE activo = 1 ORDER BY id DESC");
        $active_count = count($active_rifas);
        $selected_rifa_id = $this->get_context_rifa_id();

        // Auto-selección si solo hay una
        if ($selected_rifa_id === 0 && $active_count === 1) {
            $selected_rifa_id = $active_rifas[0]->id;
        }

        // Pantalla de selección si hay varias y ninguna seleccionada
        if ($selected_rifa_id === 0 && $active_count > 0) {
            ?>
            <div class="wrap">
                <h1>Dashboard de Rendimiento</h1>
                <p>Selecciona una rifa para ver sus estadísticas individuales:</p>
                <div
                    style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; margin-top: 20px;">
                    <?php foreach ($active_rifas as $r): ?>
                        <a href="<?php echo admin_url('admin.php?page=dm-rifa-dashboard&rifa_id=' . $r->id); ?>"
                            style="text-decoration: none; color: inherit;">
                            <div class="card"
                                style="background: #fff; padding: 25px; border: 1px solid #ccd0d4; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); transition: transform 0.2s, box-shadow 0.2s;"
                                onmouseover="this.style.transform='translateY(-3px)'; this.style.box_shadow='0 4px 8px rgba(0,0,0,0.1)';"
                                onmouseout="this.style.transform='translateY(0)'; this.style.box_shadow='0 2px 4px rgba(0,0,0,0.05)';">
                                <h3 style="margin: 0; color: #2271b1;"><?php echo esc_html($r->nombre); ?></h3>
                                <p style="margin: 10px 0 0; color: #666;">
                                    Modo:
                                    <strong><?php echo $r->modo_venta === 'virtual' ? 'Sólo Virtual' : 'Mixto (Físico/Virtual)'; ?></strong>
                                </p>
                                <span class="button button-primary" style="margin-top: 15px;">Ver Dashboard</span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php
            return;
        }

        if ($active_count === 0 && $selected_rifa_id === 0) {
            echo '<div class="wrap"><h1>Dashboard</h1><p>No hay rifas activas actualmente.</p></div>';
            return;
        }

        // Obtener datos de la rifa seleccionada
        $rifa = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tbl_rifas} WHERE id = %d", $selected_rifa_id));
        if (!$rifa) {
            echo '<div class="wrap"><h1>Dashboard</h1><p>Rifa no encontrada.</p><a href="admin.php?page=dm-rifa-dashboard" class="button">Volver al selector</a></div>';
            return;
        }

        $is_virtual = ($rifa->modo_venta === 'virtual');

        // Estadísticas de la rifa seleccionada
        $total_vendedores = $wpdb->get_var("SELECT COUNT(*) FROM {$this->tbl_vendedores}");

        $stats_query = $wpdb->prepare("
            SELECT 
                COUNT(*) as total_reservas,
                SUM(CASE WHEN status = 'pagado' THEN 1 ELSE 0 END) as total_pagadas,
                SUM(CASE WHEN status = 'reservado' THEN 1 ELSE 0 END) as total_reservadas,
                SUM(total) as recaudo_potencial,
                SUM(CASE WHEN status = 'pagado' THEN total ELSE 0 END) as recaudo_real,
                SUM(CASE WHEN status = 'pagado' AND forma_pago = 'efectivo' THEN total ELSE 0 END) as recaudo_efectivo,
                SUM(CASE WHEN status = 'pagado' AND (forma_pago = 'transferencia' OR forma_pago IS NULL OR forma_pago = '') THEN total ELSE 0 END) as recaudo_transferencia
            FROM {$this->tbl_reservas}
            WHERE rifa_id = %d
        ", $selected_rifa_id);
        $stats = $wpdb->get_row($stats_query);

        $ticket_stats = $wpdb->get_row($wpdb->prepare("
            SELECT 
                SUM(CASE WHEN n.estado = 'pagado' THEN 1 ELSE 0 END) as total_boletas_pagadas,
                SUM(CASE WHEN n.estado = 'reservado' THEN 1 ELSE 0 END) as total_boletas_reservadas,
                SUM(CASE WHEN n.estado = 'pagado' AND r.forma_pago = 'efectivo' THEN 1 ELSE 0 END) as total_boletas_fisicas
            FROM {$this->tbl_numeros} n
            LEFT JOIN {$this->tbl_reservas} r ON n.reserva_id = r.id
            WHERE n.rifa_id = %d
        ", $selected_rifa_id));

        // Metas
        $rifas_stats = $wpdb->get_results($wpdb->prepare("
            SELECT 
                r.id, r.nombre, r.meta_recaudo, r.precio, r.total_numeros,
                (SELECT COUNT(*) FROM {$this->tbl_numeros} n WHERE n.rifa_id = r.id AND n.estado = 'pagado') as boletas_vendidas,
                (SELECT COUNT(*) FROM {$this->tbl_numeros} n WHERE n.rifa_id = r.id AND n.estado = 'reservado') as boletas_reservadas,
                (SELECT SUM(res.total) FROM {$this->tbl_reservas} res WHERE res.rifa_id = r.id AND res.status = 'pagado') as total_recaudado
            FROM {$this->tbl_rifas} r
            WHERE r.id = %d
        ", $selected_rifa_id));

        // Top Vendedores para esta rifa
        $top_vendedores = $wpdb->get_results($wpdb->prepare("
            SELECT v.nombre, v.telefono,
            (SELECT COUNT(*) FROM {$this->tbl_numeros} n 
             JOIN {$this->tbl_reservas} res ON n.reserva_id = res.id 
             WHERE res.vendedor_id = v.id AND n.estado = 'pagado' AND res.rifa_id = %d) as ventas,
            (SELECT SUM(res.total) FROM {$this->tbl_reservas} res 
             WHERE res.vendedor_id = v.id AND res.status = 'pagado' AND res.rifa_id = %d) as recaudado
            FROM {$this->tbl_vendedores} v
            HAVING ventas > 0
            ORDER BY ventas DESC
            LIMIT 5
        ", $selected_rifa_id, $selected_rifa_id));

        // Peores Vendedores (con menos de 20 boletas vendidas)
        $worst_vendedores = $wpdb->get_results($wpdb->prepare("
            SELECT v.id, v.nombre, v.telefono,
            (SELECT COUNT(*) FROM {$this->tbl_numeros} n 
             JOIN {$this->tbl_reservas} res ON n.reserva_id = res.id 
             WHERE res.vendedor_id = v.id AND n.estado = 'pagado' AND res.rifa_id = %d) as ventas
            FROM {$this->tbl_vendedores} v
            HAVING ventas < 20
            ORDER BY ventas ASC
            LIMIT 10
        ", $selected_rifa_id));

        // Boletas disponibles (total - pagadas - reservadas)
        $total_boletas = intval($rifa->total_numeros);
        $boletas_pagadas = intval($ticket_stats->total_boletas_pagadas ?? 0);
        $boletas_reservadas = intval($ticket_stats->total_boletas_reservadas ?? 0);
        $boletas_disponibles = $total_boletas - $boletas_pagadas - $boletas_reservadas;

        // Vendedores con boletas reservadas (pendientes de pago)
        $vendedores_con_reservas = $wpdb->get_results($wpdb->prepare("
            SELECT v.id, v.nombre, v.telefono,
            (SELECT COUNT(*) FROM {$this->tbl_numeros} n 
             JOIN {$this->tbl_reservas} res ON n.reserva_id = res.id 
             WHERE res.vendedor_id = v.id AND n.estado = 'reservado' AND res.rifa_id = %d) as boletas_reservadas,
            (SELECT SUM(res.total) FROM {$this->tbl_reservas} res 
             WHERE res.vendedor_id = v.id AND res.status = 'reservado' AND res.rifa_id = %d) as monto_pendiente
            FROM {$this->tbl_vendedores} v
            HAVING boletas_reservadas > 0
            ORDER BY boletas_reservadas DESC
            LIMIT 15
        ", $selected_rifa_id, $selected_rifa_id));

        // Desglose por método de pago
        $pago_stats = $wpdb->get_results($wpdb->prepare("
            SELECT forma_pago, SUM(total) as total_monto, COUNT(*) as cantidad
            FROM {$this->tbl_reservas}
            WHERE rifa_id = %d AND status = 'pagado'
            GROUP BY forma_pago
        ", $selected_rifa_id));

        // Calcular montos por método de pago
        $monto_efectivo = 0;
        $monto_transferencia = 0;
        foreach ($pago_stats as $p) {
            if ($p->forma_pago === 'efectivo') {
                $monto_efectivo = floatval($p->total_monto);
            } else {
                $monto_transferencia += floatval($p->total_monto);
            }
        }

        // Total entregado en caja física (arqueos) para esta rifa
        // Incluye también arqueos guardados sin rifa_id (NULL) para no perder registros históricos
        $total_arqueos = floatval($wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(monto), 0) FROM {$this->tbl_arqueos} WHERE rifa_id = %d OR rifa_id IS NULL",
            $selected_rifa_id
        )));

        // Saldo pendiente = recaudo efectivo real − arqueos entregados
        $saldo_pendiente_caja = ($stats->recaudo_real ?? 0) - $total_arqueos;

        ?>
        <div class="wrap">
            <!-- SECCIÓN 1: Selector de Rifa + Seguimiento de Metas -->
            <div style="margin-bottom: 30px;">
                <!-- Header con selector -->
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <div>
                        <h1 style="margin: 0;">Dashboard</h1>
                        <p style="margin: 5px 0 0;">Monitoreo en tiempo real de rifas</p>
                    </div>
                    <?php if ($is_virtual): ?>
                        <div
                            style="background: #e7f5ec; color: #18502f; padding: 5px 12px; border-radius: 12px; font-weight: bold; font-size: 13px;">
                            🌐 Sólo Virtual
                        </div>
                    <?php else: ?>
                        <div
                            style="background: #e8f4fd; color: #1d4ed8; padding: 5px 12px; border-radius: 12px; font-weight: bold; font-size: 13px;">
                            🔄 Modelo Mixto
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Selector de Rifa -->
                <div
                    style="background: #fff; padding: 20px; border: 1px solid #e5e5e5; border-radius: 8px; margin-bottom: 20px;">
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <label style="font-weight: 600; min-width: 120px;">Rifa Activa:</label>
                        <select id="selector-rifa"
                            style="padding: 8px 12px; font-size: 14px; border: 1px solid #ddd; border-radius: 4px; min-width: 300px;"
                            onchange="window.location.href='admin.php?page=dm-rifa-dashboard&rifa_id=' + this.value;">
                            <?php foreach ($active_rifas as $r): ?>
                                <option value="<?php echo $r->id; ?>" <?php echo $r->id == $selected_rifa_id ? 'selected' : ''; ?>>
                                    <?php echo esc_html($r->nombre); ?> -
                                    <?php echo $r->modo_venta === 'virtual' ? 'Virtual' : 'Mixto'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Seguimiento de Metas -->
                <div style="background: #fff; padding: 20px; border: 1px solid #e5e5e5; border-radius: 8px;">
                    <h2 style="margin: 0 0 15px 0;">📊 Seguimiento de Metas</h2>
                    <?php foreach ($rifas_stats as $r):
                        $meta = intval($r->meta_recaudo);
                        if ($meta <= 0) {
                            $meta = intval($r->total_numeros) * intval($r->precio);
                        }
                        $recaudado = intval($r->total_recaudado);
                        $porcentaje = $meta > 0 ? min(100, round(($recaudado / $meta) * 100)) : 0;
                        $color = $porcentaje > 80 ? '#00a32a' : ($porcentaje > 40 ? '#dba617' : '#d63638');
                        ?>
                        <div
                            style="background: #f9fafb; padding: 15px; border-radius: 6px; border-left: 4px solid <?php echo $color; ?>;">
                            <h3 style="margin: 0 0 10px 0; font-size: 16px;"><?php echo esc_html($r->nombre); ?></h3>
                            <div
                                style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 12px; font-size: 13px;">
                                <div>
                                    <span style="color: #666;">Boletas Pagadas:</span>
                                    <strong
                                        style="display: block; font-size: 18px; color: #00a32a;"><?php echo intval($r->boletas_vendidas); ?></strong>
                                </div>
                                <div>
                                    <span style="color: #666;">Boletas Reservadas:</span>
                                    <strong
                                        style="display: block; font-size: 18px; color: #f59e0b;"><?php echo intval($r->boletas_reservadas); ?></strong>
                                </div>
                                <div>
                                    <span style="color: #666;">Recaudado:</span>
                                    <strong
                                        style="display: block; font-size: 18px; color: #2271b1;">$<?php echo number_format($recaudado, 0, ',', '.'); ?></strong>
                                </div>
                                <div>
                                    <span style="color: #666;">Meta:</span>
                                    <strong
                                        style="display: block; font-size: 18px; color: #666;">$<?php echo number_format($meta, 0, ',', '.'); ?></strong>
                                </div>
                            </div>
                            <div style="height: 16px; background: #e5e5e5; border-radius: 8px; overflow: hidden;">
                                <div
                                    style="width: <?php echo $porcentaje; ?>%; height: 100%; background: <?php echo $color; ?>; transition: width 1s;">
                                </div>
                            </div>
                            <div style="margin-top: 8px; text-align: right; font-weight: 600; color: <?php echo $color; ?>;">
                                <?php echo $porcentaje; ?>% alcanzado
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- SECCIÓN 2: Fila de Tarjetas con Métricas -->
            <div
                style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 30px;">
                <!-- Total Recaudado -->
                <div
                    style="background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #e5e5e5; border-left: 4px solid #2271b1; min-height: 120px;">
                    <h3 style="margin: 0 0 10px 0; font-size: 13px; color: #666; font-weight: 600;">💰 Total Recaudado</h3>
                    <div style="font-size: 28px; font-weight: bold; color: #1d2327;">
                        $<?php echo number_format(($stats->recaudo_real ?? 0), 0, ',', '.'); ?>
                    </div>
                </div>

                <!-- Boletas Pagadas -->
                <a href="<?php echo admin_url('admin.php?page=dm-rifa-compradores&rifa_id=' . $selected_rifa_id . '&status=pagado'); ?>"
                    style="text-decoration: none; color: inherit;">
                    <div style="background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #e5e5e5; border-left: 4px solid #00a32a; min-height: 120px; cursor: pointer; transition: transform 0.2s;"
                        onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
                        <h3 style="margin: 0 0 10px 0; font-size: 13px; color: #666; font-weight: 600;">✅ Boletas Pagadas</h3>
                        <div style="font-size: 28px; font-weight: bold; color: #1d2327;">
                            <?php echo intval($ticket_stats->total_boletas_pagadas ?? 0); ?>
                        </div>
                        <p style="margin: 10px 0 0; font-size: 10px; color: #999;">🔗 Click para detalles</p>
                    </div>
                </a>

                <!-- Boletas Reservadas -->
                <a href="<?php echo admin_url('admin.php?page=dm-rifa-compradores&rifa_id=' . $selected_rifa_id . '&status=reservado'); ?>"
                    style="text-decoration: none; color: inherit;">
                    <div style="background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #e5e5e5; border-left: 4px solid #f59e0b; min-height: 120px; cursor: pointer; transition: transform 0.2s;"
                        onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
                        <h3 style="margin: 0 0 10px 0; font-size: 13px; color: #666; font-weight: 600;">⏳ Boletas Reservadas
                        </h3>
                        <div style="font-size: 28px; font-weight: bold; color: #1d2327;">
                            <?php echo intval($ticket_stats->total_boletas_reservadas ?? 0); ?>
                        </div>
                        <p style="margin: 10px 0 0; font-size: 10px; color: #999;">🔗 Click para detalles</p>
                    </div>
                </a>

                <!-- Boletas Disponibles -->
                <div
                    style="background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #e5e5e5; border-left: 4px solid <?php echo $boletas_disponibles > 0 ? '#10b981' : '#ef4444'; ?>; min-height: 120px;">
                    <h3 style="margin: 0 0 10px 0; font-size: 13px; color: #666; font-weight: 600;">🎯 Boletas Disponibles</h3>
                    <div style="font-size: 28px; font-weight: bold; color: #1d2327;">
                        <?php echo $boletas_disponibles; ?>
                    </div>
                    <p style="margin: 10px 0 0; font-size: 11px; color: #666;">
                        <?php echo $total_boletas > 0 ? round(($boletas_disponibles / $total_boletas) * 100, 1) : 0; ?>% del
                        total
                    </p>
                </div>

                <!-- Vendedores -->
                <a href="<?php echo admin_url('admin.php?page=dm-rifa-vendedores'); ?>"
                    style="text-decoration: none; color: inherit;">
                    <div style="background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #e5e5e5; border-left: 4px solid #06b6d4; min-height: 120px; cursor: pointer; transition: transform 0.2s;"
                        onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
                        <h3 style="margin: 0 0 10px 0; font-size: 13px; color: #666; font-weight: 600;">👥 Vendedores</h3>
                        <div style="font-size: 28px; font-weight: bold; color: #1d2327;">
                            <?php echo intval($total_vendedores); ?>
                        </div>
                        <p style="margin: 10px 0 0; font-size: 10px; color: #999;">🔗 Click para listado</p>
                    </div>
                </a>

                <!-- Efectivo -->
                <a href="<?php echo admin_url('admin.php?page=dm-rifa-compradores&rifa_id=' . $selected_rifa_id . '&forma_pago=efectivo'); ?>"
                    style="text-decoration: none; color: inherit;">
                    <div style="background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #e5e5e5; border-left: 4px solid #10b981; min-height: 120px; cursor: pointer; transition: transform 0.2s;"
                        onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
                        <h3 style="margin: 0 0 10px 0; font-size: 13px; color: #666; font-weight: 600;">💵 Efectivo</h3>
                        <div style="font-size: 28px; font-weight: bold; color: #1d2327;">
                            $<?php echo number_format($monto_efectivo, 0, ',', '.'); ?>
                        </div>
                        <p style="margin: 10px 0 0; font-size: 10px; color: #999;">🔗 Click para detalles</p>
                    </div>
                </a>

                <!-- Transferencias -->
                <a href="<?php echo admin_url('admin.php?page=dm-rifa-compradores&rifa_id=' . $selected_rifa_id . '&forma_pago=transferencia'); ?>"
                    style="text-decoration: none; color: inherit;">
                    <div style="background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #e5e5e5; border-left: 4px solid #8b5cf6; min-height: 120px; cursor: pointer; transition: transform 0.2s;"
                        onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
                        <h3 style="margin: 0 0 10px 0; font-size: 13px; color: #666; font-weight: 600;">💳 Transferencias</h3>
                        <div style="font-size: 28px; font-weight: bold; color: #1d2327;">
                            $<?php echo number_format($monto_transferencia, 0, ',', '.'); ?>
                        </div>
                        <p style="margin: 10px 0 0; font-size: 10px; color: #999;">🔗 Click para detalles</p>
                    </div>
                </a>

                <!-- En Caja: Arqueos -->
                <a href="<?php echo admin_url('admin.php?page=dm-rifa-vendedores'); ?>"
                    style="text-decoration: none; color: inherit;">
                    <div style="background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #e5e5e5; border-left: 4px solid #d97706; min-height: 120px; cursor: pointer; transition: transform 0.2s;"
                        onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
                        <h3 style="margin: 0 0 10px 0; font-size: 13px; color: #666; font-weight: 600;">🏦 En Caja (Arqueos)
                        </h3>
                        <div style="font-size: 28px; font-weight: bold; color: #1d2327;">
                            $<?php echo number_format($total_arqueos, 0, ',', '.'); ?>
                        </div>
                        <?php if ($saldo_pendiente_caja > 0): ?>
                            <p style="margin: 10px 0 0; font-size: 11px; color: #d97706; font-weight: 600;">
                                ⚠ Pendiente de entregar: $<?php echo number_format($saldo_pendiente_caja, 0, ',', '.'); ?>
                            </p>
                        <?php elseif ($saldo_pendiente_caja < 0): ?>
                            <p style="margin: 10px 0 0; font-size: 11px; color: #10b981; font-weight: 600;">
                                ✅ Caja cuadrada
                            </p>
                        <?php else: ?>
                            <p style="margin: 10px 0 0; font-size: 11px; color: #10b981; font-weight: 600;">
                                ✅ Sin saldo pendiente
                            </p>
                        <?php endif; ?>
                    </div>
                </a>
            </div>

            <!-- SECCIÓN 3: Fila de Tablas -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 20px;">
                <!-- Top Vendedores -->
                <div>
                    <h2 style="margin: 0 0 15px 0;">🏆 Top Vendedores</h2>
                    <div style="background: #fff; padding: 0; border: 1px solid #e5e5e5; border-radius: 8px; overflow: hidden;">
                        <table class="widefat fixed striped" style="border: none; box-shadow: none;">
                            <thead>
                                <tr>
                                    <th>Vendedor</th>
                                    <th style="text-align: right;">Boletas</th>
                                    <th style="text-align: right;">Recaudado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($top_vendedores):
                                    foreach ($top_vendedores as $index => $v): ?>
                                        <tr>
                                            <td>
                                                <?php if ($index === 0)
                                                    echo '🥇';
                                                elseif ($index === 1)
                                                    echo '🥈';
                                                elseif ($index === 2)
                                                    echo '🥉'; ?>
                                                <strong><?php echo esc_html($v->nombre); ?></strong>
                                            </td>
                                            <td style="text-align: right;"><?php echo intval($v->ventas); ?></td>
                                            <td style="text-align: right;">
                                                <strong>$<?php echo number_format($v->recaudado ?: 0, 0, ',', '.'); ?></strong>
                                            </td>
                                        </tr>
                                    <?php endforeach; else: ?>
                                    <tr>
                                        <td colspan="3" style="text-align: center; padding: 20px; color: #999;">Sin ventas
                                            registradas</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Vendedores Bajo Meta -->
                <div>
                    <h2 style="margin: 0 0 15px 0;">⚠️ Vendedores Bajo Meta (< 20)</h2>
                            <div
                                style="background: #fff; padding: 0; border: 1px solid #e5e5e5; border-radius: 8px; overflow: hidden;">
                                <?php if ($worst_vendedores && count($worst_vendedores) > 0): ?>
                                    <table class="widefat fixed striped" style="border: none; box-shadow: none;">
                                        <thead>
                                            <tr>
                                                <th>Vendedor</th>
                                                <th style="text-align: right;">Vendidas</th>
                                                <th style="text-align: right;">Faltante</th>
                                                <th style="text-align: center;">Acción</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($worst_vendedores as $v):
                                                $faltante = 20 - intval($v->ventas);
                                                $color = $faltante > 15 ? '#ef4444' : ($faltante > 10 ? '#f59e0b' : '#10b981');
                                                ?>
                                                <tr>
                                                    <td>
                                                        <strong><?php echo esc_html($v->nombre); ?></strong>
                                                        <?php if ($v->telefono): ?>
                                                            <br><small style="color: #666;">📱 <?php echo esc_html($v->telefono); ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td style="text-align: right; color: <?php echo $color; ?>; font-weight: 600;">
                                                        <?php echo intval($v->ventas); ?>
                                                    </td>
                                                    <td style="text-align: right;">
                                                        <span
                                                            style="background: <?php echo $color; ?>; color: white; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600;">
                                                            <?php echo $faltante; ?>
                                                        </span>
                                                    </td>
                                                    <td style="text-align: center;">
                                                        <a href="<?php echo admin_url('admin.php?page=dm-rifa-vendedores&action=report&id=' . $v->id . '&rifa_id=' . $selected_rifa_id); ?>"
                                                            class="button button-small"
                                                            style="font-size: 11px; padding: 2px 8px;">Ver</a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php else: ?>
                                    <div style="padding: 20px; text-align: center; color: #10b981;">
                                        <strong>🎉 ¡Todos los vendedores superaron la meta!</strong>
                                    </div>
                                <?php endif; ?>
                            </div>
                </div>

                <!-- Vendedores con Reservas Pendientes -->
                <div>
                    <h2 style="margin: 0 0 15px 0;">⏰ Reservas Pendientes</h2>
                    <div style="background: #fff; padding: 0; border: 1px solid #e5e5e5; border-radius: 8px; overflow: hidden;">
                        <?php if ($vendedores_con_reservas && count($vendedores_con_reservas) > 0): ?>
                            <table class="widefat fixed striped" style="border: none; box-shadow: none;">
                                <thead>
                                    <tr>
                                        <th>Vendedor</th>
                                        <th style="text-align: right;">Boletas</th>
                                        <th style="text-align: right;">Monto</th>
                                        <th style="text-align: center;">Acción</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($vendedores_con_reservas as $v):
                                        $boletas = intval($v->boletas_reservadas);
                                        $urgencia = $boletas > 10 ? '#ef4444' : ($boletas > 5 ? '#f59e0b' : '#3b82f6');
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo esc_html($v->nombre); ?></strong>
                                                <?php if ($v->telefono): ?>
                                                    <br><small style="color: #666;">📱 <?php echo esc_html($v->telefono); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td style="text-align: right;">
                                                <span
                                                    style="background: <?php echo $urgencia; ?>; color: white; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600;">
                                                    <?php echo $boletas; ?>
                                                </span>
                                            </td>
                                            <td style="text-align: right; color: #f59e0b; font-weight: 600;">
                                                $<?php echo number_format(floatval($v->monto_pendiente), 0, ',', '.'); ?>
                                            </td>
                                            <td style="text-align: center;">
                                                <a href="<?php echo admin_url('admin.php?page=dm-rifa-compradores&rifa_id=' . $selected_rifa_id . '&vendedor_id=' . $v->id . '&status=reservado'); ?>"
                                                    class="button button-small button-primary"
                                                    style="font-size: 11px; padding: 2px 8px;">Ver</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div style="padding: 20px; text-align: center; color: #64748b;">
                                <strong>✅ Sin reservas pendientes</strong>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /* ---------------------- Admin: Rifas ---------------------- */
    public function page_rifas()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        global $wpdb;

        // Verificar si las columnas necesarias existen, si no, ejecutar on_activate
        $column_bg = $wpdb->get_results("SHOW COLUMNS FROM {$this->tbl_rifas} LIKE 'background_id'");
        $column_cfg = $wpdb->get_results("SHOW COLUMNS FROM {$this->tbl_rifas} LIKE 'ticket_config'");
        if (empty($column_bg) || empty($column_cfg)) {
            $this->on_activate();
        }

        // Activar/Desactivar rifa
        if (isset($_GET['action']) && $_GET['action'] === 'toggle_active' && isset($_GET['id'])) {
            check_admin_referer('dm_toggle_rifa_' . $_GET['id']);
            $rifa_id = intval($_GET['id']);
            $current_status = $wpdb->get_var($wpdb->prepare("SELECT activo FROM {$this->tbl_rifas} WHERE id = %d", $rifa_id));
            $new_status = $current_status ? 0 : 1;
            $wpdb->update($this->tbl_rifas, array('activo' => $new_status), array('id' => $rifa_id));
            echo '<div class="notice notice-success is-dismissible"><p>Estado de la rifa actualizado.</p></div>';
        }

        // Eliminar Rifa (Cascada manual)
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
            check_admin_referer('dm_delete_rifa_' . $_GET['id']);
            $rid = intval($_GET['id']);

            // 1. Borrar números
            $wpdb->delete($this->tbl_numeros, array('rifa_id' => $rid));
            // 2. Borrar reservas
            $wpdb->delete($this->tbl_reservas, array('rifa_id' => $rid));
            // 3. Borrar rifa
            $wpdb->delete($this->tbl_rifas, array('id' => $rid));

            echo '<div class="notice notice-warning is-dismissible"><p>Rifa y todos sus datos asociados (números y ventas) eliminados permanentemente.</p></div>';
        }

        // Crear o Editar rifa (POST)
        if (isset($_POST['dm_guardar_rifa']) && check_admin_referer('dm_rifa_nonce')) {
            $rifa_id = intval($_POST['rifa_id'] ?? 0);
            $nombre = sanitize_text_field($_POST['nombre'] ?? '');
            $desc = wp_kses_post($_POST['descripcion'] ?? '');
            $fecha = sanitize_text_field($_POST['fecha'] ?? '');
            $loteria = sanitize_text_field($_POST['loteria'] ?? '');
            $total = intval($_POST['total_numeros'] ?? 0);
            $precio = intval($_POST['precio'] ?? 0);
            $wa = preg_replace('/[^0-9]/', '', $_POST['wa_e164'] ?? '');
            $css = isset($_POST['css_activo']) ? 1 : 0;
            $gracias = intval($_POST['gracias_page_id'] ?? 0);
            $boleta_id = intval($_POST['boleta_id'] ?? 0);

            $data = array(
                'nombre' => $nombre,
                'descripcion' => $desc,
                'fecha' => $fecha ? date('Y-m-d H:i:s', strtotime($fecha)) : NULL,
                'loteria' => $loteria,
                'precio' => $precio,
                'wa_e164' => $wa,
                'css_activo' => $css,
                'gracias_page_id' => $gracias,
                'boleta_id' => $boleta_id,
                'url_rifa' => esc_url_raw($_POST['url_rifa'] ?? ''),
                'meta_recaudo' => intval($_POST['meta_recaudo'] ?? 0),
                'modo_venta' => sanitize_text_field($_POST['modo_venta'] ?? 'mixto'),
            );

            if ($rifa_id > 0) {
                // Actualizar
                $wpdb->update($this->tbl_rifas, $data, array('id' => $rifa_id));
                echo '<div class="notice notice-success"><p>Rifa actualizada.</p></div>';
            } else {
                // Crear
                $data['total_numeros'] = $total;
                $wpdb->insert($this->tbl_rifas, $data);
                $rifa_id = intval($wpdb->insert_id);

                if ($rifa_id > 0 && $total > 0) {
                    $values = array();
                    for ($i = 0; $i < $total; $i++) {
                        $values[] = $wpdb->prepare("(%d,%s,'disponible',NULL,NOW())", $rifa_id, $this->pad3($i));
                    }
                    if (!empty($values)) {
                        $sql = "INSERT INTO {$this->tbl_numeros} (rifa_id, numero, estado, reserva_id, updated_at) VALUES " . implode(',', $values);
                        $wpdb->query($sql);
                    }
                    echo '<div class="notice notice-success"><p>Rifa creada. Shortcode: <code>[rifa_selector id="' . esc_attr($rifa_id) . '"]</code></p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>No se pudo crear la rifa.</p></div>';
                }
            }
        }

        // Obtener datos si estamos editando
        $edit_id = isset($_GET['action']) && $_GET['action'] === 'edit' ? intval($_GET['id'] ?? 0) : 0;
        $edit_rifa = $edit_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tbl_rifas} WHERE id = %d", $edit_id)) : null;
        $cfg = array();
        if ($edit_rifa && !empty($edit_rifa->ticket_config)) {
            $cfg = json_decode($edit_rifa->ticket_config, true);
        }

        $rifas = $wpdb->get_results("SELECT * FROM {$this->tbl_rifas} ORDER BY id DESC");
        ?>
        <div class="wrap">
            <h1>DM Rifas</h1>
            <h2><?php echo $edit_rifa ? 'Editar rifa: ' . esc_html($edit_rifa->nombre) : 'Crear nueva rifa'; ?></h2>
            <form method="post">
                <?php wp_nonce_field('dm_rifa_nonce'); ?>
                <input type="hidden" name="rifa_id" value="<?php echo intval($edit_id); ?>">
                <table class="form-table">
                    <tr>
                        <th>Nombre</th>
                        <td><input type="text" name="nombre" class="regular-text"
                                value="<?php echo $edit_rifa ? esc_attr($edit_rifa->nombre) : ''; ?>" required></td>
                    </tr>
                    <tr>
                        <th>Descripción</th>
                        <td><textarea name="descripcion" class="large-text"
                                rows="3"><?php echo $edit_rifa ? esc_textarea($edit_rifa->descripcion) : ''; ?></textarea></td>
                    </tr>
                    <tr>
                        <th>Fecha (opcional)</th>
                        <td><input type="datetime-local" name="fecha"
                                value="<?php echo $edit_rifa && $edit_rifa->fecha ? date('Y-m-d\TH:i', strtotime($edit_rifa->fecha)) : ''; ?>">
                        </td>
                    </tr>
                    <tr>
                        <th>Lotería</th>
                        <td><input type="text" name="loteria" class="regular-text"
                                value="<?php echo $edit_rifa ? esc_attr($edit_rifa->loteria) : ''; ?>"></td>
                    </tr>
                    <tr <?php if ($edit_rifa)
                        echo 'style="display:none;"'; ?>>
                        <th>Total de números</th>
                        <td><input type="number" name="total_numeros"
                                value="<?php echo $edit_rifa ? intval($edit_rifa->total_numeros) : '470'; ?>" min="1" <?php if (!$edit_rifa)
                                           echo 'required'; ?>>
                            <?php if (!$edit_rifa): ?><em>(ej: 470 crea 000–469)</em><?php else: ?><br><small>El total de
                                    números
                                    no se puede editar una vez creada la rifa.</small><?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Precio por boleta</th>
                        <td><input type="number" name="precio"
                                value="<?php echo $edit_rifa ? intval($edit_rifa->precio) : '60000'; ?>" min="0" required></td>
                    </tr>
                    <tr>
                        <th>WhatsApp Administrador</th>
                        <td><input type="text" name="wa_e164" class="regular-text"
                                value="<?php echo $edit_rifa ? esc_attr($edit_rifa->wa_e164) : ''; ?>">
                            <p class="description">Formato internacional (ej: 573123456789). Se usará si no hay vendedor
                                asignado.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Meta de Recaudo ($)</th>
                        <td><input type="number" name="meta_recaudo" class="regular-text"
                                value="<?php echo $edit_rifa ? intval($edit_rifa->meta_recaudo) : '0'; ?>" min="0">
                            <p class="description">Meta total de ventas en dinero para esta rifa.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>URL de la Rifa</th>
                        <td><input type="url" name="url_rifa" class="large-text"
                                value="<?php echo $edit_rifa ? esc_url($edit_rifa->url_rifa) : ''; ?>"
                                placeholder="<?php echo home_url('/rifa-2025'); ?>">
                            <p class="description">Link completo de la página donde pusiste el shortcode de la rifa. Se
                                usará en las instrucciones enviadas a los vendedores.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Activar CSS del plugin</th>
                        <td><label><input type="checkbox" name="css_activo" value="1" <?php if ($edit_rifa)
                            checked($edit_rifa->css_activo, 1); ?>> Sí</label></td>
                    </tr>
                    <tr>
                        <th>Página de Gracias</th>
                        <td>
                            <?php
                            wp_dropdown_pages(array(
                                'name' => 'gracias_page_id',
                                'selected' => $edit_rifa ? $edit_rifa->gracias_page_id : 0,
                                'show_option_none' => '(sin redirección)',
                                'option_none_value' => 0
                            ));
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Modelo de Venta</th>
                        <td>
                            <select name="modo_venta">
                                <option value="mixto" <?php selected($edit_rifa ? $edit_rifa->modo_venta : 'mixto', 'mixto'); ?>>Mixto (Físico y Virtual)</option>
                                <option value="virtual" <?php selected($edit_rifa ? $edit_rifa->modo_venta : 'mixto', 'virtual'); ?>>Sólo Virtual</option>
                            </select>
                            <p class="description">Si eliges "Sólo Virtual", se ocultarán las opciones de venta física y
                                reportes relacionados.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Diseño de Boleta</th>
                        <td>
                            <select name="boleta_id">
                                <option value="0">-- Seleccionar Diseño --</option>
                                <?php
                                $boletas = $wpdb->get_results("SELECT id, nombre FROM {$this->tbl_boletas} ORDER BY nombre ASC");
                                foreach ($boletas as $b) {
                                    echo '<option value="' . $b->id . '" ' . selected($edit_rifa ? $edit_rifa->boleta_id : 0, $b->id, false) . '>' . esc_html($b->nombre) . '</option>';
                                }
                                ?>
                            </select>
                            <p class="description">Selecciona un diseño creado en el <a href="?page=dm-rifa-boletas"
                                    target="_blank">Diseñador de Boletas</a>.</p>
                        </td>
                    </tr>
                </table>
                <p>
                    <button type="submit" class="button button-primary" name="dm_guardar_rifa"
                        value="1"><?php echo $edit_rifa ? 'Actualizar rifa' : 'Crear rifa'; ?></button>
                    <?php if ($edit_rifa): ?>
                        <a href="<?php echo admin_url('admin.php?page=dm-rifa'); ?>" class="button">Cancelar</a>
                    <?php endif; ?>
                </p>
            </form>

            <h2>Rifas existentes</h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nombre</th>
                        <th>Estado</th>
                        <th>Estado Números</th>
                        <th>Progreso Ventas</th>
                        <th>Recaudo Est.</th>
                        <th>WhatsApp</th>
                        <th>Shortcode</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rifas):
                        foreach ($rifas as $r):
                            $stats = $wpdb->get_row($wpdb->prepare(
                                "SELECT 
                                            COUNT(*) as total,
                                            SUM(CASE WHEN estado = 'pagado' THEN 1 ELSE 0 END) as pagados,
                                            SUM(CASE WHEN estado = 'reservado' THEN 1 ELSE 0 END) as reservados,
                                            SUM(CASE WHEN estado = 'disponible' THEN 1 ELSE 0 END) as disponibles
                                         FROM {$this->tbl_numeros} WHERE rifa_id = %d",
                                $r->id
                            ));

                            $percent = ($stats->total > 0) ? round(($stats->pagados / $stats->total) * 100) : 0;
                            $recaudo = $stats->pagados * $r->precio;
                            ?>
                            <tr>
                                <td><?php echo esc_html($r->id); ?></td>
                                <td><strong><?php echo esc_html($r->nombre); ?></strong></td>
                                <td>
                                    <?php if ($r->activo): ?>
                                        <span class="badge"
                                            style="background:#00a32a; color:#fff; padding:2px 8px; border-radius:4px;">Activa</span>
                                    <?php else: ?>
                                        <span class="badge"
                                            style="background:#d63638; color:#fff; padding:2px 8px; border-radius:4px;">Inactiva</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="dashicons dashicons-yes-alt" style="color:green" title="Pagados"></span>
                                    <?php echo intval($stats->pagados); ?> |
                                    <span class="dashicons dashicons-clock" style="color:orange" title="Reservados"></span>
                                    <?php echo intval($stats->reservados); ?> |
                                    <span class="dashicons dashicons-marker" style="color:gray" title="Disponibles"></span>
                                    <?php echo intval($stats->disponibles); ?>
                                </td>
                                <td>
                                    <div
                                        style="width:100px; background:#eee; border-radius:10px; height:12px; margin-top:5px; position:relative;">
                                        <div
                                            style="width:<?php echo $percent; ?>%; background:#4caf50; border-radius:10px; height:100%;">
                                        </div>
                                    </div>
                                    <small><?php echo $percent; ?>%
                                        (<?php echo intval($stats->pagados); ?>/<?php echo intval($stats->total); ?>)</small>
                                </td>
                                <td><strong>$<?php echo number_format($recaudo, 0, ',', '.'); ?></strong></td>
                                <td><?php echo esc_html($r->wa_e164); ?></td>
                                <td><code>[rifa_selector id="<?php echo esc_attr($r->id); ?>"]</code></td>
                                <td>
                                    <a class="button"
                                        href="<?php echo admin_url('admin.php?page=dm-rifa-compradores&rifa_id=' . intval($r->id)); ?>">Ver
                                        compradores</a>
                                    <a class="button"
                                        href="<?php echo admin_url('admin.php?page=dm-rifa&action=edit&id=' . intval($r->id)); ?>">Editar</a>
                                    <a class="button <?php echo $r->activo ? 'button-secondary' : 'button-primary'; ?>"
                                        href="<?php echo wp_nonce_url(admin_url('admin.php?page=dm-rifa&action=toggle_active&id=' . intval($r->id)), 'dm_toggle_rifa_' . $r->id); ?>">
                                        <?php echo $r->activo ? 'Desactivar' : 'Activar'; ?>
                                    </a>
                                    <a class="button" style="color: #d63638; border-color: #d63638;"
                                        href="<?php echo wp_nonce_url(admin_url('admin.php?page=dm-rifa&action=delete&id=' . intval($r->id)), 'dm_delete_rifa_' . $r->id); ?>"
                                        onclick="return confirm('¿ESTÁS SEGURO? Se borrará la rifa, TODOS los números y TODAS las ventas registradas. Esta acción no se puede deshacer.');">
                                        Borrar
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                        <tr>
                            <td colspan="9">No hay rifas.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Visual Ticket Editor Modal -->
        <div id="dm-ticket-editor-modal">
            <div class="dm-editor-content">
                <div class="dm-editor-header">
                    <h2>Editor Visual de Boleta</h2>
                    <button type="button" class="button dm-close-modal">&times;</button>
                </div>
                <div class="dm-editor-body">
                    <div id="dm-canvas-container">
                        <!-- Background image will be set here -->
                        <div class="dm-draggable-text" data-field="vendedor">VENDEDOR</div>
                        <div class="dm-draggable-text" data-field="comprador">COMPRADOR</div>
                        <div class="dm-draggable-text" data-field="numeros">NÚMEROS</div>
                        <div class="dm-draggable-text" data-field="estado">ESTADO</div>
                    </div>
                </div>
                <div class="dm-editor-footer">
                    <p style="margin-right: auto; opacity: 0.7;">Arrastra los elementos para posicionarlos. Los cambios se
                        reflejarán en los campos numéricos al cerrar.</p>
                    <button type="button" class="button button-primary dm-close-modal">Listo</button>
                </div>
            </div>
        </div>
        <?php
    }

    /* ---------------------- Admin: Vendedores ---------------------- */
    public function page_vendedores()
    {
        ini_set('display_errors', 1);
        ini_set('display_startup_errors', 1);
        error_reporting(E_ALL);
        error_log("DM RIFA DEBUG: Executing page_vendedores");
        global $wpdb;

        $table_name = $this->tbl_vendedores;

        $action = $_GET['action'] ?? '';
        $seller_id = intval($_GET['id'] ?? 0);
        $orderby = $_GET['orderby'] ?? 'id';
        $order = strtoupper($_GET['order'] ?? 'ASC');

        error_log("DM RIFA DEBUG: Action: $action, Seller ID: $seller_id");
        $next_order = ($order === 'ASC') ? 'DESC' : 'ASC';

        // Asegurar que las tablas existen (especialmente si no se reactivó el plugin)
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->tbl_vendedores}'");
        if (!$table_exists) {
            error_log("DM RIFA DEBUG: La tabla de vendedores NO existe. Ejecutando db_init...");
            $this->on_activate();
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->tbl_vendedores}'");
        }

        // Procesar creación
        if (isset($_POST['dm_crear_vendedor'])) {
            error_log("DM RIFA DEBUG: Registrando vendedor...");
            error_log("POST DATA: " . print_r($_POST, true));
            check_admin_referer('dm_vendedor_nonce');
            $res = $wpdb->insert($this->tbl_vendedores, array(
                'nombre' => sanitize_text_field($_POST['nombre']),
                'email' => sanitize_email($_POST['email']),
                'telefono' => sanitize_text_field($_POST['telefono'])
            ));
            if ($res === false) {
                error_log("DM RIFA DEBUG: ERROR al insertar vendedor: " . $wpdb->last_error);
                echo '<div class="error"><p>Error al crear el vendedor: ' . esc_html($wpdb->last_error) . '</p></div>';
            } else {
                error_log("DM RIFA DEBUG: Vendedor insertado con ID: " . $wpdb->insert_id);
                echo '<div class="updated"><p>Vendedor creado.</p></div>';
            }
        }

        // Procesar edición
        if (isset($_POST['dm_editar_vendedor'])) {
            check_admin_referer('dm_edit_vendedor_nonce');
            $seller_id = intval($_POST['vendedor_id']);
            $wpdb->update($this->tbl_vendedores, array(
                'nombre' => sanitize_text_field($_POST['nombre']),
                'email' => sanitize_email($_POST['email']),
                'telefono' => sanitize_text_field($_POST['telefono'])
            ), array('id' => $seller_id));
            echo '<div class="updated"><p>Vendedor actualizado.</p></div>';
        }

        // Procesar eliminación
        if ($action === 'delete' && $seller_id > 0) {
            check_admin_referer('dm_del_vendedor_' . $_GET['id']);
            $wpdb->delete($this->tbl_vendedores, array('id' => intval($_GET['id'])));
            echo '<div class="updated"><p>Vendedor eliminado.</p></div>';
        }

        // Procesar carga por lote (CSV)
        if (isset($_POST['dm_bulk_vendedores'])) {
            check_admin_referer('dm_bulk_vendedor_nonce');
            if (!empty($_FILES['bulk_file']['tmp_name'])) {
                $handle = fopen($_FILES['bulk_file']['tmp_name'], "r");
                $headers = fgetcsv($handle, 1000, ","); // Saltar cabecera
                $count = 0;
                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    if (count($data) >= 2) {
                        $nombre = sanitize_text_field($data[0]);
                        $email = count($data) > 2 ? sanitize_email($data[1]) : '';
                        $telefono = count($data) > 2 ? sanitize_text_field($data[2]) : sanitize_text_field($data[1]);

                        $wpdb->insert($this->tbl_vendedores, array(
                            'nombre' => $nombre,
                            'email' => $email,
                            'telefono' => $telefono
                        ));
                        $count++;
                    }
                }
                fclose($handle);
                echo '<div class="updated"><p>' . intval($count) . ' vendedores importados con éxito.</p></div>';
            }
        }

        // Procesar Arqueo (Entrega de dinero)
        if (isset($_POST['dm_registrar_arqueo'])) {
            check_admin_referer('dm_arqueo_nonce');
            $v_id = intval($_POST['vendedor_id']);
            $monto = intval($_POST['monto']);
            $obs = sanitize_textarea_field($_POST['observaciones'] ?? '');

            // Determinar rifa_id: usar la que viene del POST o la primera activa como fallback
            $r_id = intval($_POST['rifa_id'] ?? 0);
            if ($r_id <= 0) {
                $r_id = intval($wpdb->get_var(
                    "SELECT id FROM {$this->tbl_rifas} WHERE activo = 1 ORDER BY id DESC LIMIT 1"
                ));
            }

            $insert_data = array(
                'vendedor_id' => $v_id,
                'monto' => $monto,
                'observaciones' => $obs,
                'rifa_id' => ($r_id > 0 ? $r_id : null),
            );

            $result = $wpdb->insert($this->tbl_arqueos, $insert_data);

            if ($result === false) {
                error_log('DM Rifa arqueo INSERT error: ' . $wpdb->last_error);
                echo '<div class="error"><p>Error al registrar el arqueo: ' . esc_html($wpdb->last_error) . '</p></div>';
            } else {
                // Actualizar registros históricos sin rifa asignada al vendedor
                if ($r_id > 0) {
                    $wpdb->query($wpdb->prepare(
                        "UPDATE {$this->tbl_arqueos} SET rifa_id = %d WHERE vendedor_id = %d AND rifa_id IS NULL AND id != %d",
                        $r_id,
                        $v_id,
                        $wpdb->insert_id
                    ));
                }
                // PRG: redirigir para refrescar historial
                $redirect = admin_url(
                    'admin.php?page=dm-rifa-vendedores&action=report&id=' . $v_id
                    . ($r_id > 0 ? '&rifa_id=' . $r_id : '')
                    . '&arqueo=ok'
                );
                wp_redirect($redirect);
                exit;
            }
        }

        // Procesar edición de rifa_id de un arqueo existente
        if (isset($_POST['dm_editar_arqueo_rifa'])) {
            check_admin_referer('dm_arqueo_nonce');
            $arq_id = intval($_POST['arqueo_id']);
            $new_rid = intval($_POST['nueva_rifa_id']);
            $v_id = intval($_POST['vendedor_id']);
            $r_id = intval($_POST['rifa_id'] ?? 0);
            if ($arq_id > 0 && $new_rid > 0) {
                $wpdb->update(
                    $this->tbl_arqueos,
                    ['rifa_id' => $new_rid],
                    ['id' => $arq_id, 'vendedor_id' => $v_id]
                );
            }
            $redirect = admin_url(
                'admin.php?page=dm-rifa-vendedores&action=report&id=' . $v_id
                . ($r_id > 0 ? '&rifa_id=' . $r_id : '')
                . '&arqueo=editado'
            );
            wp_redirect($redirect);
            exit;
        }

        // Procesar Devolución de arqueo (monto negativo)
        if (isset($_POST['dm_registrar_devolucion'])) {
            check_admin_referer('dm_arqueo_nonce');
            $v_id = intval($_POST['vendedor_id']);
            $monto_dev = abs(intval($_POST['monto_devolucion']));
            $obs = sanitize_textarea_field($_POST['observaciones'] ?? '');

            if ($monto_dev <= 0) {
                echo '<div class="error"><p>El monto de la devolución debe ser mayor a 0.</p></div>';
            } elseif (empty(trim($obs))) {
                echo '<div class="error"><p>El motivo de la devolución es obligatorio.</p></div>';
            } else {
                // Determinar rifa_id activa como en el arqueo normal
                $r_id = intval($_POST['rifa_id'] ?? 0);
                if ($r_id <= 0) {
                    $r_id = intval($wpdb->get_var(
                        "SELECT id FROM {$this->tbl_rifas} WHERE activo = 1 ORDER BY id DESC LIMIT 1"
                    ));
                }

                // Guardar como monto NEGATIVO — esto es la devolución
                $obs_final = '↩ DEVOLUCIÓN: ' . $obs;
                $result = $wpdb->insert($this->tbl_arqueos, [
                    'vendedor_id' => $v_id,
                    'monto' => -$monto_dev,   // negativo
                    'observaciones' => $obs_final,
                    'rifa_id' => ($r_id > 0 ? $r_id : null),
                ]);

                if ($result === false) {
                    error_log('DM Rifa devolucion INSERT error: ' . $wpdb->last_error);
                    echo '<div class="error"><p>Error al registrar la devolución: ' . esc_html($wpdb->last_error) . '</p></div>';
                } else {
                    $redirect = admin_url(
                        'admin.php?page=dm-rifa-vendedores&action=report&id=' . $v_id
                        . ($r_id > 0 ? '&rifa_id=' . $r_id : '')
                        . '&arqueo=devolucion'
                    );
                    wp_redirect($redirect);
                    exit;
                }
            }
        }

        // Mostrar confirmación de arqueo registrado (tras el redirect PRG)
        if (isset($_GET['arqueo'])) {
            if ($_GET['arqueo'] === 'ok') {
                echo '<div class="updated"><p>✅ Arqueo (entrega de dinero) registrado exitosamente.</p></div>';
            } elseif ($_GET['arqueo'] === 'editado') {
                echo '<div class="updated"><p>✅ Rifa del arqueo actualizada correctamente.</p></div>';
            } elseif ($_GET['arqueo'] === 'devolucion') {
                echo '<div class="updated" style="border-left-color:#2e7d32;"><p>↩ Devolución registrada correctamente. El saldo del vendedor ha sido ajustado.</p></div>';
            }
        }


        // Procesar auto-asignación aleatoria
        if (isset($_POST['dm_auto_assign_10'])) {
            check_admin_referer('dm_auto_assign_nonce');
            $rifa_id = intval($_POST['rifa_id']);
            if (!$rifa_id) {
                echo '<div class="error"><p>Selecciona una rifa válida.</p></div>';
            } else {
                $vendedores = $wpdb->get_results("SELECT id FROM {$this->tbl_vendedores}");
                $total_assigned = 0;
                foreach ($vendedores as $v) {
                    // Ver si ya tiene números asignados (para completar hasta 10)
                    $current_count = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$this->tbl_numeros} WHERE vendedor_id = %d AND rifa_id = %d AND estado = 'asignado'",
                        $v->id,
                        $rifa_id
                    ));

                    if ($current_count < 10) {
                        $needed = 10 - $current_count;
                        $available = $wpdb->get_col($wpdb->prepare(
                            "SELECT numero FROM {$this->tbl_numeros} WHERE rifa_id = %d AND estado = 'disponible' ORDER BY RAND() LIMIT %d",
                            $rifa_id,
                            $needed
                        ));

                        foreach ($available as $num) {
                            $res = $wpdb->update(
                                $this->tbl_numeros,
                                array('estado' => 'asignado', 'vendedor_id' => $v->id),
                                array('rifa_id' => $rifa_id, 'numero' => $num, 'estado' => 'disponible')
                            );
                            if ($res)
                                $total_assigned++;
                        }
                    }
                }
                echo '<div class="updated"><p>Se han asignado ' . intval($total_assigned) . ' números aleatoriamente entre los vendedores.</p></div>';
            }
        }

        // Procesar asignación física de números
        if (isset($_POST['dm_asignar_numeros'])) {
            check_admin_referer('dm_assign_nonce');
            $seller_id = intval($_POST['vendedor_id'] ?? 0);
            $rifa_id = intval($_POST['rifa_id'] ?? 0);

            // Fuente 1: campo oculto "numeros" (CSV armado por updateHiddenNumeros()).
            $numeros_post = sanitize_text_field(wp_unslash($_POST['numeros'] ?? ''));
            $desde_hidden = array_map('trim', explode(',', $numeros_post));

            // Fuente 2 (respaldo): checkboxes numeros_check[] enviados por el navegador.
            $desde_checks = isset($_POST['numeros_check']) && is_array($_POST['numeros_check'])
                ? array_map('sanitize_text_field', wp_unslash($_POST['numeros_check']))
                : array();

            // Unión de ambas fuentes; solo se aceptan números (ej. "007").
            $numeros_seleccionados = array();
            foreach (array_merge($desde_hidden, $desde_checks) as $num) {
                $num = trim((string) $num);
                if ($num !== '' && ctype_digit($num)) {
                    $numeros_seleccionados[$num] = $num;
                }
            }
            $numeros_seleccionados = array_values($numeros_seleccionados);

            if ($seller_id <= 0 || $rifa_id <= 0) {
                echo '<div class="notice notice-error"><p>Vendedor o rifa no válidos. No se realizó ningún cambio.</p></div>';
            } elseif (empty($numeros_seleccionados)) {
                // Protección: si no llega ninguna selección no se libera nada (antes se liberaban todos los números del vendedor).
                echo '<div class="notice notice-warning"><p><strong>No se recibió ningún número seleccionado.</strong> Por seguridad no se liberó ni se asignó ningún número. Si quieres quitarle números al vendedor, deja marcados los que conserva y vuelve a guardar.</p></div>';
            } else {
                // 1. Liberar solo los números asignados a este vendedor que ya NO están seleccionados.
                $placeholders = implode(',', array_fill(0, count($numeros_seleccionados), '%s'));
                $liberados = $wpdb->query($wpdb->prepare(
                    "UPDATE {$this->tbl_numeros} SET estado = 'disponible', vendedor_id = NULL, updated_at = NOW()
                     WHERE vendedor_id = %d AND rifa_id = %d AND estado = 'asignado' AND numero NOT IN ($placeholders)",
                    array_merge(array($seller_id, $rifa_id), $numeros_seleccionados)
                ));

                // 2. Asignar los seleccionados que estén disponibles (los que ya eran suyos se conservan).
                $nuevos = 0;
                $no_disponibles = array();
                foreach ($numeros_seleccionados as $num) {
                    $actual = $wpdb->get_row($wpdb->prepare(
                        "SELECT estado, vendedor_id FROM {$this->tbl_numeros} WHERE rifa_id = %d AND numero = %s",
                        $rifa_id,
                        $num
                    ));
                    if (!$actual) {
                        $no_disponibles[] = $num;
                        continue;
                    }
                    if ($actual->estado === 'asignado' && intval($actual->vendedor_id) === $seller_id) {
                        continue; // ya era suyo
                    }
                    $res = $wpdb->update(
                        $this->tbl_numeros,
                        array('estado' => 'asignado', 'vendedor_id' => $seller_id, 'updated_at' => current_time('mysql')),
                        array('rifa_id' => $rifa_id, 'numero' => $num, 'estado' => 'disponible')
                    );
                    if ($res) {
                        $nuevos++;
                    } else {
                        $no_disponibles[] = $num;
                    }
                }

                $total_vendedor = intval($wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->tbl_numeros} WHERE vendedor_id = %d AND rifa_id = %d AND estado = 'asignado'",
                    $seller_id,
                    $rifa_id
                )));

                echo '<div class="notice notice-success"><p>Asignación actualizada: ' . intval($nuevos) . ' número(s) nuevos, '
                    . intval($liberados) . ' liberado(s). El vendedor tiene ahora ' . intval($total_vendedor) . ' número(s) asignados en esta rifa.</p></div>';
                if (!empty($no_disponibles)) {
                    echo '<div class="notice notice-warning"><p>No se pudieron asignar porque ya no estaban disponibles: '
                        . esc_html(implode(', ', $no_disponibles)) . '</p></div>';
                }
            }
        }

        // Procesar reporte de venta física
        if (isset($_POST['dm_reportar_venta_fisica'])) {
            check_admin_referer('dm_report_sale_nonce');
            $seller_id = intval($_POST['vendedor_id']);
            $rifa_id = intval($_POST['rifa_id']);
            $numeros_seleccionados = $_POST['numeros_venda'] ?? array();
            $nombre_comprador = sanitize_text_field($_POST['nombre_comprador'] ?: 'Venta Física');
            $telefono_comprador = sanitize_text_field($_POST['telefono_comprador'] ?: '');

            if (empty($numeros_seleccionados)) {
                echo '<div class="error"><p>No seleccionaste ningún número.</p></div>';
            } else {
                $rifa = $this->fetch_rifa($rifa_id);
                // 1. Crear Reserva como Pagada
                $wpdb->insert($this->tbl_reservas, array(
                    'rifa_id' => $rifa_id,
                    'nombre' => $nombre_comprador,
                    'email' => '',
                    'telefono' => $telefono_comprador,
                    'numeros_csv' => implode(', ', $numeros_seleccionados),
                    'vendedor_id' => $seller_id,
                    'status' => 'pagado',
                    'precio_unit' => $rifa->precio,
                    'total' => $rifa->precio * count($numeros_seleccionados),
                    'token' => wp_generate_password(12, false),
                    'created_at' => current_time('mysql'),
                    'forma_pago' => 'efectivo'
                ));
                $reserva_id = $wpdb->insert_id;

                // 2. Actualizar Números
                $count = 0;
                foreach ($numeros_seleccionados as $num) {
                    $res = $wpdb->update(
                        $this->tbl_numeros,
                        array('estado' => 'pagado', 'reserva_id' => $reserva_id, 'vendedor_id' => $seller_id),
                        array('rifa_id' => $rifa_id, 'numero' => $num, 'vendedor_id' => $seller_id, 'estado' => 'asignado')
                    );
                    if ($res)
                        $count++;
                }
                echo '<div class="updated"><p>' . intval($count) . ' números marcados como PAGO (Venta Física).</p></div>';
                echo '<script>window.location.href="?page=dm-rifa-vendedores&action=report&id=' . $seller_id . '&updated=1";</script>';
                return;
            }
        }

        // Nueva Vista: Asignar Números (Física)
        if ($action === 'assign' && $seller_id > 0) {
            error_log("DM RIFA DEBUG: Entering assign view");
            $seller_id = intval($_GET['id']);
            $vendedor = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tbl_vendedores} WHERE id = %d", $seller_id));
            if (!$vendedor) {
                echo '<div class="wrap"><h1>Vendedor no encontrado</h1><p><a href="?page=dm-rifa-vendedores">Volver</a></p></div>';
                return;
            }

            $rifas = $wpdb->get_results("SELECT id, nombre FROM {$this->tbl_rifas} ORDER BY id DESC");
            $rifa_id = intval($_GET['rifa_id'] ?? ($rifas[0]->id ?? 0));

            // Obtener todos los números de esta rifa
            $todos_nums = $wpdb->get_results($wpdb->prepare(
                "SELECT numero, estado, vendedor_id FROM {$this->tbl_numeros} WHERE rifa_id = %d ORDER BY CAST(numero AS UNSIGNED) ASC",
                $rifa_id
            ));
            ?>
            <div class="wrap">
                <h1>Asignar Números a: <?php echo esc_html($vendedor->nombre); ?></h1>
                <p><a href="?page=dm-rifa-vendedores" class="button">Volver al listado</a></p>

                <div class="card" style="max-width: 100%; margin-top: 20px;">
                    <form method="get" action="">
                        <input type="hidden" name="page" value="dm-rifa-vendedores">
                        <input type="hidden" name="action" value="assign">
                        <input type="hidden" name="id" value="<?php echo $seller_id; ?>">
                        <p>
                            <label><strong>Rifa:</strong></label>
                            <select name="rifa_id" onchange="this.form.submit()">
                                <?php foreach ($rifas as $r): ?>
                                    <option value="<?php echo $r->id; ?>" <?php selected($rifa_id, $r->id); ?>>
                                        <?php echo esc_html($r->nombre); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </p>
                    </form>

                    <form method="post" action="" id="dm-assign-form">
                        <?php wp_nonce_field('dm_assign_nonce'); ?>
                        <input type="hidden" name="vendedor_id" value="<?php echo $seller_id; ?>">
                        <input type="hidden" name="rifa_id" value="<?php echo $rifa_id; ?>">

                        <h3>Selecciona los números para entrega física:</h3>
                        <p class="description">Los números seleccionados se marcarán como "Asignado" y quedarán bloqueados en la
                            web. Solo puedes asignar números que estén "Disponibles".</p>

                        <div
                            style="display: grid; grid-template-columns: repeat(auto-fill, minmax(70px, 1fr)); gap: 8px; max-height: 500px; overflow-y: auto; border: 1px solid #ccc; padding: 15px; background: #f9f9f9; border-radius: 5px;">
                            <?php foreach ($todos_nums as $n):
                                $is_mine = ($n->vendedor_id == $seller_id && $n->estado === 'asignado');
                                $is_blocked = ($n->estado !== 'disponible' && !$is_mine);
                                $status_label = '';
                                $bg = '#fff';
                                if ($is_mine) {
                                    $bg = '#d9f7d9'; // Verde claro para lo ya asignado a él
                                } elseif ($is_blocked) {
                                    $bg = '#eee';
                                    $status_label = ($n->estado === 'reservado') ? ' (Res)' : (($n->estado === 'pagado') ? ' (Pag)' : ' (Otr)');
                                }
                                ?>
                                <label
                                    style="display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 10px; border: 1px solid #ddd; border-radius: 4px; cursor: <?php echo $is_blocked ? 'not-allowed' : 'pointer'; ?>; background: <?php echo $bg; ?>; transition: all 0.2s;">
                                    <input type="checkbox" name="numeros_check[]" value="<?php echo esc_attr($n->numero); ?>" <?php checked($is_mine); ?> <?php disabled($is_blocked); ?> class="assign-checkbox"
                                        style="margin-bottom: 5px;">
                                    <span style="font-weight: bold; font-size: 14px;"><?php echo esc_html($n->numero); ?></span>
                                    <?php if ($status_label): ?>
                                        <small style="font-size: 10px; color: #666;"><?php echo $status_label; ?></small>
                                    <?php endif; ?>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <input type="hidden" name="numeros" id="numeros_hidden">

                        <p style="margin-top: 20px;">
                            <button type="submit" name="dm_asignar_numeros" class="button button-primary button-large"
                                onclick="updateHiddenNumeros()">Actualizar Asignación Física</button>
                        </p>
                    </form>
                </div>
            </div>
            <script>
                function updateHiddenNumeros() {
                    const checkboxes = document.querySelectorAll('.assign-checkbox:checked');
                    const values = Array.from(checkboxes).map(cb => cb.value);
                    document.getElementById('numeros_hidden').value = values.join(',');
                }
                // Rellenar el campo oculto en cualquier envío (clic o Enter)
                (function () {
                    const form = document.getElementById('dm-assign-form');
                    if (form) {
                        form.addEventListener('submit', updateHiddenNumeros);
                    }
                })();
                // Visual feedback enhancement
                document.querySelectorAll('.assign-checkbox').forEach(cb => {
                    cb.addEventListener('change', function () {
                        if (this.checked) {
                            this.parentElement.style.background = '#d9f7d9';
                            this.parentElement.style.borderColor = '#22c55e';
                        } else {
                            this.parentElement.style.background = '#fff';
                            this.parentElement.style.borderColor = '#ddd';
                        }
                    });
                });
            </script>
            <?php
            return; // Terminar aquí para no mostrar el listado general
        }

        // Nueva Vista: Editar Vendedor
        if ($action === 'edit' && $seller_id > 0) {
            $seller_id = intval($_GET['id']);
            $vendedor = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tbl_vendedores} WHERE id = %d", $seller_id));
            if (!$vendedor) {
                echo "Vendedor no encontrado.";
                return;
            }
            ?>
            <div class="wrap">
                <h1>Editar Vendedor: <?php echo esc_html($vendedor->nombre); ?></h1>
                <p><a href="?page=dm-rifa-vendedores" class="button">Volver al listado</a></p>

                <div class="card" style="max-width: 500px; margin-top: 20px;">
                    <form method="post">
                        <?php wp_nonce_field('dm_edit_vendedor_nonce'); ?>
                        <input type="hidden" name="vendedor_id" value="<?php echo $seller_id; ?>">
                        <table class="form-table">
                            <tr>
                                <th>Nombre</th>
                                <td><input type="text" name="nombre" value="<?php echo esc_attr($vendedor->nombre); ?>" required
                                        class="regular-text"></td>
                            </tr>
                            <tr>
                                <th>Email</th>
                                <td><input type="email" name="email" value="<?php echo esc_attr($vendedor->email); ?>"
                                        class="regular-text"></td>
                            </tr>
                            <tr>
                                <th>Teléfono/WhatsApp</th>
                                <td><input type="text" name="telefono" value="<?php echo esc_attr($vendedor->telefono); ?>" required
                                        class="regular-text"></td>
                            </tr>
                        </table>
                        <p>
                            <button type="submit" name="dm_editar_vendedor" class="button button-primary">Guardar
                                Cambios</button>
                            <a href="?page=dm-rifa-vendedores" class="button">Cancelar</a>
                        </p>
                    </form>
                </div>
            </div>
            <?php
            return;
        }

        // Nueva Vista: Reportar Venta Física
        if ($action === 'report_sale' && $seller_id > 0) {
            error_log("DM RIFA DEBUG: Entering report_sale view");
            $seller_id = intval($_GET['id']);
            $vendedor = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tbl_vendedores} WHERE id = %d", $seller_id));
            if (!$vendedor) {
                echo "Vendedor no encontrado.";
                return;
            }

            $rifas = $wpdb->get_results("SELECT id, nombre FROM {$this->tbl_rifas} ORDER BY id DESC");
            $rifa_id = intval($_GET['rifa_id'] ?? ($rifas[0]->id ?? 0));

            // Números ASIGNADOS a este vendedor
            $nums_asignados = $wpdb->get_results($wpdb->prepare(
                "SELECT numero FROM {$this->tbl_numeros} WHERE vendedor_id = %d AND rifa_id = %d AND estado = 'asignado' ORDER BY CAST(numero AS UNSIGNED) ASC",
                $seller_id,
                $rifa_id
            ));
            ?>
            <div class="wrap">
                <h1>Reportar Venta Física: <?php echo esc_html($vendedor->nombre); ?></h1>
                <p><a href="?page=dm-rifa-vendedores" class="button">Volver al listado</a></p>

                <div class="card" style="max-width: 600px; margin-top: 20px;">
                    <form method="get" action="">
                        <input type="hidden" name="page" value="dm-rifa-vendedores">
                        <input type="hidden" name="action" value="report_sale">
                        <input type="hidden" name="id" value="<?php echo $seller_id; ?>">
                        <p>
                            <label><strong>Rifa:</strong></label>
                            <select name="rifa_id" onchange="this.form.submit()">
                                <?php foreach ($rifas as $r): ?>
                                    <option value="<?php echo $r->id; ?>" <?php selected($rifa_id, $r->id); ?>>
                                        <?php echo esc_html($r->nombre); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </p>
                    </form>

                    <form method="post">
                        <?php wp_nonce_field('dm_report_sale_nonce'); ?>
                        <input type="hidden" name="vendedor_id" value="<?php echo $seller_id; ?>">
                        <input type="hidden" name="rifa_id" value="<?php echo $rifa_id; ?>">

                        <h3>Datos del Comprador (Opcional):</h3>
                        <p>
                            <input type="text" name="nombre_comprador" placeholder="Nombre del cliente" class="regular-text"
                                style="margin-bottom: 10px; display: block;">
                            <input type="text" name="telefono_comprador" placeholder="Teléfono" class="regular-text"
                                style="display: block;">
                        </p>

                        <h3>Selecciona los números vendidos:</h3>
                        <p class="description">Selecciona los números que el vendedor ya entregó y cobró físicamente. Estos pasarán
                            de "Asignado" a "Pagado".</p>

                        <?php if (empty($nums_asignados)): ?>
                            <div style="padding: 20px; background: #fff8e1; border-left: 4px solid #ffb300; margin: 20px 0;">
                                <p>Este vendedor no tiene números asignados para venta física en esta rifa.</p>
                            </div>
                        <?php else: ?>
                            <div
                                style="display: grid; grid-template-columns: repeat(auto-fill, minmax(80px, 1fr)); gap: 10px; margin: 20px 0; border: 1px solid #ddd; padding: 15px; background: #fff; border-radius: 4px;">
                                <?php foreach ($nums_asignados as $na): ?>
                                    <label
                                        style="border: 1px solid #ccc; padding: 10px; border-radius: 4px; display: flex; align-items: center; gap: 8px; cursor: pointer; background: #fcfcfc;">
                                        <input type="checkbox" name="numeros_venda[]" value="<?php echo esc_attr($na->numero); ?>">
                                        <strong style="font-size: 16px;"><?php echo esc_html($na->numero); ?></strong>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <p style="margin-top: 30px;">
                                <button type="submit" name="dm_reportar_venta_fisica" class="button button-primary button-large"
                                    onclick="return confirm('¿Confirmas que estos números ya fueron vendidos físicamente?')">Registrar
                                    Venta Física</button>
                            </p>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
            <?php
            return;
        }

        // --- VISTA REPORTE INDIVIDUAL ---
        if ($action === 'report' && $seller_id > 0) {
            error_log("DM RIFA DEBUG: Entering report view");
            $vendedor = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tbl_vendedores} WHERE id = %d", $seller_id));
            if (!$vendedor) {
                echo "Vendedor no encontrado";
                return;
            }

            $rifas = $wpdb->get_results("SELECT id, nombre FROM {$this->tbl_rifas} ORDER BY id DESC");
            $rifa_id = intval($_GET['rifa_id'] ?? 0);

            $where_stats = "WHERE r.vendedor_id = %d";
            $where_simple = "WHERE vendedor_id = %d";

            if ($rifa_id > 0) {
                $where_stats .= " AND r.rifa_id = %d";
                $where_simple .= " AND rifa_id = %d";
            }

            // Parámetros dinámicos según si hay filtro de rifa o no
            $sub_params = ($rifa_id > 0) ? [$seller_id, $rifa_id] : [$seller_id];

            // Estadísticas generales para este vendedor (Separadas para mayor robustez)
            $stats = new stdClass();
            $stats->pagadas = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->tbl_numeros} n 
                 JOIN {$this->tbl_reservas} r ON n.reserva_id = r.id 
                 $where_stats AND n.estado = 'pagado'",
                ...$sub_params
            )) ?: 0;

            $stats->reservadas = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->tbl_numeros} n 
                 JOIN {$this->tbl_reservas} r ON n.reserva_id = r.id 
                 $where_stats AND n.estado = 'reservado'",
                ...$sub_params
            )) ?: 0;

            // Monto pendiente = precio_unit × números aún en estado 'reservado'
            // vinculados a este vendedor. Evita depender del campo 'total' desactualizado.
            $precio_unit_vendedor = intval($wpdb->get_var(
                "SELECT r.precio FROM {$this->tbl_rifas} r
                 JOIN {$this->tbl_reservas} res ON res.rifa_id = r.id
                 WHERE res.vendedor_id = " . intval($seller_id) .
                ($rifa_id > 0 ? " AND res.rifa_id = " . intval($rifa_id) : '') .
                " LIMIT 1"
            ));
            $stats->monto_reservado = $precio_unit_vendedor * intval($stats->reservadas);

            $stats->asignadas_fisica = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->tbl_numeros} 
                 $where_simple AND estado = 'asignado'",
                ...$sub_params
            )) ?: 0;

            $stats->total_recaudado = $wpdb->get_var($wpdb->prepare(
                "SELECT SUM(total) FROM {$this->tbl_reservas} 
                 $where_simple AND status = 'pagado'",
                ...$sub_params
            )) ?: 0;

            $stats->recaudo_efectivo = $wpdb->get_var($wpdb->prepare(
                "SELECT SUM(total) FROM {$this->tbl_reservas} 
                 $where_simple AND status = 'pagado' AND forma_pago = 'efectivo'",
                ...$sub_params
            )) ?: 0;

            $stats->recaudo_transferencia = $wpdb->get_var($wpdb->prepare(
                "SELECT SUM(total) FROM {$this->tbl_reservas} 
                 $where_simple AND status = 'pagado' AND (forma_pago = 'transferencia' OR forma_pago IS NULL OR forma_pago = '')",
                ...$sub_params
            )) ?: 0;

            $stats->total_entregado = $wpdb->get_var($wpdb->prepare(
                "SELECT SUM(monto) FROM {$this->tbl_arqueos} 
                 $where_simple",
                ...$sub_params
            )) ?: 0;

            // Listado de reservas de este vendedor
            $reservas = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->tbl_reservas} $where_simple ORDER BY created_at DESC",
                ...$sub_params
            ));

            // Listado de arqueos (entregas de dinero)
            $arqueos = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->tbl_arqueos} $where_simple ORDER BY fecha DESC",
                ...$sub_params
            ));

            ?>
            <div class="wrap">
                <h1>Reporte Detallado: <?php echo esc_html($vendedor->nombre); ?></h1>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <div style="display: flex; gap: 10px;">
                        <a href="?page=dm-rifa-vendedores" class="button">← Volver al listado</a>
                        <a href="?page=dm-rifa-vendedores&action=edit&id=<?php echo $seller_id; ?>" class="button button-primary">✏️
                            Editar Datos del Vendedor</a>
                        <?php
                        $export_url = wp_nonce_url(
                            admin_url('admin-post.php?action=dm_rifa_export_vendedor&vendedor_id=' . $seller_id . '&rifa_id=' . $rifa_id),
                            'dm_rifa_export_vendedor'
                        );
                        ?>
                        <a href="<?php echo esc_url($export_url); ?>" class="button"
                            style="background: #00a32a; color: white; border-color: #00a32a;">📥 Descargar Reporte CSV</a>
                    </div>
                    <form method="get" action="" style="display: flex; align-items: center; gap: 10px;">
                        <input type="hidden" name="page" value="dm-rifa-vendedores">
                        <input type="hidden" name="action" value="report">
                        <input type="hidden" name="id" value="<?php echo $seller_id; ?>">
                        <label><strong>Filtrar por Rifa:</strong></label>
                        <select name="rifa_id" onchange="this.form.submit()">
                            <option value="0">Todas las Rifas</option>
                            <?php foreach ($rifas as $r): ?>
                                <option value="<?php echo $r->id; ?>" <?php selected($rifa_id, $r->id); ?>>
                                    <?php echo esc_html($r->nombre); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>

                <!-- Sección resumen con 4 métricas principales -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin: 20px 0;">
                    <div class="card" style="margin:0; padding:15px; border-left: 4px solid #2271b1;">
                        <h3 style="margin:0; font-size: 13px; color: #666;">✅ Boletas Vendidas</h3>
                        <p style="font-size:28px; font-weight:bold; margin:10px 0 0;"><?php echo intval($stats->pagadas); ?></p>
                    </div>
                    <div class="card" style="margin:0; padding:15px; border-left: 4px solid #ed6c02;">
                        <h3 style="margin:0; font-size: 13px; color: #666;">⏳ Boletas Reservadas</h3>
                        <p style="font-size:28px; font-weight:bold; margin:10px 0 0;"><?php echo intval($stats->reservadas); ?></p>
                        <p style="font-size:11px; color: #666; margin: 5px 0 0;">
                            $<?php echo number_format($stats->monto_reservado, 0, ',', '.'); ?> pendiente</p>
                    </div>
                    <div class="card" style="margin:0; padding:15px; border-left: 4px solid #d32f2f;">
                        <h3 style="margin:0; font-size: 13px; color: #666;">💰 Dinero Entregado</h3>
                        <p style="font-size:28px; font-weight:bold; margin:10px 0 0;">
                            $<?php echo number_format($stats->total_entregado, 0, ',', '.'); ?></p>
                        <p style="font-size:11px; color: #666; margin: 5px 0 0;">Arqueos registrados</p>
                    </div>
                    <div class="card" style="margin:0; padding:15px; border-left: 4px solid #2e7d32;">
                        <h3 style="margin:0; font-size: 13px; color: #666;">💵 Total Recaudado</h3>
                        <p style="font-size:28px; font-weight:bold; margin:10px 0 0;">
                            $<?php echo number_format($stats->total_recaudado, 0, ',', '.'); ?></p>
                        <p style="font-size:11px; color: #666; margin: 5px 0 0;">De <?php echo intval($stats->pagadas); ?> boletas
                        </p>
                    </div>
                    <?php if ($stats->asignadas_fisica > 0): ?>
                        <div class="card" style="margin:0; padding:15px; border-left: 4px solid #ad1457;">
                            <h3 style="margin:0; font-size: 13px; color: #666;">🎟️ Asignadas (Física)</h3>
                            <p style="font-size:28px; font-weight:bold; margin:10px 0 0;">
                                <?php echo intval($stats->asignadas_fisica); ?>
                            </p>
                            <p style="font-size:11px; color: #666; margin: 5px 0 0;">Sin vender</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Desglose financiero detallado -->
                <div style="background: #f9fafb; padding: 20px; border-radius: 8px; margin: 20px 0;">
                    <h3 style="margin: 0 0 15px 0;">📊 Desglose Financiero</h3>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px;">
                        <div>
                            <div style="font-size: 11px; color: #666; margin-bottom: 3px;">💳 Transferencias</div>
                            <div style="font-size: 18px; font-weight: 600;">
                                $<?php echo number_format($stats->recaudo_transferencia, 0, ',', '.'); ?></div>
                        </div>
                        <div>
                            <div style="font-size: 11px; color: #666; margin-bottom: 3px;">💵 Efectivo</div>
                            <div style="font-size: 18px; font-weight: 600;">
                                $<?php echo number_format($stats->recaudo_efectivo, 0, ',', '.'); ?></div>
                        </div>
                        <div>
                            <div style="font-size: 11px; color: #666; margin-bottom: 3px;">⏰ Pendiente de Cobro</div>
                            <div style="font-size: 18px; font-weight: 600; color: #f59e0b;">
                                $<?php echo number_format($stats->monto_reservado, 0, ',', '.'); ?></div>
                        </div>
                        <div style="background: #fff; padding: 10px; border-radius: 6px; border-left: 3px solid #f9a825;">
                            <div style="font-size: 11px; color: #666; margin-bottom: 3px;">💼 Saldo por Entregar</div>
                            <div
                                style="font-size: 18px; font-weight: 600; color: <?php echo ($stats->total_recaudado - $stats->total_entregado) > 0 ? '#d32f2f' : '#2e7d32'; ?>;">
                                $<?php echo number_format($stats->total_recaudado - $stats->total_entregado, 0, ',', '.'); ?></div>
                        </div>
                    </div>
                </div>

                <div style="display: flex; gap: 20px; margin-top: 30px;">
                    <!-- Registro de Arqueo -->
                    <div style="flex: 1; background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #ccd0d4;">
                        <h2 style="margin-top: 0;">Registrar Movimiento</h2>

                        <!-- Tabs Entrega / Devolución -->
                        <div style="display:flex; gap:0; margin-bottom:20px; border-bottom:2px solid #e0e0e0;">
                            <button type="button" id="tab-entrega" onclick="switchTab('entrega')"
                                style="padding:8px 18px; border:none; background:none; cursor:pointer; font-weight:700; font-size:13px; color:#2271b1; border-bottom:3px solid #2271b1; margin-bottom:-2px;">
                                ⬆️ Entrega
                            </button>
                            <button type="button" id="tab-devolucion" onclick="switchTab('devolucion')"
                                style="padding:8px 18px; border:none; background:none; cursor:pointer; font-weight:600; font-size:13px; color:#666; border-bottom:3px solid transparent; margin-bottom:-2px;">
                                ↩ Devolución
                            </button>
                        </div>

                        <!-- Panel Entrega -->
                        <div id="panel-entrega">
                            <form method="post" action="">
                                <?php wp_nonce_field('dm_arqueo_nonce'); ?>
                                <input type="hidden" name="vendedor_id" value="<?php echo $seller_id; ?>">
                                <?php
                                // Siempre usar la rifa activa; si hay más de una, mostrar selector
                                $rifas_activas = array_filter($rifas, fn($r) => true); // todas las rifas disponibles
                                $rifa_default = ($rifa_id > 0) ? $rifa_id : intval($wpdb->get_var(
                                    "SELECT id FROM {$this->tbl_rifas} WHERE activo = 1 ORDER BY id DESC LIMIT 1"
                                ));
                                ?>
                                <?php if (count($rifas) > 1): ?>
                                    <p>
                                        <label><strong>Rifa:</strong></label><br>
                                        <select name="rifa_id" class="regular-text">
                                            <?php foreach ($rifas as $r): ?>
                                                <option value="<?php echo $r->id; ?>" <?php selected($rifa_default, $r->id); ?>>
                                                    <?php echo esc_html($r->nombre); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </p>
                                <?php else: ?>
                                    <input type="hidden" name="rifa_id" value="<?php echo $rifa_default; ?>">
                                    <?php if ($rifa_default > 0 && count($rifas) === 1): ?>
                                        <p style="font-size:12px; color:#666; margin:0 0 10px;">🎟️ Rifa:
                                            <strong><?php echo esc_html($rifas[0]->nombre); ?></strong></p>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <p>
                                    <label><strong>Monto Entregado:</strong></label><br>
                                    <input type="number" name="monto" class="regular-text" required min="1" placeholder="Ej: 50000">
                                </p>
                                <p>
                                    <label><strong>Observaciones:</strong></label><br>
                                    <textarea name="observaciones" style="width: 100%;" rows="2"
                                        placeholder="Semana 1, evento central, etc."></textarea>
                                </p>
                                <p>
                                    <button type="submit" name="dm_registrar_arqueo" class="button button-primary"
                                        onclick="return confirm('¿Seguro que deseas registrar esta entrega de dinero?')">⬆️
                                        Registrar Entrega</button>
                                </p>
                            </form>
                        </div>

                        <!-- Panel Devolución -->
                        <div id="panel-devolucion" style="display:none;">
                            <form method="post" action="">
                                <?php wp_nonce_field('dm_arqueo_nonce'); ?>
                                <input type="hidden" name="vendedor_id" value="<?php echo $seller_id; ?>">
                                <?php if (count($rifas) > 1): ?>
                                    <p>
                                        <label><strong>Rifa:</strong></label><br>
                                        <select name="rifa_id" class="regular-text">
                                            <?php foreach ($rifas as $r): ?>
                                                <option value="<?php echo $r->id; ?>" <?php selected($rifa_default, $r->id); ?>>
                                                    <?php echo esc_html($r->nombre); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </p>
                                <?php else: ?>
                                    <input type="hidden" name="rifa_id" value="<?php echo $rifa_default; ?>">
                                <?php endif; ?>
                                <div
                                    style="background:#fff8e1; border:1px solid #f9a825; border-radius:6px; padding:12px; margin-bottom:15px; font-size:12px; color:#5d4037;">
                                    ⚠️ <strong>Devolución:</strong> Se registrará un monto <em>negativo</em> que reducirá el total
                                    entregado en caja.
                                </div>
                                <p>
                                    <label><strong>Monto a Devolver:</strong></label><br>
                                    <input type="number" name="monto_devolucion" id="monto_devolucion_input" class="regular-text"
                                        required min="1" placeholder="Ej: 10000">
                                </p>
                                <p>
                                    <label><strong>Motivo de la devolución:</strong> <span
                                            style="color:#d32f2f;">*</span></label><br>
                                    <textarea name="observaciones" style="width: 100%;" rows="3" required
                                        placeholder="Ej: El vendedor entregó $10.000 de más el 24/02/2026"></textarea>
                                </p>
                                <p>
                                    <button type="submit" name="dm_registrar_devolucion" class="button"
                                        style="background:#d32f2f; color:#fff; border-color:#b71c1c;"
                                        onclick="return confirm('¿Confirmar devolución? Se registrará un monto negativo.')">↩
                                        Confirmar Devolución</button>
                                </p>
                            </form>
                        </div>
                    </div>

                    <!-- Historial de Arqueos -->
                    <div style="flex: 1.5; background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #ccd0d4;">
                        <h2 style="margin-top: 0;">Historial de Movimientos</h2>
                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Tipo / Monto</th>
                                    <th>Rifa</th>
                                    <th>Observaciones</th>
                                    <th>Editar / Dev.</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($arqueos): ?>
                                    <?php foreach ($arqueos as $arq): ?>
                                        <?php $es_devolucion = ($arq->monto < 0); ?>
                                        <tr style="<?php echo $es_devolucion ? 'background:#fef9f9;' : ''; ?>">
                                            <td style="font-size:12px;"><?php echo date('d/m/Y H:i', strtotime($arq->fecha)); ?></td>
                                            <td style="font-weight:bold;">
                                                <?php if ($es_devolucion): ?>
                                                    <span
                                                        style="display:inline-block; background:#e8f5e9; color:#1b5e20; padding:2px 7px; border-radius:4px; font-size:11px; margin-bottom:2px;">↩
                                                        DEVOLUCIÓN</span><br>
                                                    <span
                                                        style="color:#2e7d32;">+$<?php echo number_format(abs($arq->monto), 0, ',', '.'); ?></span>
                                                    <span style="font-size:10px; color:#999;"> (regresa a caja)</span>
                                                <?php else: ?>
                                                    <span style="color:#d32f2f;">-$<?php echo number_format($arq->monto, 0, ',', '.'); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php
                                                if ($arq->rifa_id) {
                                                    $r_match = array_values(array_filter($rifas, fn($r) => $r->id == $arq->rifa_id));
                                                    echo $r_match ? '<span style="background:#e7f5ec; color:#18502f; padding:2px 6px; border-radius:4px; font-size:11px; font-weight:600;">' . esc_html($r_match[0]->nombre) . '</span>' : '<span style="color:#999;">N/A</span>';
                                                } else {
                                                    echo '<span style="background:#fff3e0; color:#e65100; padding:2px 6px; border-radius:4px; font-size:11px;">Sin asignar</span>';
                                                }
                                                ?>
                                            </td>
                                            <td style="font-size: 11px; color:#555;"><?php echo esc_html($arq->observaciones ?: '—'); ?>
                                            </td>
                                            <td>
                                                <div style="display:flex; flex-direction:column; gap:4px;">
                                                    <!-- Editar rifa asignada -->
                                                    <form method="post" action="" style="display:flex; gap:4px; align-items:center;">
                                                        <?php wp_nonce_field('dm_arqueo_nonce'); ?>
                                                        <input type="hidden" name="arqueo_id" value="<?php echo $arq->id; ?>">
                                                        <input type="hidden" name="vendedor_id" value="<?php echo $seller_id; ?>">
                                                        <input type="hidden" name="rifa_id" value="<?php echo $rifa_id; ?>">
                                                        <select name="nueva_rifa_id" style="font-size:11px; padding:2px 4px;">
                                                            <?php foreach ($rifas as $r): ?>
                                                                <option value="<?php echo $r->id; ?>" <?php selected($arq->rifa_id, $r->id); ?>>
                                                                    <?php echo esc_html($r->nombre); ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <button type="submit" name="dm_editar_arqueo_rifa" class="button button-small"
                                                            title="Guardar cambio de rifa">✔</button>
                                                    </form>
                                                    <?php if (!$es_devolucion): ?>
                                                        <!-- Botón para registrar devolución de este arqueo -->
                                                        <button type="button"
                                                            onclick="activarDevolucion(<?php echo $arq->id; ?>, <?php echo $arq->monto; ?>, '<?php echo esc_js($arq->observaciones); ?>')"
                                                            class="button button-small"
                                                            style="color:#c62828; border-color:#c62828; font-size:11px;"
                                                            title="Registrar devolución de este arqueo">
                                                            ↩ Devolver
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" style="text-align:center; color:#999; padding:20px;">No hay movimientos
                                            registrados.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <script>
                    function switchTab(tab) {
                        document.getElementById('panel-entrega').style.display = (tab === 'entrega') ? 'block' : 'none';
                        document.getElementById('panel-devolucion').style.display = (tab === 'devolucion') ? 'block' : 'none';
                        document.getElementById('tab-entrega').style.cssText = 'padding:8px 18px;border:none;background:none;cursor:pointer;font-weight:' + (tab === 'entrega' ? '700' : '600') + ';font-size:13px;color:' + (tab === 'entrega' ? '#2271b1' : '#666') + ';border-bottom:3px solid ' + (tab === 'entrega' ? '#2271b1' : 'transparent') + ';margin-bottom:-2px;';
                        document.getElementById('tab-devolucion').style.cssText = 'padding:8px 18px;border:none;background:none;cursor:pointer;font-weight:' + (tab === 'devolucion' ? '700' : '600') + ';font-size:13px;color:' + (tab === 'devolucion' ? '#c62828' : '#666') + ';border-bottom:3px solid ' + (tab === 'devolucion' ? '#c62828' : 'transparent') + ';margin-bottom:-2px;';
                    }
                    function activarDevolucion(id, monto, obs) {
                        switchTab('devolucion');
                        var campo = document.getElementById('monto_devolucion_input');
                        if (campo) campo.value = monto;
                        document.getElementById('monto_devolucion_input').scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                </script>

                <h2>Compradores Gestionados</h2>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Comprador</th>
                            <th>Teléfono</th>
                            <th>Números</th>
                            <th>Total</th>
                            <th>Estado</th>
                            <th>Pago</th>
                            <th>Comprobante</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($reservas): ?>
                            <?php foreach ($reservas as $res): ?>
                                <tr>
                                    <td><?php echo esc_html(date('d/m/Y H:i', strtotime($res->created_at))); ?></td>
                                    <td><strong><?php echo esc_html($res->nombre); ?></strong></td>
                                    <td><?php echo esc_html($res->telefono); ?></td>
                                    <td><?php echo esc_html($res->numeros_csv); ?></td>
                                    <td><?php echo esc_html(number_format($res->total, 0, ',', '.')); ?></td>
                                    <td>
                                        <span
                                            style="padding:4px 8px; border-radius:4px; font-size:11px; font-weight:bold; color:#fff; background:<?php echo ($res->status == 'pagado') ? '#2e7d32' : (($res->status == 'reservado') ? '#ed6c02' : '#757575'); ?>;">
                                            <?php echo strtoupper(esc_html($res->status)); ?>
                                        </span>
                                    </td>
                                    <td><span class="badge"
                                            style="background:#f0f0f0; padding:2px 6px; border-radius:3px; font-size:10px;"><?php echo strtoupper(esc_html($res->forma_pago ?: 'transferencia')); ?></span>
                                    </td>
                                    <td>
                                        <?php if (!empty($res->comprobante_url)): ?>
                                            <a href="<?php echo esc_url($res->comprobante_url); ?>" target="_blank" style="color: #2e7d32;">Ver
                                                ✅</a>
                                        <?php else: ?>
                                            <span style="color: #d32f2f;">Falta ❌</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="<?php echo admin_url('admin.php?page=dm-rifa-compradores&rifa_id=' . $res->rifa_id . '&view=' . $res->id); ?>"
                                            class="button button-small">Gestionar</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8">Este vendedor aún no tiene registros de venta o reserva.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php
            return;
        }

        error_log("DM RIFA DEBUG: Table name: " . $this->tbl_vendedores);

        // 1. Obtener vendedores básicos
        $query = "SELECT * FROM {$this->tbl_vendedores} ORDER BY nombre ASC";
        $vendedores = $wpdb->get_results($query);

        if ($vendedores) {
            // 2. Obtener conteo de números vendidos/pagados por vendedor (desde reservas para coincidir con reportes)
            $ventas_raw = $wpdb->get_results("
                SELECT r.vendedor_id, COUNT(*) as total 
                FROM {$this->tbl_numeros} n
                JOIN {$this->tbl_reservas} r ON n.reserva_id = r.id
                WHERE n.estado = 'pagado' 
                GROUP BY r.vendedor_id
            ", OBJECT_K);

            // 2b. Obtener conteo de números reservados por vendedor
            $reservadas_raw = $wpdb->get_results("
                SELECT r.vendedor_id, COUNT(*) as total 
                FROM {$this->tbl_numeros} n
                JOIN {$this->tbl_reservas} r ON n.reserva_id = r.id
                WHERE n.estado = 'reservado' 
                GROUP BY r.vendedor_id
            ", OBJECT_K);

            // 3. Obtener conteo de números asignados (físicos - éstos sí dependen del vendedor_id en números)
            $asignados_raw = $wpdb->get_results("
                SELECT vendedor_id, COUNT(*) as total 
                FROM {$this->tbl_numeros} 
                WHERE estado = 'asignado' 
                GROUP BY vendedor_id
            ", OBJECT_K);

            // 4. Obtener recaudación (reservas pagadas)
            $recaudado_raw = $wpdb->get_results("
                SELECT vendedor_id, SUM(total) as total 
                FROM {$this->tbl_reservas} 
                WHERE status = 'pagado' 
                GROUP BY vendedor_id
            ", OBJECT_K);

            // 5. Obtener entregado (arqueos)
            $entregado_raw = $wpdb->get_results("
                SELECT vendedor_id, SUM(monto) as total 
                FROM {$this->tbl_arqueos} 
                GROUP BY vendedor_id
            ", OBJECT_K);

            // 6. Mapear datos a los objetos de vendedor
            foreach ($vendedores as $v) {
                $vid = $v->id;
                $v->ventas = isset($ventas_raw[$vid]) ? intval($ventas_raw[$vid]->total) : 0;
                $v->reservadas = isset($reservadas_raw[$vid]) ? intval($reservadas_raw[$vid]->total) : 0;
                $v->asignados = isset($asignados_raw[$vid]) ? intval($asignados_raw[$vid]->total) : 0;
                $v->recaudado = isset($recaudado_raw[$vid]) ? floatval($recaudado_raw[$vid]->total) : 0;
                $v->entregado = isset($entregado_raw[$vid]) ? floatval($entregado_raw[$vid]->total) : 0;
            }
        }

        $has_mixto = $wpdb->get_var("SELECT COUNT(*) FROM {$this->tbl_rifas} WHERE modo_venta = 'mixto'");
        ?>
        <div class="wrap">
            <h1>Gestión de Vendedores</h1>

            <div style="display: flex; gap: 20px; flex-wrap: wrap; margin-top: 20px;">
                <div class="card" style="flex: 1; min-width: 300px; margin: 0;">
                    <h2>Añadir Nuevo Vendedor</h2>
                    <form method="post">
                        <?php wp_nonce_field('dm_vendedor_nonce'); ?>
                        <table class="form-table">
                            <tr>
                                <th>Nombre</th>
                                <td><input type="text" name="nombre" required class="regular-text"></td>
                            </tr>
                            <tr>
                                <th>Email</th>
                                <td><input type="email" name="email" class="regular-text"></td>
                            </tr>
                            <tr>
                                <th>Teléfono/WhatsApp</th>
                                <td><input type="text" name="telefono" required class="regular-text"></td>
                            </tr>
                        </table>
                        <p><button type="submit" name="dm_crear_vendedor" class="button button-primary">Registrar
                                Vendedor</button></p>
                    </form>
                </div>

                <div class="card" style="flex: 1; min-width: 300px; margin: 0;">
                    <h2>Importar Vendedores</h2>
                    <p class="description">Sube un CSV (Nombre, Email, Teléfono).</p>
                    <form method="post" enctype="multipart/form-data">
                        <?php wp_nonce_field('dm_bulk_vendedor_nonce'); ?>
                        <input type="file" name="bulk_file" accept=".csv" required>
                        <p><button type="submit" name="dm_bulk_vendedores" class="button">Importar por Lote</button></p>
                    </form>
                </div>

                <?php if ($has_mixto > 0): ?>
                    <div class="card" style="flex: 1; min-width: 300px; margin: 0;">
                        <h2>Auto-Asignar Números</h2>
                        <p class="description">Asigna aleatoriamente 10 números disponibles a cada vendedor que tenga menos de 10
                            asignados.</p>
                        <form method="post">
                            <?php wp_nonce_field('dm_auto_assign_nonce'); ?>
                            <p>
                                <label>Rifa:</label>
                                <select name="rifa_id" required>
                                    <option value="">(Seleccionar Rifa)</option>
                                    <?php
                                    $context_rifa_id = $this->get_context_rifa_id();
                                    $rifas_list = $wpdb->get_results("SELECT id, nombre FROM {$this->tbl_rifas} ORDER BY id DESC");
                                    foreach ($rifas_list as $rl): ?>
                                        <option value="<?php echo $rl->id; ?>" <?php selected($context_rifa_id, $rl->id); ?>>
                                            <?php echo esc_html($rl->nombre); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </p>
                            <p>
                                <button type="submit" name="dm_auto_assign_10" class="button button-primary"
                                    onclick="return confirm('¿Asignar 10 números aleatorios a todos?')">Auto-Asignar 10 por
                                    Vendedor</button>
                            </p>
                        </form>
                    </div>
                <?php endif; ?>
            </div>

            <div
                style="display: flex; justify-content: space-between; align-items: center; margin-top: 30px; margin-bottom: 10px;">
                <h2 style="margin: 0;">Listado de Vendedores</h2>
                <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=dm_rifa_export_report'), 'dm_rifa_export_report'); ?>"
                    class="button button-primary" style="background: #2e7d32; border-color: #2e7d32; font-weight: bold;"
                    title="Descargar resumen general de todos los vendedores en CSV">
                    📥 Exportar Reporte General CSV
                </a>
            </div>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th><a href="?page=dm-rifa-vendedores&orderby=nombre&order=<?php echo $next_order; ?>">Nombre</a></th>
                        <th>Email</th>
                        <th>Teléfono</th>
                        <th><a href="?page=dm-rifa-vendedores&orderby=ventas&order=<?php echo $next_order; ?>">Vendidas</a></th>
                        <th>Reservadas</th>
                        <th>Recaudado</th>
                        <th>Entregado</th>
                        <th>Total</th>
                        <?php if ($has_mixto > 0): ?>
                            <th>Asignadas (Física)</th>
                        <?php endif; ?>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($vendedores):
                        foreach ($vendedores as $v): ?>
                            <tr>
                                <td><?php echo intval($v->id); ?></td>
                                <td><strong><?php echo esc_html($v->nombre); ?></strong></td>
                                <td><?php echo esc_html($v->email); ?></td>
                                <td><?php echo esc_html($v->telefono); ?></td>
                                <td><span class="badge"
                                        style="background:#00a32a; color:#fff; padding:2px 8px; border-radius:4px; font-weight: 600;"><?php echo intval($v->ventas); ?></span>
                                </td>
                                <td><span class="badge"
                                        style="background:#ed6c02; color:#fff; padding:2px 8px; border-radius:4px; font-weight: 600;"><?php echo intval($v->reservadas); ?></span>
                                </td>
                                <td style="font-weight:bold; color:#2e7d32;">$<?php echo number_format($v->recaudado, 0, ',', '.'); ?>
                                </td>
                                <td style="font-weight:bold; color:#d32f2f;">$<?php echo number_format($v->entregado, 0, ',', '.'); ?>
                                </td>
                                <td
                                    style="font-weight:bold; color:<?php echo ($v->recaudado - $v->entregado) > 0 ? '#f9a825' : '#2e7d32'; ?>;">
                                    $<?php echo number_format($v->recaudado - $v->entregado, 0, ',', '.'); ?></td>
                                <?php if ($has_mixto > 0): ?>
                                    <td><span class="badge"
                                            style="background:#ad1457; color:#fff; padding:2px 8px; border-radius:4px;"><?php echo intval($v->asignados); ?></span>
                                    </td>
                                <?php endif; ?>
                                <td>
                                    <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                                        <?php if ($has_mixto > 0): ?>
                                            <a href="<?php echo admin_url('admin.php?page=dm-rifa-vendedores&action=assign&id=' . $v->id); ?>"
                                                class="button button-small" title="Asignar boletas físicas">Física</a>
                                            <a href="<?php echo admin_url('admin.php?page=dm-rifa-vendedores&action=report_sale&id=' . $v->id); ?>"
                                                class="button button-small" style="background:#2271b1; color:#fff;"
                                                title="Informar venta de boletas asignadas">Venta</a>
                                        <?php endif; ?>

                                        <a href="<?php echo admin_url('admin.php?page=dm-rifa-vendedores&action=edit&id=' . $v->id); ?>"
                                            class="button button-small" title="Editar datos del vendedor">Editar</a>

                                        <?php
                                        // Generar link de WhatsApp con instrucciones
                                        $v_nums = $wpdb->get_col(
                                            "SELECT numero FROM {$this->tbl_numeros} WHERE vendedor_id = {$v->id} AND estado = 'asignado' ORDER BY CAST(numero AS UNSIGNED) ASC"
                                        );
                                        $nums_text = !empty($v_nums) ? implode(", ", $v_nums) : "Aún no tienes números asignados";
                                        $site_url = home_url();
                                        $msg = "Hola *" . esc_attr($v->nombre) . "*, te hemos asignado estos números para venta física 🎟️\n\n";
                                        $msg .= "👉 *$nums_text*\n\n";
                                        $msg .= "✅ *Instrucciones:* \n";
                                        $msg .= "1. Realiza la venta y cobra al cliente.\n";
                                        $msg .= "2. Reporta de inmediato al administrador para registrarla:\n";
                                        $admin_wa = $wpdb->get_var("SELECT wa_e164 FROM {$this->tbl_rifas} WHERE activo = 1 LIMIT 1");
                                        $msg .= "📲 WhatsApp " . ($admin_wa ?: '3123625582') . " (Administrador)\n";
                                        $msg .= "Envía: Nombre del comprador + número vendido + comprobante.\n\n";
                                        $msg .= "Estos números ya están bloqueados en la web para evitar que alguien más los compre.\n";
                                        $msg .= "¡Muchos éxitos! 🚀";
                                        $wa_url = "https://api.whatsapp.com/send?phone=" . preg_replace('/\D/', '', $v->telefono) . "&text=" . urlencode($msg);
                                        ?>
                                        <?php
                                        // 2. Mensaje General de guía de venta
                                        $rifa_info = $wpdb->get_row("SELECT url_rifa FROM {$this->tbl_rifas} WHERE activo = 1 LIMIT 1");
                                        $site_url = ($rifa_info && $rifa_info->url_rifa) ? $rifa_info->url_rifa : home_url();

                                        $msg_guia = "Hola *" . esc_attr($v->nombre) . "*, estas son las instrucciones para realizar las ventas de la rifa de manera correcta:

🚀 *Paso a paso para vender:*
1️⃣ Ingresa al link de la rifa: " . $site_url . "
2️⃣ Deja que el cliente elija sus números favoritos en el mapa o ayúdalo a buscarlos.
3️⃣ Completa los datos del comprador (Nombre y Teléfono son obligatorios).
4️⃣ *MUY IMPORTANTE:* En el campo que dice *\"Vendedor responsable\"*, asegúrate de seleccionar tu nombre (*" . esc_attr($v->nombre) . "*) para que la venta cuente para ti.
5️⃣ Haz clic en el botón de *\"Reservar\"*.

💰 *Reporte de pagos:*
Una vez hecha la reserva en la web, para que yo pueda emitir las boletas oficiales, debes enviarme lo siguiente:
* Si es por *Transferencia*: Pídele el comprobante al cliente y reenvíamelo de inmediato.
* Si es en *Efectivo*: Avísame apenas recibas el dinero.

Apenas me confirmes el pago, yo activaré los números en el sistema y se generarán las boletas digitales para el cliente. 

¡Muchos éxitos con las ventas! 🚀";

                                        $wa_url_guia = "https://api.whatsapp.com/send?phone=" . preg_replace('/\D/', '', $v->telefono) . "&text=" . urlencode($msg_guia);
                                        ?>

                                        <a href="<?php echo $wa_url_guia; ?>" target="_blank" class="button button-small"
                                            style="background:#25D366; color:#fff; border-color:#25D366;"
                                            title="Enviar guía paso a paso al vendedor">Guía WA</a>

                                        <a href="<?php echo admin_url('admin.php?page=dm-rifa-vendedores&action=report&id=' . $v->id); ?>"
                                            class="button button-small" style="background: #673ab7; color: #fff; border-color: #673ab7;"
                                            title="Ver Reporte Detallado">Reporte</a>

                                        <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=dm-rifa-vendedores&action=delete&id=' . $v->id), 'dm_del_vendedor_' . $v->id); ?>"
                                            class="button button-link-delete button-small"
                                            onclick="return confirm('¿Eliminar vendedor?')" title="Eliminar vendedor">X</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                        <tr>
                            <td colspan="7">No hay vendedores registrados.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    public function page_compradores()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        global $wpdb;
        $rifa_id = $this->get_context_rifa_id();
        $view_reserva = intval($_GET['view'] ?? 0);

        // Obtener filtros de la URL
        $filter_status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $filter_forma_pago = isset($_GET['forma_pago']) ? sanitize_text_field($_GET['forma_pago']) : '';

        // Obtener parámetros de ordenamiento
        $orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'id';
        $order = isset($_GET['order']) && strtoupper($_GET['order']) === 'ASC' ? 'ASC' : 'DESC';

        // Validar columna de ordenamiento
        $valid_orderby = ['id', 'nombre', 'total', 'created_at', 'vendedor'];
        if (!in_array($orderby, $valid_orderby)) {
            $orderby = 'id';
        }

        $rifas = $wpdb->get_results("SELECT id,nombre FROM {$this->tbl_rifas} ORDER BY id DESC");
        if (!$rifas) {
            echo '<div class="wrap"><h1>Reservas y Ventas</h1><p>Primero crea una rifa.</u003c/p></div>';
            return;
        }

        if (!$rifa_id && !empty($rifas)) {
            $rifa_id = intval($rifas[0]->id);
        }
        $rifa = $this->fetch_rifa($rifa_id);

        // Vista detalle de una reserva
        if ($view_reserva > 0) {
            $this->render_reserva_detail($view_reserva, $rifa_id);
            return;
        }

        // Construir la consulta con filtros
        $where_clauses = ["r.rifa_id = %d"];
        $query_params = [$rifa_id];

        if (!empty($filter_status)) {
            $where_clauses[] = "r.status = %s";
            $query_params[] = $filter_status;
        }

        if (!empty($filter_forma_pago)) {
            if ($filter_forma_pago === 'efectivo') {
                $where_clauses[] = "r.forma_pago = 'efectivo'";
            } elseif ($filter_forma_pago === 'transferencia') {
                $where_clauses[] = "(r.forma_pago = 'transferencia' OR r.forma_pago IS NULL OR r.forma_pago = '')";
            }
        }

        $where_sql = implode(' AND ', $where_clauses);

        // Determinar la columna de ordenamiento para SQL
        $order_column = 'r.id';
        if ($orderby === 'nombre') {
            $order_column = 'r.nombre';
        } elseif ($orderby === 'total') {
            $order_column = 'r.total';
        } elseif ($orderby === 'created_at') {
            $order_column = 'r.created_at';
        } elseif ($orderby === 'vendedor') {
            $order_column = 'v.nombre';
        }

        $query = "SELECT r.*, v.nombre as vendedor_nombre 
                  FROM {$this->tbl_reservas} r 
                  LEFT JOIN {$this->tbl_vendedores} v ON r.vendedor_id = v.id 
                  WHERE {$where_sql}
                  ORDER BY {$order_column} {$order}";

        // Lista de reservas con vendedor y estado calculado en tiempo real
        $res = $wpdb->get_results($wpdb->prepare($query, ...$query_params));

        // Calcular el estado real de cada reserva basado en sus números
        foreach ($res as $reserva) {
            $reserva->estatus_real = $this->calcular_estado_reserva($reserva, $rifa_id);
        }

        $export_url = wp_nonce_url(admin_url('admin-post.php?action=dm_rifa_export_csv&rifa_id=' . $rifa_id), 'dm_rifa_export_csv');

        ?>
        <div class="wrap">
            <h1>Reservas y Ventas - <?php echo esc_html($rifa->nombre); ?></h1>
            <form method="get" style="margin:10px 0;">
                <input type="hidden" name="page" value="dm-rifa-compradores">
                <select name="rifa_id">
                    <?php foreach ($rifas as $r): ?>
                        <option value="<?php echo esc_attr($r->id); ?>" <?php selected(intval($r->id), $rifa_id); ?>>
                            <?php echo esc_html($r->nombre); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button class="button">Ver</button>
                <a class="button button-primary" href="<?php echo esc_url($export_url); ?>">Exportar CSV</a>
            </form>

            <!-- Filtros de estado y forma de pago -->
            <div style="background: #f0f0f1; padding: 15px; border-radius: 5px; margin: 15px 0;">
                <strong>Filtrar por:</strong>
                <div style="margin-top: 10px;">
                    <span style="margin-right: 15px;">
                        <strong>Estado:</strong>
                        <a href="<?php echo admin_url('admin.php?page=dm-rifa-compradores&rifa_id=' . $rifa_id); ?>"
                            class="button <?php echo empty($filter_status) ? 'button-primary' : ''; ?>">
                            Todos
                        </a>
                        <a href="<?php echo admin_url('admin.php?page=dm-rifa-compradores&rifa_id=' . $rifa_id . '&status=pagado'); ?>"
                            class="button <?php echo $filter_status === 'pagado' ? 'button-primary' : ''; ?>">
                            ✅ Pagado
                        </a>
                        <a href="<?php echo admin_url('admin.php?page=dm-rifa-compradores&rifa_id=' . $rifa_id . '&status=reservado'); ?>"
                            class="button <?php echo $filter_status === 'reservado' ? 'button-primary' : ''; ?>">
                            ⏳ Reservado
                        </a>
                    </span>

                    <span>
                        <strong>Forma de Pago:</strong>
                        <a href="<?php echo admin_url('admin.php?page=dm-rifa-compradores&rifa_id=' . $rifa_id . ($filter_status ? '&status=' . $filter_status : '')); ?>"
                            class="button <?php echo empty($filter_forma_pago) ? 'button-primary' : ''; ?>">
                            Todos
                        </a>
                        <a href="<?php echo admin_url('admin.php?page=dm-rifa-compradores&rifa_id=' . $rifa_id . ($filter_status ? '&status=' . $filter_status : '') . '&forma_pago=transferencia'); ?>"
                            class="button <?php echo $filter_forma_pago === 'transferencia' ? 'button-primary' : ''; ?>">
                            💰 Transferencia
                        </a>
                        <a href="<?php echo admin_url('admin.php?page=dm-rifa-compradores&rifa_id=' . $rifa_id . ($filter_status ? '&status=' . $filter_status : '') . '&forma_pago=efectivo'); ?>"
                            class="button <?php echo $filter_forma_pago === 'efectivo' ? 'button-primary' : ''; ?>">
                            💵 Efectivo
                        </a>
                    </span>

                    <?php if (!empty($filter_status) || !empty($filter_forma_pago)): ?>
                        <a href="<?php echo admin_url('admin.php?page=dm-rifa-compradores&rifa_id=' . $rifa_id); ?>" class="button"
                            style="margin-left: 15px; color: #d63638; border-color: #d63638;">
                            🗑️ Limpiar Filtros
                        </a>
                    <?php endif; ?>
                </div>

                <?php if (!empty($filter_status) || !empty($filter_forma_pago)): ?>
                    <div style="margin-top: 10px; font-style: italic; color: #666;">
                        Mostrando:
                        <?php if ($filter_status): ?>
                            <strong>Estado = <?php echo esc_html(ucfirst($filter_status)); ?></strong>
                        <?php endif; ?>
                        <?php if ($filter_status && $filter_forma_pago): ?> | <?php endif; ?>
                        <?php if ($filter_forma_pago): ?>
                            <strong>Forma de Pago = <?php echo esc_html(ucfirst($filter_forma_pago)); ?></strong>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                style="display:inline-block; margin-left:10px;">
                <?php wp_nonce_field('dm_rifa_manual_cleanup'); ?>
                <input type="hidden" name="action" value="dm_rifa_manual_cleanup">
                <input type="hidden" name="rifa_id" value="<?php echo esc_attr($rifa_id); ?>">
                <button type="submit" class="button" onclick="return confirm('¿Liberar todas las reservas expiradas?');">
                    Liberar Expiradas
                </button>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                style="display:inline-block; margin-left:10px;">
                <input type="hidden" name="action" value="dm_rifa_restore_data">
                <button type="submit" class="button button-primary" style="background: #2c3338;"
                    onclick="return confirm('¿Restaurar las 2 ventas perdidas (Gabriel y Maria)?');">
                    Restaurar Ventas Perdidas 🔄
                </button>
            </form>

            <?php if (isset($_GET['updated'])): ?>
                <div class="updated notice is-dismissible">
                    <p>Reserva actualizada correctamente.</p>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['cleanup_done'])): ?>
                <div class="notice notice-info is-dismissible" style="margin-left:0; margin-right:0;">
                    <p>Se han liberado <?php echo intval($_GET['cleanup_done']); ?> reservas expiradas.</p>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['restored'])): ?>
                <div class="notice notice-success is-dismissible" style="margin-left:0; margin-right:0;">
                    <p>✅ <b>¡Éxito!</b> Las ventas de Gabriel Garavito y Maria Estela han sido restauradas correctamente.</p>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['deleted'])): ?>
                <div class="notice notice-success is-dismissible" style="margin-left:0; margin-right:0;">
                    <p>Reserva eliminada correctamente. Los números han sido liberados.</p>
                </div>
            <?php endif; ?>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <?php
                        // Función helper para generar URLs de ordenamiento
                        $sort_url = function ($column) use ($rifa_id, $filter_status, $filter_forma_pago, $orderby, $order) {
                            $new_order = ($orderby === $column && $order === 'ASC') ? 'DESC' : 'ASC';
                            $url = admin_url('admin.php?page=dm-rifa-compradores&rifa_id=' . $rifa_id . '&orderby=' . $column . '&order=' . $new_order);
                            if ($filter_status)
                                $url .= '&status=' . $filter_status;
                            if ($filter_forma_pago)
                                $url .= '&forma_pago=' . $filter_forma_pago;
                            return $url;
                        };

                        $sort_icon = function ($column) use ($orderby, $order) {
                            if ($orderby !== $column)
                                return ' ↕️';
                            return $order === 'ASC' ? ' ↑' : ' ↓';
                        };
                        ?>
                        <th><a href="<?php echo $sort_url('id'); ?>"
                                style="text-decoration: none; color: inherit;">ID<?php echo $sort_icon('id'); ?></a></th>
                        <th><a href="<?php echo $sort_url('nombre'); ?>"
                                style="text-decoration: none; color: inherit;">Nombre<?php echo $sort_icon('nombre'); ?></a>
                        </th>
                        <th>Email</th>
                        <th>Teléfono</th>
                        <th><a href="<?php echo $sort_url('vendedor'); ?>"
                                style="text-decoration: none; color: inherit; font-weight: bold;">Vendedor<?php echo $sort_icon('vendedor'); ?></a>
                        </th>
                        <th>Números</th>
                        <th><a href="<?php echo $sort_url('total'); ?>"
                                style="text-decoration: none; color: inherit;">Total<?php echo $sort_icon('total'); ?></a></th>
                        <th>Estatus</th>
                        <th>Pago</th>
                        <th>🖨️</th>
                        <th><a href="<?php echo $sort_url('created_at'); ?>"
                                style="text-decoration: none; color: inherit;">Creado<?php echo $sort_icon('created_at'); ?></a>
                        </th>
                        <th>📎</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($res):
                        foreach ($res as $row): ?>
                            <tr>
                                <td><?php echo esc_html($row->id); ?></td>
                                <td><?php echo esc_html($row->nombre); ?></td>
                                <td><?php echo esc_html($row->email); ?></td>
                                <td><?php echo esc_html($row->telefono); ?></td>
                                <td><?php echo esc_html($row->vendedor_nombre ?: '-'); ?></td>
                                <td><?php echo esc_html($row->numeros_csv); ?></td>
                                <td><?php echo esc_html(number_format($row->total, 0, ',', '.')); ?></td>
                                <td>
                                    <?php
                                    $status_bg = '#f0f0f0';
                                    $status_text = $row->status;
                                    if ($row->status === 'pagado') {
                                        $status_bg = '#d9f7d9'; // Verde
                                    } elseif ($row->status === 'reservado') {
                                        $status_bg = '#fff5cc'; // Amarillo
                                    } elseif ($row->status === 'pago parcial') {
                                        $status_bg = '#e3f2fd'; // Azul clarito
                                    } elseif ($row->status === 'parcialmente liberado') {
                                        $status_bg = '#ffccbc'; // Naranja suave
                                    }
                                    ?>
                                    <span
                                        style="padding:3px 8px;border-radius:3px;background:<?php echo $status_bg; ?>; font-weight:500;">
                                        <?php echo esc_html($status_text); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html($row->forma_pago ?: 'transferencia'); ?></td>
                                <td><?php echo intval($row->impreso) ? '✅' : '❌'; ?></td>
                                <td><?php echo esc_html($row->created_at); ?></td>
                                <td>
                                    <?php if (!empty($row->comprobante_url)): ?>
                                        <a href="<?php echo esc_url($row->comprobante_url); ?>" target="_blank" class="button button-small"
                                            title="Ver Comprobante">
                                            <span class="dashicons dashicons-media-document" style="margin-top:4px;"></span>
                                        </a>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 5px;">
                                        <a class="button button-small"
                                            href="<?php echo esc_url(admin_url('admin.php?page=dm-rifa-compradores&rifa_id=' . $rifa_id . '&view=' . $row->id)); ?>">
                                            Gestionar
                                        </a>
                                        <?php if ($row->status === 'reservado'): ?>
                                            <a class="button button-small" style="color: #ed6c02; border-color: #ed6c02;"
                                                href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=dm_rifa_liberar_reserva&id=' . $row->id . '&rifa_id=' . $rifa_id), 'dm_rifa_liberar_reserva'); ?>"
                                                onclick="return confirm('¿Liberar números y marcar como expirada? El registro se mantendrá.');">
                                                Liberar
                                            </a>
                                        <?php endif; ?>
                                        <a class="button button-small" style="color: #d63638; border-color: #d63638;"
                                            href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=dm_rifa_delete_reserva&id=' . $row->id . '&rifa_id=' . $rifa_id), 'dm_rifa_delete_reserva'); ?>"
                                            onclick="return confirm('¿Estás seguro de borrar este comprador? Los números volverán a estar disponibles.');">
                                            Borrar
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                        <tr>
                            <td colspan="12">Sin reservas aún.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function render_reserva_detail($reserva_id, $rifa_id)
    {
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
            $normalized_nums = array_map(array($this, 'pad3'), $numeros_arr);
            $place = implode(',', array_fill(0, count($normalized_nums), '%s'));
            $query = $wpdb->prepare(
                "SELECT numero, estado FROM {$this->tbl_numeros} WHERE rifa_id = %d AND numero IN ($place)",
                array_merge(array($rifa_id), $normalized_nums)
            );
            $results = $wpdb->get_results($query);
            foreach ($results as $r) {
                $numeros_estado[$r->numero] = $r->estado;
            }
        }

        ?>
        <div class="wrap">
            <h1>Gestionar Reserva #<?php echo esc_html($reserva_id); ?></h1>
            <p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=dm-rifa-compradores&rifa_id=' . $rifa_id)); ?>"
                    class="button">
                    ← Volver a Reservas
                </a>
                <a class="button" style="color: #d63638; border-color: #d63638; margin-left: 10px;"
                    href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=dm_rifa_delete_reserva&id=' . $reserva_id . '&rifa_id=' . $rifa_id), 'dm_rifa_delete_reserva'); ?>"
                    onclick="return confirm('¿Estás seguro de borrar esta reserva? Los números volverán a estar disponibles.');">
                    Borrar Reserva
                </a>
            </p>

            <?php
            $vendedores = $wpdb->get_results("SELECT id, nombre FROM {$this->tbl_vendedores} ORDER BY nombre ASC");
            ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data"
                style="margin-top:10px;">
                <?php wp_nonce_field('dm_rifa_update_reserva'); ?>
                <input type="hidden" name="action" value="dm_rifa_update_reserva">
                <input type="hidden" name="reserva_id" value="<?php echo esc_attr($reserva_id); ?>">
                <input type="hidden" name="rifa_id" value="<?php echo esc_attr($rifa_id); ?>">
                <input type="hidden" name="solo_vendedor" value="1">

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div>
                        <p>
                            <label><strong>Nombre del comprador:</strong><br>
                                <input type="text" name="nombre" value="<?php echo esc_attr($reserva->nombre); ?>"
                                    style="width:100%;">
                            </label>
                        </p>
                        <p>
                            <label><strong>Email:</strong><br>
                                <input type="email" name="email" value="<?php echo esc_attr($reserva->email); ?>"
                                    style="width:100%;">
                            </label>
                        </p>
                        <p>
                            <label><strong>Teléfono:</strong><br>
                                <input type="text" name="telefono" value="<?php echo esc_attr($reserva->telefono); ?>"
                                    style="width:100%;">
                            </label>
                        </p>
                    </div>
                    <div>
                        <p>
                            <label><strong>Vendedor Responsable:</strong><br>
                                <select name="vendedor_id" style="width:100%;">
                                    <option value="">-- Sin asignar --</option>
                                    <?php foreach ($vendedores as $v): ?>
                                        <option value="<?php echo intval($v->id); ?>" <?php selected(intval($reserva->vendedor_id), intval($v->id)); ?>>
                                            <?php echo esc_html($v->nombre); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </p>
                        <p>
                            <label><strong>Historial/Comprobante Interno:</strong><br>
                                <input type="file" name="comprobante_archivo" accept="image/*,application/pdf">
                            </label>
                        </p>
                        <p><strong>Total:</strong> $<?php echo esc_html(number_format($reserva->total, 0, ',', '.')); ?></p>
                        <p><strong>Estado global:</strong> <?php echo esc_html($reserva->status); ?></p>
                        <p><strong>Entregada/Impresa:</strong>
                            <?php echo intval($reserva->impreso) ? '<span style="color:green;font-weight:bold;">SÍ ✅</span>' : '<span style="color:red;font-weight:bold;">NO ❌</span>'; ?>
                        </p>
                    </div>
                </div>

                <button type="submit" class="button button-primary" style="margin-top:10px;">Guardar Datos del Comprador e
                    Internos</button>
            </form>

            <p style="margin-top:15px;"><strong>Creado:</strong> <?php echo esc_html($reserva->created_at); ?></p>

            <?php if (!empty($reserva->comprobante_url)): ?>
                <div style="margin-top:15px; border:1px solid #ddd; padding:15px; background:#fff; display: inline-block;">
                    <strong>Comprobante/Archivo adjunto:</strong><br>
                    <?php if (strpos($reserva->comprobante_url, '.pdf') !== false): ?>
                        <a href="<?php echo esc_url($reserva->comprobante_url); ?>" target="_blank" class="button">📄 Ver PDF</a>
                    <?php else: ?>
                        <a href="<?php echo esc_url($reserva->comprobante_url); ?>" target="_blank">
                            <img src="<?php echo esc_url($reserva->comprobante_url); ?>"
                                style="max-width:300px; display:block; margin-top:10px; border:1px solid #eee;">
                        </a>
                        <p style="margin:5px 0 0 0; font-size:11px;"><a href="<?php echo esc_url($reserva->comprobante_url); ?>"
                                target="_blank">Ver a tamaño completo</a></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php
            $boleta_id = intval($rifa->boleta_id);
            $has_bg = false;
            if ($boleta_id > 0) {
                $has_bg = (bool) $wpdb->get_var($wpdb->prepare("SELECT background_id FROM {$this->tbl_boletas} WHERE id = %d", $boleta_id));
            }
            if ($has_bg): ?>
                <p style="margin-top:20px;">
                    <a href="<?php echo esc_url(admin_url('admin-post.php?action=dm_rifa_print_ticket&reserva_id=' . $reserva_id . '&t=' . $reserva->token)); ?>"
                        class="button button-primary" target="_blank">
                        <span class="dashicons dashicons-printer" style="margin-top:4px;"></span> Imprimir Boleta (PDF/JPG)
                    </a>
                    <a href="<?php echo $this->get_whatsapp_ticket_url($reserva, $rifa); ?>" class="button" target="_blank"
                        style="background:#25D366; color:#fff; border-color:#25D366;">
                        <span class="dashicons dashicons-whatsapp" style="margin-top:4px;"></span> Enviar por WhatsApp
                    </a>
                </p>
            <?php else: ?>
                <p class="description" style="color:red;">El diseño de boleta seleccionado no tiene una imagen de fondo. Ve a
                    <b>Diseños de Boleta</b> y sube una imagen para habilitar la impresión.
                </p>
            <?php endif; ?>
        </div>

        <h2>Números de esta reserva</h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('dm_rifa_update_reserva'); ?>
            <input type="hidden" name="action" value="dm_rifa_update_reserva">
            <input type="hidden" name="reserva_id" value="<?php echo esc_attr($reserva_id); ?>">
            <input type="hidden" name="rifa_id" value="<?php echo esc_attr($rifa_id); ?>">

            <div style="margin:10px 0;">
                <button class="button" type="submit" name="cambiar_estado" value="reservado">Marcar seleccionados como
                    Reservado</button>
                <button class="button button-primary" type="submit" name="cambiar_estado" value="pagado"
                    onclick="return dmSelectAllIfNone()">Marcar
                    seleccionados como Pagado</button>
                <button class="button" type="submit" name="cambiar_estado" value="disponible">Liberar seleccionados
                    (Disponible)</button>
            </div>
            <script>
                function dmSelectAllIfNone() {
                    var checkboxes = document.querySelectorAll('.dm-num-checkbox');
                    var checked = Array.from(checkboxes).some(c => c.checked);
                    if (!checked) {
                        if (confirm('No has seleccionado números. ¿Deseas marcar TODOS los números de esta reserva como pagados?')) {
                            checkboxes.forEach(c => c.checked = true);
                            return true;
                        }
                        return false;
                    }
                    return true;
                }
            </script>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th style="width:50px;">
                            <input type="checkbox" id="dm-rifa-checkall" title="Seleccionar todos">
                            <span style="font-size:10px;display:block;cursor:pointer;"
                                onclick="document.getElementById('dm-rifa-checkall').click()">Todos</span>
                        </th>
                        <th>Número</th>
                        <th>Estado actual</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($numeros_arr as $num):
                        $num = $this->pad3(trim($num));
                        if ($num === '')
                            continue;
                        $estado_actual = $numeros_estado[$num] ?? 'desconocido';
                        $bg_color = '#f0f0f0';
                        if ($estado_actual === 'pagado')
                            $bg_color = '#d9f7d9';
                        elseif ($estado_actual === 'reservado')
                            $bg_color = '#fff5cc';
                        elseif ($estado_actual === 'asignado')
                            $bg_color = '#fce4ec'; // Rosa claro para asignado
                        ?>
                        <tr>
                            <td><input type="checkbox" name="numeros[]" class="dm-num-checkbox"
                                    value="<?php echo esc_attr($num); ?>"></td>
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
        <script>
            (function () {
                var checkAll = document.getElementById('dm-rifa-checkall');
                if (checkAll) {
                    checkAll.onclick = function () {
                        var items = document.getElementsByClassName('dm-num-checkbox');
                        for (var i = 0; i < items.length; i++) {
                            items[i].checked = checkAll.checked;
                        }
                    };
                }
            })();
        </script>
        </div>
        <?php
    }

    public function admin_post_update_reserva()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Permisos insuficientes');
        }
        check_admin_referer('dm_rifa_update_reserva');

        global $wpdb;
        $reserva_id = intval($_POST['reserva_id'] ?? 0);
        $rifa_id = intval($_POST['rifa_id'] ?? 0);
        $estado = sanitize_text_field($_POST['cambiar_estado'] ?? '');
        $numeros = array_map('sanitize_text_field', $_POST['numeros'] ?? array());

        if (!$reserva_id || !$rifa_id) {
            wp_redirect(admin_url('admin.php?page=dm-rifa-compradores'));
            exit;
        }

        if (isset($_POST['solo_vendedor'])) {
            $vid = intval($_POST['vendedor_id']);
            $nombre = sanitize_text_field($_POST['nombre'] ?? '');
            $email = sanitize_email($_POST['email'] ?? '');
            $telefono = sanitize_text_field($_POST['telefono'] ?? '');

            $update_data = array(
                'vendedor_id' => $vid,
                'nombre' => $nombre,
                'email' => $email,
                'telefono' => $telefono
            );

            // Manejo de carga de comprobante interno
            if (!empty($_FILES['comprobante_archivo']['name'])) {
                if (!function_exists('wp_handle_upload')) {
                    require_once(ABSPATH . 'wp-admin/includes/file.php');
                }
                $uploaded_file = $_FILES['comprobante_archivo'];
                $upload_overrides = array('test_form' => false);
                $movefile = wp_handle_upload($uploaded_file, $upload_overrides);

                if ($movefile && !isset($movefile['error'])) {
                    $update_data['comprobante_url'] = $movefile['url'];
                }
            }

            // Recalcular estado global de la reserva (por si acaso)
            $reserva = $this->fetch_reserva($reserva_id);
            $update_data['status'] = $this->calcular_estado_reserva($reserva, $rifa_id);

            $wpdb->update($this->tbl_reservas, $update_data, array('id' => $reserva_id));
            // Sincronizar vendedor_id en la tabla de números
            $wpdb->update($this->tbl_numeros, array('vendedor_id' => $vid), array('reserva_id' => $reserva_id));
            wp_redirect(admin_url('admin.php?page=dm-rifa-compradores&rifa_id=' . $rifa_id . '&view=' . $reserva_id . '&updated=1'));
            exit;
        }

        // Obtener la reserva y su rifa_id real
        $reserva = $this->fetch_reserva($reserva_id);
        if (!$reserva) {
            wp_redirect(admin_url('admin.php?page=dm-rifa-compradores'));
            exit;
        }
        $rifa_id = intval($reserva->rifa_id);
        $vendedor_id_val = $reserva->vendedor_id ? intval($reserva->vendedor_id) : 0;

        // Si no se enviaron números, pero la acción es pagar/reservar, asumimos TODOS los de la reserva
        if (empty($numeros) && in_array($estado, array('reservado', 'pagado', 'asignado'))) {
            $numeros = array_filter(array_map('trim', explode(',', $reserva->numeros_csv)));
        }

        if (!in_array($estado, array('disponible', 'reservado', 'pagado', 'asignado')) || empty($numeros)) {
            error_log("DM RIFA ERROR: Invalid state ($estado) or empty numbers for reservation $reserva_id");
            wp_redirect(admin_url('admin.php?page=dm-rifa-compradores&rifa_id=' . $rifa_id . '&view=' . $reserva_id . '&error=1'));
            exit;
        }

        // Normalizar números seleccionados antes de la consulta
        $numeros = array_map(array($this, 'pad3'), $numeros);

        $place = implode(',', array_fill(0, count($numeros), '%s'));

        if ($estado === 'disponible') {
            // Liberar números: quitar reserva_id y vendedor_id
            $wpdb->query($wpdb->prepare(
                "UPDATE {$this->tbl_numeros} SET estado = %s, reserva_id = NULL, vendedor_id = NULL, updated_at = NOW() WHERE rifa_id = %d AND numero IN ($place)",
                array_merge(array($estado, $rifa_id), $numeros)
            ));

            // ── RECALCULAR numeros_csv y total EN LA RESERVA ─────────────────
            // Obtener todos los números que AÚN siguen vinculados a esta reserva
            $numeros_restantes = $wpdb->get_col($wpdb->prepare(
                "SELECT numero FROM {$this->tbl_numeros}
                 WHERE reserva_id = %d AND rifa_id = %d AND estado != 'disponible'
                 ORDER BY numero ASC",
                $reserva_id,
                $rifa_id
            ));

            // Obtener precio unitario desde la rifa
            $precio_unit = intval($wpdb->get_var($wpdb->prepare(
                "SELECT precio FROM {$this->tbl_rifas} WHERE id = %d",
                $rifa_id
            )));

            $nuevo_csv = implode(',', $numeros_restantes);
            $nuevo_total = $precio_unit * count($numeros_restantes);

            $wpdb->update(
                $this->tbl_reservas,
                array(
                    'numeros_csv' => $nuevo_csv,
                    'total' => $nuevo_total,
                ),
                array('id' => $reserva_id)
            );
            // ─────────────────────────────────────────────────────────────────

        } else {
            // Reservar, pagar o asignar: mantener/establecer reserva_id y vendedor_id
            $wpdb->query($wpdb->prepare(
                "UPDATE {$this->tbl_numeros} SET estado = %s, reserva_id = %d, vendedor_id = %d, updated_at = NOW() WHERE rifa_id = %d AND numero IN ($place)",
                array_merge(array($estado, $reserva_id, $vendedor_id_val, $rifa_id), $numeros)
            ));
        }

        // Recalcular estado global siempre (usa el numeros_csv actualizado)
        $reserva = $this->fetch_reserva($reserva_id);
        $nuevo_status = $this->calcular_estado_reserva($reserva, $rifa_id);

        $wpdb->update(
            $this->tbl_reservas,
            array('status' => $nuevo_status),
            array('id' => $reserva_id)
        );

        wp_redirect(admin_url('admin.php?page=dm-rifa-compradores&rifa_id=' . $rifa_id . '&view=' . $reserva_id . '&updated=1'));
        exit;
    }

    public function admin_post_liberar_reserva()
    {
        if (!current_user_can('manage_options')) {
            wp_die('No tienes permisos.');
        }
        $reserva_id = intval($_GET['id'] ?? 0);
        $rifa_id = intval($_GET['rifa_id'] ?? 0);

        check_admin_referer('dm_rifa_liberar_reserva');

        if ($reserva_id > 0) {
            global $wpdb;
            // 1. Liberar números (solo los que están en estado 'reservado')
            $wpdb->update(
                $this->tbl_numeros,
                array(
                    'estado' => 'disponible',
                    'reserva_id' => null,
                    'updated_at' => current_time('mysql')
                ),
                array('reserva_id' => $reserva_id, 'estado' => 'reservado')
            );
            // 2. Limpiar numeros_csv y total de la reserva para que no cuente en reportes
            $wpdb->update(
                $this->tbl_reservas,
                array(
                    'status' => 'expirado',
                    'numeros_csv' => '',
                    'total' => 0,
                ),
                array('id' => $reserva_id)
            );
        }

        wp_redirect(admin_url('admin.php?page=dm-rifa-compradores&rifa_id=' . $rifa_id . '&updated=1'));
        exit;
    }

    public function admin_post_delete_reserva()
    {
        if (!current_user_can('manage_options')) {
            wp_die('No tienes permisos.');
        }
        $reserva_id = intval($_GET['id'] ?? 0);
        $rifa_id = intval($_GET['rifa_id'] ?? 0);

        check_admin_referer('dm_rifa_delete_reserva');

        if ($reserva_id > 0) {
            global $wpdb;
            // 1. Liberar números
            $wpdb->update(
                $this->tbl_numeros,
                array(
                    'estado' => 'disponible',
                    'reserva_id' => null,
                    'vendedor_id' => null,
                    'updated_at' => current_time('mysql')
                ),
                array('reserva_id' => $reserva_id)
            );
            // 2. Borrar reserva
            $wpdb->delete($this->tbl_reservas, array('id' => $reserva_id));
        }

        wp_redirect(admin_url('admin.php?page=dm-rifa-compradores&rifa_id=' . $rifa_id . '&deleted=1'));
        exit;
    }

    public function admin_post_export_csv()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Acceso denegado');
        }
        check_admin_referer('dm_rifa_export_csv');
        $rifa_id = intval($_GET['rifa_id']);
        if (!$rifa_id) {
            wp_die('ID de rifa inválido');
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=compradores_rifa_' . $rifa_id . '.csv');
        $output = fopen('php://output', 'w');
        fputcsv($output, array('ID', 'Nombre', 'Email', 'Telefono', 'Numeros', 'Total', 'Status', 'Fecha'));

        global $wpdb;
        $res = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->tbl_reservas} WHERE rifa_id = %d ORDER BY id DESC", $rifa_id), ARRAY_A);
        foreach ($res as $row) {
            fputcsv($output, array($row['id'], $row['nombre'], $row['email'], $row['telefono'], $row['numeros_csv'], $row['total'], $row['status'], $row['created_at']));
        }
        fclose($output);
        exit;
    }

    public function admin_post_export_report()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Acceso denegado');
        }
        check_admin_referer('dm_rifa_export_report');
        global $wpdb;

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=reporte_general_vendedores.csv');

        $output = fopen('php://output', 'w');

        // BOM para compatibilidad con Excel
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // Encabezados
        fputcsv($output, [
            'Vendedor',
            'Boletas Vendidas',
            'Boletas Reservadas',
            'Total Recaudado',
            'Monto en Reservas',
            'Dinero Entregado (Arqueos)',
            'Saldo Pendiente'
        ]);

        // 1. Obtener todos los vendedores
        $vendedores = $wpdb->get_results("SELECT id, nombre FROM {$this->tbl_vendedores} ORDER BY nombre ASC");

        if (!$vendedores) {
            fclose($output);
            exit;
        }

        // 2. Obtener conteos globales con queries simples (igual que page_vendedores)
        $ventas_raw = $wpdb->get_results("
            SELECT r.vendedor_id, COUNT(*) as total
            FROM {$this->tbl_numeros} n
            JOIN {$this->tbl_reservas} r ON n.reserva_id = r.id
            WHERE n.estado = 'pagado'
            GROUP BY r.vendedor_id
        ", OBJECT_K);

        $reservadas_raw = $wpdb->get_results("
            SELECT r.vendedor_id, COUNT(*) as total
            FROM {$this->tbl_numeros} n
            JOIN {$this->tbl_reservas} r ON n.reserva_id = r.id
            WHERE n.estado = 'reservado'
            GROUP BY r.vendedor_id
        ", OBJECT_K);

        $recaudado_raw = $wpdb->get_results("
            SELECT vendedor_id, SUM(total) as total
            FROM {$this->tbl_reservas}
            WHERE status = 'pagado'
            GROUP BY vendedor_id
        ", OBJECT_K);

        $monto_reservas_raw = $wpdb->get_results("
            SELECT vendedor_id, SUM(total) as total
            FROM {$this->tbl_reservas}
            WHERE status = 'reservado'
            GROUP BY vendedor_id
        ", OBJECT_K);

        $entregado_raw = $wpdb->get_results("
            SELECT vendedor_id, SUM(monto) as total
            FROM {$this->tbl_arqueos}
            GROUP BY vendedor_id
        ", OBJECT_K);

        // 3. Escribir filas
        foreach ($vendedores as $v) {
            $vid = $v->id;
            $vendidas = isset($ventas_raw[$vid]) ? intval($ventas_raw[$vid]->total) : 0;
            $reservadas = isset($reservadas_raw[$vid]) ? intval($reservadas_raw[$vid]->total) : 0;
            $recaudado = isset($recaudado_raw[$vid]) ? floatval($recaudado_raw[$vid]->total) : 0;
            $monto_reservas = isset($monto_reservas_raw[$vid]) ? floatval($monto_reservas_raw[$vid]->total) : 0;
            $entregado = isset($entregado_raw[$vid]) ? floatval($entregado_raw[$vid]->total) : 0;
            $saldo_pendiente = $recaudado - $entregado;

            // Omitir vendedores sin ninguna actividad
            if ($vendidas === 0 && $reservadas === 0 && $recaudado == 0)
                continue;

            fputcsv($output, [
                $v->nombre,
                $vendidas,
                $reservadas,
                '$' . number_format($recaudado, 0, ',', '.'),
                '$' . number_format($monto_reservas, 0, ',', '.'),
                '$' . number_format($entregado, 0, ',', '.'),
                '$' . number_format($saldo_pendiente, 0, ',', '.')
            ]);
        }

        // Fila de TOTALES — iterar de nuevo para no guardar arrays grandes
        $t_vendidas = $t_reservadas = $t_recaudado = $t_reservas = $t_entregado = 0;
        foreach ($vendedores as $v) {
            $vid = $v->id;
            $t_vendidas += isset($ventas_raw[$vid]) ? intval($ventas_raw[$vid]->total) : 0;
            $t_reservadas += isset($reservadas_raw[$vid]) ? intval($reservadas_raw[$vid]->total) : 0;
            $t_recaudado += isset($recaudado_raw[$vid]) ? floatval($recaudado_raw[$vid]->total) : 0;
            $t_reservas += isset($monto_reservas_raw[$vid]) ? floatval($monto_reservas_raw[$vid]->total) : 0;
            $t_entregado += isset($entregado_raw[$vid]) ? floatval($entregado_raw[$vid]->total) : 0;
        }

        // Separador y fila de totales
        fputcsv($output, []);
        fputcsv($output, [
            'TOTAL GENERAL',
            $t_vendidas,
            $t_reservadas,
            '$' . number_format($t_recaudado, 0, ',', '.'),
            '$' . number_format($t_reservas, 0, ',', '.'),
            '$' . number_format($t_entregado, 0, ',', '.'),
            '$' . number_format($t_recaudado - $t_entregado, 0, ',', '.')
        ]);

        // Marca de tiempo de generación
        fputcsv($output, []);
        fputcsv($output, ['Generado el', date('d/m/Y H:i:s')]);

        fclose($output);
        exit;
    }

    public function admin_post_export_vendedor()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Acceso denegado');
        }
        check_admin_referer('dm_rifa_export_vendedor');
        global $wpdb;

        $vendedor_id = intval($_GET['vendedor_id'] ?? 0);
        $rifa_id = intval($_GET['rifa_id'] ?? 0);

        if (!$vendedor_id) {
            wp_die('Vendedor no especificado');
        }

        $vendedor = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tbl_vendedores} WHERE id = %d", $vendedor_id));
        if (!$vendedor) {
            wp_die('Vendedor no encontrado');
        }

        // Preparar filtros
        $where_stats = "WHERE r.vendedor_id = %d";
        $where_simple = "WHERE vendedor_id = %d";

        if ($rifa_id > 0) {
            $where_stats .= " AND r.rifa_id = %d";
            $where_simple .= " AND rifa_id = %d";
        }

        $sub_params = ($rifa_id > 0) ? [$vendedor_id, $rifa_id] : [$vendedor_id];

        // Obtener estadísticas
        $stats = new stdClass();
        $stats->pagadas = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->tbl_numeros} n 
             JOIN {$this->tbl_reservas} r ON n.reserva_id = r.id 
             $where_stats AND n.estado = 'pagado'",
            ...$sub_params
        )) ?: 0;

        $stats->reservadas = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->tbl_numeros} n 
             JOIN {$this->tbl_reservas} r ON n.reserva_id = r.id 
             $where_stats AND n.estado = 'reservado'",
            ...$sub_params
        )) ?: 0;

        $stats->total_recaudado = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(total) FROM {$this->tbl_reservas} 
             $where_simple AND status = 'pagado'",
            ...$sub_params
        )) ?: 0;

        $stats->monto_reservado = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(total) FROM {$this->tbl_reservas} 
             $where_simple AND status = 'reservado'",
            ...$sub_params
        )) ?: 0;

        $stats->total_entregado = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(monto) FROM {$this->tbl_arqueos} 
             $where_simple",
            ...$sub_params
        )) ?: 0;

        // Obtener reservas
        $reservas = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->tbl_reservas} $where_simple ORDER BY created_at DESC",
            ...$sub_params
        ));

        // Generar CSV
        $filename = 'reporte_' . sanitize_file_name($vendedor->nombre) . '_' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);

        $output = fopen('php://output', 'w');

        // BOM para UTF-8
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // Resumen del vendedor
        fputcsv($output, array('REPORTE DE VENTAS - ' . strtoupper($vendedor->nombre)));
        fputcsv($output, array('Fecha:', date('d/m/Y H:i')));
        fputcsv($output, array(''));

        // Métricas principales
        fputcsv($output, array('RESUMEN DE VENTAS'));
        fputcsv($output, array('Boletas Vendidas:', $stats->pagadas));
        fputcsv($output, array('Boletas Reservadas:', $stats->reservadas));
        fputcsv($output, array('Total Recaudado:', '$' . number_format($stats->total_recaudado, 0, ',', '.')));
        fputcsv($output, array('Monto en Reservas:', '$' . number_format($stats->monto_reservado, 0, ',', '.')));
        fputcsv($output, array('Dinero Entregado:', '$' . number_format($stats->total_entregado, 0, ',', '.')));
        fputcsv($output, array('Saldo Pendiente:', '$' . number_format($stats->total_recaudado - $stats->total_entregado, 0, ',', '.')));
        fputcsv($output, array(''));

        // Detalle de transacciones
        fputcsv($output, array('DETALLE DE TRANSACCIONES'));
        fputcsv($output, array('Fecha', 'Comprador', 'Teléfono', 'Números', 'Total', 'Estado', 'Método de Pago'));

        foreach ($reservas as $res) {
            fputcsv($output, array(
                date('d/m/Y H:i', strtotime($res->created_at)),
                $res->nombre,
                $res->telefono,
                $res->numeros_csv,
                '$' . number_format($res->total, 0, ',', '.'),
                strtoupper($res->status),
                strtoupper($res->forma_pago ?: 'transferencia')
            ));
        }

        fclose($output);
        exit;
    }


    public function page_reportes()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        global $wpdb;

        $rifa_id = $this->get_context_rifa_id();
        $fecha_inicio = sanitize_text_field($_GET['fecha_inicio'] ?? '');
        $fecha_fin = sanitize_text_field($_GET['fecha_fin'] ?? '');

        $rifas = $wpdb->get_results("SELECT id, nombre, modo_venta FROM {$this->tbl_rifas} ORDER BY id DESC");

        $is_virtual = false;
        $has_mixto_global = $wpdb->get_var("SELECT COUNT(*) FROM {$this->tbl_rifas} WHERE modo_venta = 'mixto' AND activo = 1");

        if ($rifa_id > 0) {
            foreach ($rifas as $r) {
                if ($r->id == $rifa_id && $r->modo_venta === 'virtual') {
                    $is_virtual = true;
                    break;
                }
            }
        } elseif ($has_mixto_global == 0) {
            // Si no hay rifas mixtas activas y estamos viendo "Todas", tratamos como virtual
            $is_virtual = true;
        }

        // Preparar filtros
        $where_rifa_simple = $rifa_id ? $wpdb->prepare("AND rifa_id = %d", $rifa_id) : "";
        $where_rifa_join = $rifa_id ? $wpdb->prepare("AND r.rifa_id = %d", $rifa_id) : "";
        $where_dates_simple = "";
        $where_dates_join = "";

        if ($fecha_inicio) {
            $where_dates_simple .= $wpdb->prepare(" AND created_at >= %s", $fecha_inicio . ' 00:00:00');
            $where_dates_join .= $wpdb->prepare(" AND r.created_at >= %s", $fecha_inicio . ' 00:00:00');
        }
        if ($fecha_fin) {
            $where_dates_simple .= $wpdb->prepare(" AND created_at <= %s", $fecha_fin . ' 23:59:59');
            $where_dates_join .= $wpdb->prepare(" AND r.created_at <= %s", $fecha_fin . ' 23:59:59');
        }

        $query = "
            SELECT 
                v.id, 
                v.nombre,
                (SELECT COUNT(*) FROM {$this->tbl_numeros} n 
                 JOIN {$this->tbl_reservas} r ON n.reserva_id = r.id
                 WHERE r.vendedor_id = v.id AND n.estado = 'pagado' $where_rifa_join $where_dates_join) as vendidas,
                (SELECT COUNT(*) FROM {$this->tbl_numeros} n 
                 JOIN {$this->tbl_reservas} r ON n.reserva_id = r.id
                 WHERE r.vendedor_id = v.id AND n.estado = 'reservado' $where_rifa_join $where_dates_join) as reservadas,
                (SELECT SUM(total) FROM {$this->tbl_reservas} 
                 WHERE vendedor_id = v.id AND status = 'pagado' $where_rifa_simple $where_dates_simple) as total_recaudado,
                (SELECT SUM(total) FROM {$this->tbl_reservas} 
                 WHERE vendedor_id = v.id AND status = 'reservado' $where_rifa_simple $where_dates_simple) as monto_reservas,
                (SELECT SUM(monto) FROM {$this->tbl_arqueos} 
                 WHERE vendedor_id = v.id $where_rifa_simple $where_dates_simple) as dinero_entregado
            FROM {$this->tbl_vendedores} v
            GROUP BY v.id
            ORDER BY vendidas DESC
        ";

        $vendedores = $wpdb->get_results($query);
        $export_url = wp_nonce_url(admin_url('admin-post.php?action=dm_rifa_export_report&rifa_id=' . $rifa_id . '&fecha_inicio=' . $fecha_inicio . '&fecha_fin=' . $fecha_fin), 'dm_rifa_export_report');

        ?>
        <div class="wrap">
            <h1>Reporte de Ventas por Vendedor</h1>
            <p>Resumen detallado del rendimiento y recaudación de cada vendedor.</p>

            <form method="get"
                style="margin:20px 0; background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; display: flex; align-items: flex-end; gap: 15px; flex-wrap: wrap;">
                <input type="hidden" name="page" value="dm-rifa-reportes">

                <div>
                    <label style="display: block; margin-bottom: 5px;"><strong>Seleccionar Rifa:</strong></label>
                    <select name="rifa_id" style="min-width: 200px;">
                        <option value="0">-- Todas las rifas --</option>
                        <?php foreach ($rifas as $r): ?>
                            <option value="<?php echo $r->id; ?>" <?php selected($rifa_id, $r->id); ?>>
                                <?php echo esc_html($r->nombre); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label style="display: block; margin-bottom: 5px;"><strong>Desde:</strong></label>
                    <input type="date" name="fecha_inicio" value="<?php echo esc_attr($fecha_inicio); ?>">
                </div>

                <div>
                    <label style="display: block; margin-bottom: 5px;"><strong>Hasta:</strong></label>
                    <input type="date" name="fecha_fin" value="<?php echo esc_attr($fecha_fin); ?>">
                </div>

                <div style="flex-grow: 1;">
                    <button type="submit" class="button button-primary" style="height: 30px;">Filtrar Reporte</button>
                    <a href="admin.php?page=dm-rifa-reportes" class="button button-secondary" style="height: 30px;">Limpiar</a>
                </div>

                <div>
                    <a href="<?php echo $export_url; ?>" class="button button-primary"
                        style="background: #2271b1; border-color: #2271b1; height: 30px;">Exportar CSV</a>
                </div>
            </form>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Vendedor</th>
                        <th style="text-align: right;">Boletas Vendidas</th>
                        <?php if (!$is_virtual): ?>
                            <th style="text-align: right;">Recaudo Efectivo</th>
                            <th style="text-align: right;">Recaudo Transferencia</th>
                        <?php endif; ?>
                        <th style="text-align: right;">Total Recaudado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $total_global_boletas = 0;
                    $total_global_efectivo = 0;
                    $total_global_transferencia = 0;

                    if ($vendedores):
                        foreach ($vendedores as $v):
                            $row_total = ($v->efectivo ?: 0) + ($v->transferencia ?: 0);
                            $total_global_boletas += $v->boletas;
                            $total_global_efectivo += ($v->efectivo ?: 0);
                            $total_global_transferencia += ($v->transferencia ?: 0);

                            if ($v->boletas == 0 && $row_total == 0)
                                continue; // No mostrar si no tiene nada
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html($v->nombre); ?></strong></td>
                                <td style="text-align: right;"><?php echo intval($v->boletas); ?></td>
                                <?php if (!$is_virtual): ?>
                                    <td style="text-align: right;">$<?php echo number_format($v->efectivo ?: 0, 0, ',', '.'); ?>
                                    </td>
                                    <td style="text-align: right;">
                                        $<?php echo number_format($v->transferencia ?: 0, 0, ',', '.'); ?></td>
                                <?php endif; ?>
                                <td style="text-align: right; font-weight: bold; background: #f9f9f9;">
                                    $<?php echo number_format($row_total, 0, ',', '.'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr style="background: #f0f0f1; font-weight: bold;">
                            <td>TOTAL GENERAL</td>
                            <td style="text-align: right;"><?php echo $total_global_boletas; ?></td>
                            <?php if (!$is_virtual): ?>
                                <td style="text-align: right;">$<?php echo number_format($total_global_efectivo, 0, ',', '.'); ?>
                                </td>
                                <td style="text-align: right;">
                                    $<?php echo number_format($total_global_transferencia, 0, ',', '.'); ?></td>
                            <?php endif; ?>
                            <td style="text-align: right;">
                                $<?php echo number_format(($total_global_efectivo + $total_global_transferencia), 0, ',', '.'); ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?php echo $is_virtual ? 3 : 5; ?>">No hay datos de ventas registrados.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /* ---------------------- Shortcode: Selector ---------------------- */
    public function sc_rifa_selector($atts)
    {
        global $wpdb;
        $atts = shortcode_atts(array('id' => 0), $atts);
        $rifa_id = intval($atts['id']);
        if (!$rifa_id)
            return '<div class="dm-rifa-error">Falta el atributo id en el shortcode.</div>';
        $rifa = $this->fetch_rifa($rifa_id);
        if (!$rifa)
            return '<div class="dm-rifa-error">Rifa no encontrada.</div>';

        if (!$rifa->activo && !current_user_can('manage_options')) {
            return '<div class="dm-rifa-error">Esta rifa no está disponible actualmente.</div>';
        }

        $nums = $wpdb->get_results($wpdb->prepare("SELECT numero, estado FROM {$this->tbl_numeros} WHERE rifa_id = %d ORDER BY numero ASC", $rifa_id));
        $map = array();
        foreach ($nums as $n) {
            $map[$n->numero] = $n->estado;
        }

        ob_start();
        ?>
        <div class="dm-rifa-wrap" data-rifa="<?php echo esc_attr($rifa_id); ?>"
            data-precio="<?php echo esc_attr($rifa->precio); ?>">
            <div class="dm-rifa-controls">
                <input type="text" class="dm-buscar" placeholder="Buscar número (ej. 023)">
                <select class="dm-filtro-estado">
                    <option value="">Todos</option>
                    <option value="disponible">Disponibles</option>
                    <option value="reservado">Reservados</option>
                    <option value="pagado">Pagados</option>
                    <?php if ($rifa->modo_venta !== 'virtual'): ?>
                        <option value="asignado">Venta Física (Asignados)</option>
                    <?php endif; ?>
                </select>
                <div class="dm-contador"><strong>Seleccionaste 0</strong> – Total: $0</div>
            </div>

            <div class="dm-convenciones">
                <div class="dm-convencion-item">
                    <span class="dm-convencion-color"
                        style="background: var(--rifa-status-available); border: 1px solid var(--rifa-status-available-border);"></span>
                    <span>Disponible</span>
                </div>
                <div class="dm-convencion-item">
                    <span class="dm-convencion-color" style="background: var(--rifa-primary);"></span>
                    <span>Tu Selección</span>
                </div>
                <div class="dm-convencion-item">
                    <span class="dm-convencion-color"
                        style="background: var(--rifa-status-reserved); border: 1px solid var(--rifa-status-reserved-border);"></span>
                    <span>Reservado</span>
                </div>
                <div class="dm-convencion-item">
                    <span class="dm-convencion-color"
                        style="background: var(--rifa-status-paid); border: 1px solid var(--rifa-status-paid-border);"></span>
                    <span>Pagado / No disponible</span>
                </div>
                <?php if ($rifa->modo_venta !== 'virtual'): ?>
                    <div class="dm-convencion-item">
                        <span class="dm-convencion-color"
                            style="background: var(--rifa-status-assigned); border: 1px solid var(--rifa-status-assigned-border);"></span>
                        <span>Venta Física</span>
                    </div>
                <?php endif; ?>
            </div>

            <div class="dm-grid" role="grid" aria-label="Números de la rifa"></div>

            <div class="dm-form">
                <h3>Datos del comprador</h3>
                <?php
                $vendedores = $wpdb->get_results("SELECT id, nombre FROM {$this->tbl_vendedores} ORDER BY nombre ASC");
                if ($vendedores):
                    ?>
                    <label>Vendedor responsable<br>
                        <select class="dm-vendedor">
                            <option value="">(Elegir vendedor)</option>
                            <?php foreach ($vendedores as $v): ?>
                                <option value="<?php echo intval($v->id); ?>"><?php echo esc_html($v->nombre); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endif; ?>
                <label>Nombre<br><input type="text" class="dm-nombre"></label>
                <label>Email (opcional)<br><input type="email" class="dm-email"></label>
                <label>Teléfono<br><input type="tel" class="dm-telefono"></label>

                <label>Forma de pago<br>
                    <label style="display: block; margin: 5px 0;">
                        <input type="radio" name="dm-forma-pago" class="dm-forma-pago" value="transferencia" checked>
                        Transferencia Nequi/Daviplata/BBVA
                    </label>
                    <label style="display: block; margin: 5px 0;">
                        <input type="radio" name="dm-forma-pago" class="dm-forma-pago" value="efectivo">
                        Efectivo
                    </label>
                </label>


                <button type="button" class="button button-primary dm-continuar">Continuar</button>
                <div class="dm-msg" aria-live="polite"></div>
            </div>
        </div>
        <script>
            window.DM_RIFA_STATE = window.DM_RIFA_STATE || {};
            window.DM_RIFA_STATE[<?php echo intval($rifa_id); ?>] = <?php echo wp_json_encode($map); ?>;
            window.DM_RIFA_VERSION = "<?php echo time(); ?>";
        </script>
        <?php
        return ob_get_clean();
    }

    /* ---------------------- Shortcode: Confirmación ---------------------- */
    public function sc_rifa_confirm($atts)
    {
        global $wpdb;
        $rifa_id = intval($_GET['rifa'] ?? 0);
        $token = sanitize_text_field($_GET['t'] ?? '');

        if (!$rifa_id || $token === '') {
            return '<div class="dm-rifa-error">Faltan parámetros de confirmación.</div>';
        }
        $rifa = $this->fetch_rifa($rifa_id);
        $res = $this->fetch_reserva_by_token($token);
        if (!$rifa || !$res || intval($res->rifa_id) !== $rifa_id) {
            return '<div class="dm-rifa-error">No se encontró la reserva.</div>';
        }

        $vendedor_nombre = '(No asignado)';
        $vendedor_telefono = '';
        if (intval($res->vendedor_id) > 0) {
            $vendedor = $wpdb->get_row($wpdb->prepare("SELECT nombre, telefono FROM {$this->tbl_vendedores} WHERE id = %d", intval($res->vendedor_id)));
            if ($vendedor) {
                $vendedor_nombre = $vendedor->nombre;
                $vendedor_telefono = $vendedor->telefono;
            }
        }

        $nums = esc_html($res->numeros_csv);
        $total = number_format(intval($res->total), 0, ',', '.');

        // Teléfonos limpios
        $wa_admin = preg_replace('/[^0-9]/', '', (string) $rifa->wa_e164);
        $wa_comprador = preg_replace('/[^0-9]/', '', (string) $res->telefono);

        // -- MENSAJE PARA EL COMPRADOR (Recibo) --
        $msg_comprador_raw = "Hola *" . esc_attr($res->nombre) . "*! 🎟️ Aquí tienes el resumen de tu reserva:\n\n" .
            "🔢 *Números:* {$nums}\n" .
            "💰 *Total:* $" . $total . "\n" .
            "👤 *Vendedor:* {$vendedor_nombre}\n" .
            "💳 *Forma de pago:* " . ucfirst($res->forma_pago ?? 'transferencia') . "\n\n" .
            "Por favor realiza el pago y envía el comprobante para confirmar tus números. ¡Gracias!";
        $wa_url_comprador = "https://wa.me/{$wa_comprador}?text=" . rawurlencode($msg_comprador_raw);

        // -- MENSAJE PARA EL ADMINISTRADOR (Reporte de Pago) --
        $msg_admin_raw = "Hola Administrador! He realizado una reserva de la rifa:\n\n" .
            "👤 *Cliente:* {$res->nombre}\n" .
            "🔢 *Números:* {$nums}\n" .
            "💰 *Total:* $" . $total . "\n" .
            "👤 *Vendedor:* {$vendedor_nombre}\n\n" .
            "Adjunto mi comprobante de pago (" . ucfirst($res->forma_pago ?? 'transferencia') . ").";
        $wa_url_admin = "https://wa.me/{$wa_admin}?text=" . rawurlencode($msg_admin_raw);

        ob_start(); ?>
        <div class="dm-rifa-confirm">
            <div style="text-align: center; margin-bottom: 20px;">
                <span class="dashicons dashicons-yes-alt"
                    style="color: #4caf50; font-size: 60px; width: 60px; height: 60px;"></span>
                <h2 style="margin-top: 10px;">¡Reserva Exitosa!</h2>
                <p>Tus números han sido apartados temporalmente.</p>
            </div>

            <div class="dm-confirm-box"
                style="background: #f9f9f9; border: 1px solid #ddd; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                <p><strong>Comprador:</strong> <?php echo esc_html($res->nombre); ?><br>
                    <strong>Teléfono:</strong> <?php echo esc_html($res->telefono); ?><br>
                    <strong>Vendedor:</strong> <?php echo esc_html($vendedor_nombre); ?><br>
                    <strong>Forma de pago:</strong> <?php echo esc_html(ucfirst($res->forma_pago ?? 'transferencia')); ?>
                </p>
                <p style="font-size: 1.2em;"><strong>Números:</strong> <span
                        style="color: var(--rifa-primary); font-weight: bold;"><?php echo $nums; ?></span><br>
                    <strong>Total a pagar:</strong> <span
                        style="font-size: 1.3em; font-weight: bold;">$<?php echo $total; ?></span>
                </p>
            </div>

            <div class="dm-confirm-actions" style="display: flex; flex-direction: column; gap: 10px;">
                <?php
                $boleta_id = intval($rifa->boleta_id);
                $has_bg = false;
                if ($boleta_id > 0) {
                    $has_bg = (bool) $wpdb->get_var($wpdb->prepare("SELECT background_id FROM {$this->tbl_boletas} WHERE id = %d", $boleta_id));
                }
                if ($res->status === 'pagado' && $has_bg): ?>
                    <?php
                    $ticket_url = admin_url('admin-post.php?action=dm_rifa_print_ticket&reserva_id=' . $res->id . '&t=' . $res->token);
                    ?>
                    <button type="button" class="button button-primary"
                        style="background: #2271b1; padding: 12px; height: auto; font-size: 16px; margin-bottom:10px;"
                        onclick="window.open('<?php echo esc_url($ticket_url); ?>', '_blank')">
                        <span class="dashicons dashicons-download" style="margin-top: 2px;"></span>
                        Descargar Mis Boletas (Digital)
                    </button>
                <?php endif; ?>

                <button type="button" class="button button-primary"
                    style="background: #25D366; border-color: #25D366; padding: 12px; height: auto; font-size: 16px;"
                    onclick="window.open('<?php echo esc_url($wa_url_admin); ?>', '_blank')">
                    <span class="dashicons dashicons-whatsapp" style="margin-top: 2px;"></span>
                    Enviar comprobante al Administrador
                </button>

                <button type="button" class="button button-secondary" style="padding: 10px; height: auto; font-size: 14px;"
                    onclick="window.open('<?php echo esc_url($wa_url_comprador); ?>', '_blank')">
                    <span class="dashicons dashicons-share" style="margin-top: 2px;"></span>
                    Enviar resumen al Comprador
                </button>
            </div>

            <p style="margin-top: 20px; font-size: 0.9em; color: #666; text-align: center;">
                Recuerda que tienes 24 horas para confirmar tu pago, de lo contrario los números volverán a estar disponibles.
            </p>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ---------------------- AJAX: Reservar ---------------------- */
    public function ajax_reservar()
    {
        check_ajax_referer('dm_rifa_nonce', 'nonce');
        global $wpdb;
        $this->ensure_reservas_columns();

        $rifa_id = intval($_POST['rifa_id'] ?? 0);
        $nlist = $_POST['numeros'] ?? array();
        $nombre = sanitize_text_field($_POST['nombre'] ?? '');
        $email = sanitize_text_field($_POST['email'] ?? '');
        $tel = sanitize_text_field($_POST['telefono'] ?? '');
        $vendedor_id = intval($_POST['vendedor_id'] ?? 0);
        $forma_pago = sanitize_text_field($_POST['forma_pago'] ?? 'transferencia');

        if (!$rifa_id || empty($nlist) || !$nombre || !$tel) {
            wp_send_json_error(array('message' => 'Datos incompletos'));
        }
        $rifa = $this->fetch_rifa($rifa_id);
        if (!$rifa) {
            wp_send_json_error(array('message' => 'Rifa no encontrada'));
        }

        if (!$rifa->activo) {
            wp_send_json_error(array('message' => 'Esta rifa está temporalmente pausada.'));
        }

        // Normalizar números seleccionados
        $nums = array();
        foreach ($nlist as $v) {
            $v = trim($v);
            if ($v !== '') {
                // Preservar el formato original si es posible, pero limpiar basura
                // Si el sistema usa pad3 por defecto, aseguramos que coincida
                // pero si el número en DB es '01' y enviamos '01', no queremos que se vuelva '001'
                // así que solo limpiamos espacios.
                $nums[] = $v;
            }
        }
        $nums = array_values(array_unique($nums));
        if (empty($nums)) {
            wp_send_json_error(array('message' => 'No se enviaron números válidos'));
        }

        // Verificar disponibilidad REAL en la tabla de números
        $place = implode(',', array_fill(0, count($nums), '%s'));
        $query = $wpdb->prepare(
            "SELECT numero, estado, id FROM {$this->tbl_numeros} WHERE rifa_id = %d AND numero IN ($place)",
            array_merge(array($rifa_id), $nums)
        );
        $rows = $wpdb->get_results($query);

        $found_ids = array();
        $found_states = array();
        foreach ($rows as $r) {
            $found_ids[$r->numero] = $r->id;
            $found_states[$r->numero] = $r->estado;
        }

        $no_disponibles = array();
        foreach ($nums as $n) {
            if (!isset($found_states[$n])) {
                $no_disponibles[] = "$n (No existe)";
            } elseif ($found_states[$n] !== 'disponible') {
                $no_disponibles[] = "$n (" . $found_states[$n] . ")";
            }
        }

        if (!empty($no_disponibles)) {
            wp_send_json_error(array('message' => 'Algunos números ya no están disponibles: ' . implode(', ', $no_disponibles)));
        }

        // Si llegamos aquí, todos existen y están disponibles
        $precio_unit = intval($rifa->precio);
        $total = $precio_unit * count($nums);
        $expires = date('Y-m-d H:i:s', time() + 24 * 3600);
        $token = function_exists('random_bytes') ? bin2hex(random_bytes(16)) : wp_generate_password(32, false);

        // INSERTAR RESERVA
        $inserted = $wpdb->insert($this->tbl_reservas, array(
            'rifa_id' => $rifa_id,
            'nombre' => $nombre,
            'email' => $email,
            'telefono' => $tel,
            'numeros_csv' => implode(', ', $nums),
            'precio_unit' => $precio_unit,
            'total' => $total,
            'status' => 'reservado',
            'created_at' => current_time('mysql'),
            'expires_at' => $expires,
            'token' => $token,
            'vendedor_id' => $vendedor_id,
            'forma_pago' => $forma_pago,
        ));

        if (!$inserted) {
            wp_send_json_error(array('message' => 'Error al crear la reserva en la base de datos.'));
        }

        $reserva_id = intval($wpdb->insert_id);

        // ACTUALIZAR ESTADO DE LOS NÚMEROS
        $ids_to_update = array_values($found_ids);
        $place_ids = implode(',', array_fill(0, count($ids_to_update), '%d'));
        $updated_nums = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->tbl_numeros} SET estado = 'reservado', reserva_id = %d, updated_at = NOW() WHERE id IN ($place_ids)",
            array_merge(array($reserva_id), $ids_to_update)
        ));

        if ($updated_nums === false) {
            error_log("DM RIFA ERROR: Falló actualización de números para Reserva $reserva_id");
            // Intentar mitigar: si falló el update pero la reserva existe,
            // al menos logueamos para que el admin sepa.
        }

        $confirm_url = '';
        if (intval($rifa->gracias_page_id) > 0) {
            $confirm_url = add_query_arg(
                array('rifa' => $rifa_id, 't' => $token),
                get_permalink(intval($rifa->gracias_page_id))
            );
        }

        wp_send_json_success(array(
            'reserva_id' => $reserva_id,
            'confirm_url' => $confirm_url,
        ));
    }
    /* ---------------------- AJAX: Obtener Estados ---------------------- */
    public function ajax_get_states()
    {
        global $wpdb;
        $rifa_id = intval($_GET['rifa_id'] ?? 0);
        if (!$rifa_id) {
            wp_send_json_error('ID no válido');
        }

        $nums = $wpdb->get_results($wpdb->prepare(
            "SELECT numero, estado FROM {$this->tbl_numeros} WHERE rifa_id = %d",
            $rifa_id
        ));

        $map = array();
        foreach ($nums as $n) {
            $map[$n->numero] = $n->estado;
        }

        wp_send_json_success($map);
    }

    /* ---------------------- Cleanup Logic ---------------------- */
    public function cron_cleanup_expired()
    {
        global $wpdb;

        // 1. Buscar todas las reservas en estado 'reservado' que hayan expirado
        $expired_reservas = $wpdb->get_results($wpdb->prepare(
            "SELECT id, rifa_id FROM {$this->tbl_reservas} WHERE status = 'reservado' AND expires_at <= %s",
            current_time('mysql')
        ));

        if (empty($expired_reservas))
            return 0;

        $count = 0;
        foreach ($expired_reservas as $res) {
            // Liberar los números asociados a esta reserva
            $wpdb->query($wpdb->prepare(
                "UPDATE {$this->tbl_numeros} SET estado = 'disponible', reserva_id = NULL, updated_at = NOW() 
                 WHERE reserva_id = %d AND rifa_id = %d AND estado = 'reservado'",
                $res->id,
                $res->rifa_id
            ));

            // Marcar la reserva como expirada
            $wpdb->update(
                $this->tbl_reservas,
                array('status' => 'expirado'),
                array('id' => $res->id)
            );
            $count++;
        }

        return $count;
    }

    public function admin_post_manual_cleanup()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Permisos insuficientes');
        }
        check_admin_referer('dm_rifa_manual_cleanup');
        $rifa_id = intval($_POST['rifa_id'] ?? 0);
        $done = $this->cron_cleanup_expired(); // Changed from $this->liberar_reservas_expiradas($rifa_id);
        wp_redirect(admin_url('admin.php?page=dm-rifa-compradores&rifa_id=' . $rifa_id . '&cleanup_done=' . $done));
        exit;
    }

    public function admin_post_restore_data()
    {
        if (!current_user_can('manage_options')) {
            wp_die('No tienes permisos.');
        }

        global $wpdb;

        // Detectar Rifa Activa
        $rifa_id = $wpdb->get_var("SELECT id FROM {$this->tbl_rifas} WHERE activo = 1 ORDER BY id DESC LIMIT 1");

        if (!$rifa_id) {
            wp_die("No hay rifa activa.");
        }

        $datos = [
            [
                'id' => 181,
                'nombre' => 'Maria Estela Calzada Alonso',
                'email' => 'aroacristela@gmail.com',
                'telefono' => '3108117159',
                'numeros' => ['931'],
                'total' => 20000,
                'created_at' => '2026-02-11 16:03:12',
                'forma_pago' => 'efectivo'
            ],
            [
                'id' => 182,
                'nombre' => 'Gabriel Garavito',
                'email' => 'adrivito@hotmail.com',
                'telefono' => '3118767912',
                'numeros' => ['981'],
                'total' => 20000,
                'created_at' => '2026-02-11 16:04:58',
                'forma_pago' => 'efectivo'
            ]
        ];

        foreach ($datos as $row) {
            $existe = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->tbl_reservas} WHERE id = %d", $row['id']));
            if ($existe)
                continue;

            $wpdb->insert($this->tbl_reservas, [
                'id' => $row['id'],
                'rifa_id' => $rifa_id,
                'nombre' => $row['nombre'],
                'email' => $row['email'],
                'telefono' => $row['telefono'],
                'numeros_csv' => implode(',', $row['numeros']),
                'precio_unit' => $row['total'],
                'total' => $row['total'],
                'status' => 'pagado', // Restaurar como pagados directamente
                'created_at' => $row['created_at'],
                'token' => wp_generate_password(24, false),
                'forma_pago' => $row['forma_pago'],
                'impreso' => 0
            ]);

            foreach ($row['numeros'] as $num) {
                $num_padded = str_pad($num, 3, '0', STR_PAD_LEFT);
                $wpdb->update($this->tbl_numeros, [
                    'estado' => 'pagado',
                    'reserva_id' => $row['id']
                ], [
                    'rifa_id' => $rifa_id,
                    'numero' => $num_padded
                ]);
            }
        }

        wp_redirect(admin_url('admin.php?page=dm-rifa-compradores&restored=1'));
        exit;
    }

    private function draw_text_wrapped($im, $size, $angle, $x, $y, $color, $font, $text, $max_width)
    {
        // Asegurar que haya espacios después de las comas para que explode(' ') funcione
        $text = str_replace(',', ', ', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        $words = explode(' ', trim($text));
        $lines = array();
        $current_line = '';
        foreach ($words as $word) {
            $test_line = $current_line . ($current_line ? ' ' : '') . $word;
            $bbox = imagettfbbox($size, $angle, $font, $test_line);
            $width = $bbox[2] - $bbox[0];
            if ($width > $max_width && $current_line !== '') {
                $lines[] = $current_line;
                $current_line = $word;
            } else {
                $current_line = $test_line;
            }
        }
        if ($current_line !== '')
            $lines[] = $current_line;

        foreach ($lines as $l) {
            imagettftext($im, $size, $angle, $x, $y, $color, $font, $l);
            $y += ($size * 1.4); // Espaciado un poco más ajustado
        }
        return $y;
    }

    /**
     * Motor de generación de boletas (GD)
     */
    public function admin_post_print_ticket()
    {
        error_log("=== DM RIFA VERSION 2.0 - ARCHIVO ACTUALIZADO ===");
        global $wpdb;

        $is_preview = isset($_GET['is_preview']) && $_GET['is_preview'] == 1;

        if ($is_preview) {
            if (!current_user_can('manage_options')) {
                wp_die('Permisos insuficientes para previsualización.');
            }
            $preview_key = sanitize_key($_GET['preview_key']);
            $preview_data = get_transient($preview_key);
            if (!$preview_data) {
                wp_die('Sesión de previsualización expirada o inválida.');
            }
            $background_id = intval($preview_data['background_id']);
            $cfg = $preview_data['ticket_config'];

            // Sample Data for Preview
            $vendedor_nombre = 'VENDEDOR DE PRUEBA';
            $reserva = (object) array(
                'nombre' => 'COMPRADOR DE PRUEBA',
                'numeros_csv' => '042, 115, 230',
                'status' => 'PAGADO',
                'id' => 0,
                'vendedor_id' => 0
            );
            $vendedor_nombre = 'VENDEDOR DE PRUEBA';
            $background_id = intval($preview_data['background_id']);
            $cfg = $preview_data['ticket_config'];
        } else {
            $reserva_id = intval($_GET['reserva_id'] ?? 0);
            $token = sanitize_text_field($_GET['t'] ?? '');

            $reserva = $this->fetch_reserva($reserva_id);
            if (!$reserva)
                wp_die('Reserva no encontrada');

            // Seguridad: Permitir si es admin O si el token coincide
            if (!current_user_can('manage_options')) {
                if ($token === '' || $reserva->token !== $token) {
                    wp_die('Permisos insuficientes o token inválido.');
                }
            }

            $vendedor_nombre = '(No asignado)';
            if (intval($reserva->vendedor_id) > 0) {
                $v = $wpdb->get_var($wpdb->prepare("SELECT nombre FROM {$this->tbl_vendedores} WHERE id = %d", intval($reserva->vendedor_id)));
                if ($v)
                    $vendedor_nombre = $v;
            }

            $rifa = $this->fetch_rifa($reserva->rifa_id);
            if (!$rifa) {
                wp_die('Rifa no encontrada');
            }

            // Fetch Boleta
            $boleta_id = intval($rifa->boleta_id);
            if (!$boleta_id) {
                wp_die('La rifa no tiene un diseño de boleta (Boleta) asignado. Por favor, edita la rifa y selecciona uno.');
            }

            $boleta = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tbl_boletas} WHERE id = %d", $boleta_id));
            if (!$boleta || !$boleta->background_id) {
                wp_die('Diseño de boleta no encontrado o sin imagen de fondo.');
            }

            $background_id = $boleta->background_id;
        }

        if (!$background_id) {
            wp_die('Falta imagen de fondo.');
        }

        $bg_path = get_attached_file($background_id);
        if (!$bg_path || !file_exists($bg_path)) {
            wp_die('Imagen de fondo no encontrada en el servidor.');
        }

        // Crear imagen desde el fondo
        $info = getimagesize($bg_path);
        switch ($info[2]) {
            case IMAGETYPE_JPEG:
                $im = imagecreatefromjpeg($bg_path);
                break;
            case IMAGETYPE_PNG:
                $im = imagecreatefrompng($bg_path);
                break;
            default:
                wp_die('Formato de imagen no soportado (usar JPG o PNG)');
        }

        if (!$im) {
            wp_die('Error al cargar la imagen.');
        }

        // Colores
        $black = imagecolorallocate($im, 0, 0, 0);

        // Exhaustive font search
        $font = '';
        if (function_exists('imagettftext')) {
            $font_paths = array(
                // Local Project - Google Sans Flex (PRIORITY)
                plugin_dir_path(__FILE__) . 'assets/fonts/GoogleSansFlex_72pt-Regular.ttf',
                plugin_dir_path(__FILE__) . 'assets/fonts/Google_Sans_Flex/GoogleSansFlex_72pt-Regular.ttf',
                plugin_dir_path(__FILE__) . 'assets/fonts/GoogleSansFlex-Bold.ttf',
                plugin_dir_path(__FILE__) . 'assets/fonts/GoogleSansFlex-Regular.ttf',
                plugin_dir_path(__FILE__) . 'assets/fonts/Google_Sans_Flex/GoogleSansFlex-Bold.ttf',
                plugin_dir_path(__FILE__) . 'assets/fonts/Google_Sans_Flex/GoogleSansFlex-Regular.ttf',
                plugin_dir_path(__FILE__) . 'assets/fonts/font.ttf',
                // Linux
                '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
                '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
                '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
                '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
                // Mac
                '/System/Library/Fonts/Supplemental/Arial Bold.ttf',
                '/System/Library/Fonts/Supplemental/Arial.ttf',
                '/Library/Fonts/Arial Bold.ttf',
                '/Library/Fonts/Arial.ttf',
                '/System/Library/Fonts/Helvetica.ttc',
                // Windows
                'C:\Windows\Fonts\arialbd.ttf',
                'C:\Windows\Fonts\arial.ttf'
            );
            foreach ($font_paths as $path) {
                if (file_exists($path)) {
                    $font = $path;
                    break;
                }
            }
        }

        $nombre_completo = strtoupper($reserva->nombre);
        $vendedor_txt = strtoupper($vendedor_nombre);
        $numeros = $reserva->numeros_csv;
        $estado = strtoupper($reserva->status);

        if (file_exists($font)) {
            // Valores dinámicos para mejor ajuste
            $max_w = $info[0] - 160; // Margen de 80 a cada lado

            // Ajuste dinámico de punto de partida si hay muchos números
            $count_nums = count(explode(',', $numeros));
            $current_y = 1350; // Empezamos un poco más alto
            if ($count_nums > 30)
                $current_y = 1250;
            if ($count_nums > 60)
                $current_y = 1150;
            if ($count_nums > 100)
                $current_y = 1000;

            error_log("DM RIFA: Renderizando Boleta - Numeros: $count_nums, StartY: $current_y");

            // Estado (arriba izquierda)
            imagettftext($im, 32, 0, 80, $current_y, $black, $font, "ESTADO: " . $estado);

            // Vendedor
            $current_y += 80;
            imagettftext($im, 24, 0, 80, $current_y, $black, $font, "VENDEDOR:");
            $current_y += 50;
            $current_y = $this->draw_text_wrapped($im, 34, 0, 80, $current_y, $black, $font, $vendedor_txt, $max_w);

            // Comprador (nombre del cliente)
            $current_y += 40;
            imagettftext($im, 24, 0, 80, $current_y, $black, $font, "COMPRADOR:");
            $current_y += 60;
            $current_y = $this->draw_text_wrapped($im, 40, 0, 80, $current_y, $black, $font, $nombre_completo, $max_w);

            // Números de boleta
            $current_y += 40;
            imagettftext($im, 24, 0, 80, $current_y, $black, $font, "NÚMEROS:");
            $current_y += 60;

            // Ajuste dinámico de tamaño para números si son demasiados
            $count_nums = count(explode(',', $numeros));
            $fsize_nums = 30;
            if ($count_nums > 20)
                $fsize_nums = 24;
            if ($count_nums > 50)
                $fsize_nums = 18;
            if ($count_nums > 100)
                $fsize_nums = 14;

            $this->draw_text_wrapped($im, $fsize_nums, 0, 80, $current_y, $black, $font, $numeros, $max_w);
        } else {
            error_log("DM RIFA: ⚠️ USANDO FALLBACK - No se encontró fuente TTF");
            // Fallback muy básico
            imagestring($im, 5, 80, 1100, "ESTADO: " . $estado, $black);
            imagestring($im, 5, 80, 1200, "VENDEDOR: " . $vendedor_txt, $black);
            imagestring($im, 5, 80, 1350, "NOMBRE: " . $nombre_completo, $black);
            imagestring($im, 5, 80, 1500, "NUMEROS: " . substr($numeros, 0, 100) . (strlen($numeros) > 100 ? '...' : ''), $black);
        }

        // Marcar como impreso si no es previsualización
        if (!$is_preview) {
            $wpdb->update($this->tbl_reservas, array('impreso' => 1), array('id' => $reserva->id));
        }

        // Salida
        header('Content-Type: image/jpeg');
        header('Content-Disposition: inline; filename="boleta-' . ($is_preview ? 'preview' : $reserva->id) . '.jpg"');
        imagejpeg($im, null, 90);
        imagedestroy($im);
        exit;
    }

    /* ---------------------- Page: Boletas (Ticket Designer) ---------------------- */
    public function page_boletas()
    {
        global $wpdb;

        // Safety check: ensure table exists
        $table_check = $wpdb->get_var("SHOW TABLES LIKE '{$this->tbl_boletas}'");
        if (!$table_check) {
            $this->on_activate();
        }

        $action = isset($_GET['action']) ? $_GET['action'] : 'list';
        $boleta_id = isset($_POST['boleta_id']) ? intval($_POST['boleta_id']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);

        // Handle Save
        if (isset($_POST['dm_save_boleta'])) {
            check_admin_referer('dm_save_boleta_action', '_wpnonce');
            $nombre = sanitize_text_field($_POST['nombre']);
            $background_id = intval($_POST['dm_background_id']);
            $config = isset($_POST['ticket_config']) ? $_POST['ticket_config'] : array();

            // Clean config
            $clean_config = array();
            foreach ($config as $key => $values) {
                $clean_config[$key] = array_map('intval', $values);
            }
            $config_json = wp_json_encode($clean_config);

            if ($boleta_id > 0) {
                $wpdb->update($this->tbl_boletas, array(
                    'nombre' => $nombre,
                    'background_id' => $background_id,
                    'ticket_config' => $config_json
                ), array('id' => $boleta_id));
            } else {
                $wpdb->insert($this->tbl_boletas, array(
                    'nombre' => $nombre,
                    'background_id' => $background_id,
                    'ticket_config' => $config_json
                ));
                $boleta_id = $wpdb->insert_id;
            }

            if ($wpdb->last_error) {
                wp_die("Error al guardar en la base de datos: " . $wpdb->last_error);
            }

            $action = 'edit';
            // Redirect to avoid resubmission and update URL with ID
            wp_redirect(admin_url('admin.php?page=dm-rifa-boletas&action=edit&id=' . $boleta_id . '&updated=1'));
            exit;
        }

        if (isset($_GET['updated'])) {
            echo '<div class="updated"><p>Boleta guardada correctamente.</p></div>';
        }

        // Handle Delete
        if ($action === 'delete' && $boleta_id > 0) {
            check_admin_referer('delete-boleta_' . $boleta_id);
            $wpdb->delete($this->tbl_boletas, array('id' => $boleta_id));
            echo '<div class="updated"><p>Boleta eliminada.</p></div>';
            $action = 'list';
        }

        if ($action === 'edit' || $action === 'add') {
            $boleta = null;
            if ($boleta_id > 0) {
                $boleta = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tbl_boletas} WHERE id = %d", $boleta_id));
            }
            $nombre = $boleta ? $boleta->nombre : '';
            $bg_id = $boleta ? $boleta->background_id : 0;
            $config = $boleta ? json_decode($boleta->ticket_config, true) : array();
            ?>
            <div class="wrap">
                <h1><?php echo $boleta_id > 0 ? 'Editar Boleta' : 'Nueva Boleta'; ?></h1>

                <div class="wrap" style="max-width: 900px;">
                    <form method="post" id="dm-boleta-form">
                        <?php wp_nonce_field('dm_save_boleta_action', '_wpnonce'); ?>
                        <input type="hidden" name="boleta_id" value="<?php echo $boleta_id; ?>">

                        <div class="postbox" style="padding: 20px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                            <h2 style="margin-top:0;">Configuración de Boleta</h2>
                            <table class="form-table">
                                <tr>
                                    <th>Nombre del Diseño</th>
                                    <td><input type="text" name="nombre" value="<?php echo esc_attr($nombre); ?>"
                                            class="regular-text" required placeholder="Ej: Diseño Navidad 2025"></td>
                                </tr>
                                <tr>
                                    <th>Imagen de Fondo</th>
                                    <td>
                                        <div id="dm_bg_preview" style="margin-bottom:15px;">
                                            <?php if ($bg_id): ?>
                                                <?php $img = wp_get_attachment_image_src($bg_id, 'full'); ?>
                                                <img src="<?php echo esc_url($img[0]); ?>"
                                                    style="max-width:400px; display:block; border: 1px solid #ccd0d4; border-radius:4px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                                            <?php else: ?>
                                                <div
                                                    style="width:400px; height:150px; background:#f0f0f1; border:1px dashed #ccd0d4; display:flex; align-items:center; justify-content:center; color:#647280;">
                                                    Sin imagen seleccionada</div>
                                            <?php endif; ?>
                                        </div>
                                        <input type="hidden" name="dm_background_id" id="dm_background_id"
                                            value="<?php echo esc_attr($bg_id); ?>">
                                        <button type="button" class="button button-large button-secondary dm-upload-btn"
                                            data-target="#dm_background_id" data-preview="#dm_bg_preview">Subir o Elegir
                                            Fondo</button>
                                        <p class="description">Sube la imagen base (Recomendado 1080x1920px).</p>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <p class="description" style="margin-top:15px;">
                            <strong>Nota:</strong> Los textos se generan automáticamente con tamaños y posiciones optimizadas para
                            la imagen de fondo.
                        </p>
                </div>

                <div style="margin-top:20px; display:flex; gap:10px;">
                    <button type="submit" name="dm_save_boleta" class="button button-primary button-large"
                        style="height:45px; padding:0 30px;">Guardar Cambios</button>
                    <button type="button" id="dm-btn-preview" class="button button-secondary button-large"
                        style="height:45px; padding:0 20px;">Previsualizar como queda</button>
                </div>
                </form>

                <div style="margin-top:40px;">
                    <h2 style="border-bottom: 2px solid #ccd0d4; padding-bottom: 10px;">Previsualización de Boleta</h2>
                    <div id="dm-ticket-preview-container"
                        style="background:#f0f0f1; padding:30px; border:1px solid #ccd0d4; border-radius:8px; text-align:center; min-height:200px; display:flex; align-items:center; justify-content:center; flex-direction:column;">
                        <div class="dm-loading-preview" style="display:none; padding: 20px;">
                            <span class="spinner is-active" style="float:none; margin:0 auto 10px;"></span>
                            <p>Generando vista previa profesional...</p>
                        </div>
                        <img id="dm-preview-img" src=""
                            style="max-width:100%; height:auto; border-radius:4px; box-shadow:0 8px 16px rgba(0,0,0,0.2); display:none;">
                        <p class="preview-hint" style="color:#647280;">Haz clic en "Previsualizar" para ver el resultado aquí.
                        </p>
                    </div>
                </div>
            </div>
            </div>
            <?php
        } else {
            // List view
            $results = $wpdb->get_results("SELECT * FROM {$this->tbl_boletas} ORDER BY id DESC");
            ?>
            <div class="wrap">
                <h1 class="wp-heading-inline">Diseños de Boletas</h1>
                <a href="?page=dm-rifa-boletas&action=add" class="page-title-action">Añadir Nueva</a>
                <hr class="wp-header-end">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>ID</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($results): ?>
                            <?php foreach ($results as $row): ?>
                                <tr>
                                    <td><strong><a
                                                href="?page=dm-rifa-boletas&action=edit&id=<?php echo $row->id; ?>"><?php echo esc_html($row->nombre); ?></a></strong>
                                    </td>
                                    <td><?php echo $row->id; ?></td>
                                    <td>
                                        <a href="?page=dm-rifa-boletas&action=edit&id=<?php echo $row->id; ?>">Editar</a> |
                                        <a href="<?php echo wp_nonce_url('?page=dm-rifa-boletas&action=delete&id=' . $row->id, 'delete-boleta_' . $row->id); ?>"
                                            class="submitdelete" onclick="return confirm('¿Seguro?');" style="color:#a00;">Eliminar</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="3">No hay diseños creados.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php
        }
    }

    /* ---------------------- AJAX: Boleta Preview ---------------------- */
    public function ajax_boleta_preview()
    {
        // Match the nonce used in the form
        if (!isset($_POST['_ajax_nonce']) || !wp_verify_nonce($_POST['_ajax_nonce'], 'dm_save_boleta_action')) {
            wp_send_json_error('Error de seguridad (Nonce inválido).');
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('No autorizado');
        }

        parse_str($_POST['form_data'], $data);

        $bg_id = intval($data['dm_background_id'] ?? 0);
        $config = $data['ticket_config'] ?? array();

        if (!$bg_id) {
            wp_send_json_error('Falta imagen de fondo');
        }

        $preview_id = 'dm_preview_' . get_current_user_id();
        set_transient($preview_id, array(
            'background_id' => $bg_id,
            'ticket_config' => $config
        ), 300); // 5 minutes

        $url = admin_url('admin-post.php?action=dm_rifa_print_ticket&is_preview=1&preview_key=' . $preview_id);

        wp_send_json_success(array('url' => $url));
    }
}

DM_Rifa_Unificado::instance();