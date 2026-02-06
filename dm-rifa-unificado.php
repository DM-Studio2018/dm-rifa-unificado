<?php
/**
 * Plugin Name: DM Rifa Unificado
 * Description: Selector de números, reservas y página de confirmación con WhatsApp + panel de gestión en el admin (todo en un solo plugin).
 * Version: 1.1.0
 * Author: DM Studio SAS
 * License: GPL2
 */

if (!defined('ABSPATH')) {
    exit;
}

class DM_Rifa_Unificado
{
    private static $instance = null;
    private $version = '1.1.0';
    private $tbl_rifas;
    private $tbl_numeros;
    private $tbl_reservas;
    private $tbl_vendedores;

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
        add_action('admin_post_dm_rifa_manual_cleanup', array($this, 'admin_post_manual_cleanup'));
        add_action('admin_post_dm_rifa_print_ticket', array($this, 'admin_post_print_ticket'));

        // Cron logic
        add_action('dm_rifa_cleanup_expired_hook', array($this, 'cron_cleanup_expired'));
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
            vendedor_id BIGINT UNSIGNED NULL,
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

        dbDelta($sql_rifas);
        dbDelta($sql_numeros);
        dbDelta($sql_reservas);
        dbDelta($sql_vendedores);

        if (!wp_next_scheduled('dm_rifa_cleanup_expired_hook')) {
            wp_schedule_event(time(), 'hourly', 'dm_rifa_cleanup_expired_hook');
        }
    }

    /* ---------------------- Assets ---------------------- */
    public function enqueue_front()
    {
        if (is_singular()) {
            global $post;
            if ($post && (has_shortcode($post->post_content, 'rifa_selector') || has_shortcode($post->post_content, 'rifa_confirm'))) {
                wp_enqueue_script('dm-rifa-front', plugins_url('assets/frontend.js', __FILE__), array('jquery'), $this->version, true);
                wp_localize_script('dm-rifa-front', 'DMRIFA', array(
                    'ajax' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('dm_rifa_nonce')
                ));
                wp_enqueue_style('dm-rifa-front', plugins_url('assets/style.css', __FILE__), array(), $this->version);
            }
        }
    }

    public function enqueue_admin($hook)
    {
        if (strpos($hook, 'dm-rifa') !== false) {
            wp_enqueue_media();
            wp_enqueue_style('dm-rifa-front', plugins_url('assets/style.css', __FILE__), array(), $this->version);
            wp_enqueue_script('dm-rifa-admin', plugins_url('assets/admin.js', __FILE__), array('jquery'), $this->version, true);
            wp_localize_script('dm-rifa-admin', 'DMRIFA', array(
                'ajax' => admin_url('admin-ajax.php'),
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
        add_submenu_page('dm-rifa', 'Compradores', 'Compradores', 'manage_options', 'dm-rifa-compradores', array($this, 'page_compradores'));
        add_submenu_page('dm-rifa', 'Vendedores', 'Vendedores', 'manage_options', 'dm-rifa-vendedores', array($this, 'page_vendedores'));
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

        $numeros_arr = array_map('trim', explode(',', $reserva->numeros_csv));
        if (empty($numeros_arr))
            return 'reservado';

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

        $ticket_url = admin_url('admin-post.php?action=dm_rifa_print_ticket&reserva_id=' . $reserva->id);
        $msj .= "Puedes ver/descargar tu boleta aquí: " . $ticket_url . "\n\n";

        if ($vendedor) {
            $msj .= "Vendedor: " . $vendedor->nombre . " (" . $vendedor->telefono . ")\n";
        }

        return "https://wa.me/" . preg_replace('/[^0-9]/', '', $reserva->telefono) . "/?text=" . urlencode($msj);
    }

    /* ---------------------- Admin: Rifas ---------------------- */
    public function page_rifas()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        global $wpdb;

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
            $bg_id = intval($_POST['background_id'] ?? 0);

            $data = array(
                'nombre' => $nombre,
                'descripcion' => $desc,
                'fecha' => $fecha ? date('Y-m-d H:i:s', strtotime($fecha)) : NULL,
                'loteria' => $loteria,
                'precio' => $precio,
                'wa_e164' => $wa,
                'css_activo' => $css,
                'gracias_page_id' => $gracias,
                'background_id' => $bg_id,
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
                            <?php if (!$edit_rifa): ?><em>(ej: 470 crea 000–469)</em><?php else: ?><br><small>El total de números
                                    no se puede editar una vez creada la rifa.</small><?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Precio por boleta</th>
                        <td><input type="number" name="precio"
                                value="<?php echo $edit_rifa ? intval($edit_rifa->precio) : '60000'; ?>" min="0" required></td>
                    </tr>
                    <tr>
                        <th>WhatsApp (E.164)</th>
                        <td><input type="text" name="wa_e164"
                                value="<?php echo $edit_rifa ? esc_attr($edit_rifa->wa_e164) : '573123625582'; ?>" required>
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
                        <th>Fondo de Boleta (1080x1920)</th>
                        <td>
                            <input type="hidden" name="background_id" id="dm_background_id"
                                value="<?php echo $edit_rifa ? intval($edit_rifa->background_id) : ''; ?>">
                            <div id="dm_bg_preview" style="margin-bottom:10px;">
                                <?php if ($edit_rifa && $edit_rifa->background_id): ?>
                                    <?php $img = wp_get_attachment_image_src($edit_rifa->background_id, 'medium'); ?>
                                    <?php if ($img): ?><img src="<?php echo esc_url($img[0]); ?>"
                                            style="max-width:200px; height:auto; border:1px solid #ccc;"><?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <button type="button" class="button dm-upload-btn" data-target="#dm_background_id"
                                data-preview="#dm_bg_preview">Subir/Elegir Imagen</button>
                            <p class="description">Imagen de fondo para la generación automática de boletas.</p>
                        </td>
                    </tr>
                </table>
                <p>
                    <button type="submit" class="button button-primary" name="dm_guardar_rifa"
                        value="1"><?php echo $edit_rifa ? 'Actualizar rifa' : 'Crear rifa'; ?></button>
                    <?php if ($edit_rifa): ?>
                        <a href="<?php echo admin_url('admin.php?page=dm-rifa-unificado'); ?>" class="button">Cancelar</a>
                    <?php endif; ?>
                </p>
            </form>

            <h2>Rifas existentes</h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nombre</th>
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
                                        href="<?php echo admin_url('admin.php?page=dm-rifa-unificado&action=edit&id=' . intval($r->id)); ?>">Editar</a>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                        <tr>
                            <td colspan="7">No hay rifas.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /* ---------------------- Admin: Vendedores ---------------------- */
    public function page_vendedores()
    {
        global $wpdb;

        // Procesar creación
        if (isset($_POST['dm_crear_vendedor'])) {
            check_admin_referer('dm_vendedor_nonce');
            $wpdb->insert($this->tbl_vendedores, array(
                'nombre' => sanitize_text_field($_POST['nombre']),
                'email' => sanitize_email($_POST['email']),
                'telefono' => sanitize_text_field($_POST['telefono'])
            ));
            echo '<div class="updated"><p>Vendedor creado.</p></div>';
        }

        // Procesar eliminación
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
            check_admin_referer('dm_del_vendedor_' . $_GET['id']);
            $wpdb->delete($this->tbl_vendedores, array('id' => intval($_GET['id'])));
            echo '<div class="updated"><p>Vendedor eliminado.</p></div>';
        }

        $vendedores = $wpdb->get_results("SELECT * FROM {$this->tbl_vendedores} ORDER BY nombre ASC");
        ?>
        <div class="wrap">
            <h1>Gestión de Vendedores</h1>

            <div class="card" style="max-width: 500px; margin-top: 20px;">
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
                    <p><button type="submit" name="dm_crear_vendedor" class="button button-primary">Registrar Vendedor</button>
                    </p>
                </form>
            </div>

            <h2>Listado de Vendedores</h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nombre</th>
                        <th>Email</th>
                        <th>Teléfono</th>
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
                                <td>
                                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=dm-rifa-vendedores&action=delete&id=' . $v->id), 'dm_del_vendedor_' . $v->id); ?>"
                                        class="button button-link-delete" onclick="return confirm('¿Eliminar vendedor?')">Eliminar</a>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                        <tr>
                            <td colspan="5">No hay vendedores registrados.</td>
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
        $rifa_id = intval($_GET['rifa_id'] ?? 0);
        $view_reserva = intval($_GET['view'] ?? 0);

        $rifas = $wpdb->get_results("SELECT id,nombre FROM {$this->tbl_rifas} ORDER BY id DESC");
        if (!$rifas) {
            echo '<div class="wrap"><h1>Compradores</h1><p>Primero crea una rifa.</p></div>';
            return;
        }

        if (!$rifa_id) {
            $rifa_id = intval($rifas[0]->id);
        }
        $rifa = $this->fetch_rifa($rifa_id);

        // Vista detalle de una reserva
        if ($view_reserva > 0) {
            $this->render_reserva_detail($view_reserva, $rifa_id);
            return;
        }

        // Lista de compradores con estado calculado en tiempo real
        $res = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->tbl_reservas} WHERE rifa_id = %d ORDER BY id DESC", $rifa_id));

        // Calcular el estado real de cada reserva basado en sus números
        foreach ($res as $reserva) {
            $reserva->status_real = $this->calcular_estado_reserva($reserva, $rifa_id);
        }

        $export_url = wp_nonce_url(admin_url('admin-post.php?action=dm_rifa_export_csv&rifa_id=' . $rifa_id), 'dm_rifa_export_csv');

        ?>
        <div class="wrap">
            <h1>Compradores - <?php echo esc_html($rifa->nombre); ?></h1>
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

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                style="display:inline-block; margin-left:10px;">
                <?php wp_nonce_field('dm_rifa_manual_cleanup'); ?>
                <input type="hidden" name="action" value="dm_rifa_manual_cleanup">
                <input type="hidden" name="rifa_id" value="<?php echo esc_attr($rifa_id); ?>">
                <button type="submit" class="button" onclick="return confirm('¿Liberar todas las reservas expiradas?');">
                    Liberar Expiradas
                </button>
            </form>

            <?php if (isset($_GET['cleanup_done'])): ?>
                <div class="notice notice-info is-dismissible" style="margin-left:0; margin-right:0;">
                    <p>Se han liberado <?php echo intval($_GET['cleanup_done']); ?> reservas expiradas.</p>
                </div>
            <?php endif; ?>

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
                    <?php if ($res):
                        foreach ($res as $row): ?>
                            <tr>
                                <td><?php echo esc_html($row->id); ?></td>
                                <td><?php echo esc_html($row->nombre); ?></td>
                                <td><?php echo esc_html($row->email); ?></td>
                                <td><?php echo esc_html($row->telefono); ?></td>
                                <td><?php echo esc_html($row->numeros_csv); ?></td>
                                <td><?php echo esc_html(number_format($row->total, 0, ',', '.')); ?></td>
                                <td>
                                    <span style="padding:3px 8px;border-radius:3px;background:<?php
                                    echo $row->status === 'pagado' ? '#d9f7d9' : ($row->status === 'reservado' ? '#fff5cc' : '#f0f0f0');
                                    ?>">
                                        <?php echo esc_html($row->status); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html($row->created_at); ?></td>
                                <td>
                                    <a class="button button-small"
                                        href="<?php echo esc_url(admin_url('admin.php?page=dm-rifa-compradores&rifa_id=' . $rifa_id . '&view=' . $row->id)); ?>">
                                        Gestionar números
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                        <tr>
                            <td colspan="9">Sin compradores aún.</td>
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
            $place = implode(',', array_fill(0, count($numeros_arr), '%s'));
            $query = $wpdb->prepare(
                "SELECT numero, estado FROM {$this->tbl_numeros} WHERE rifa_id = %d AND numero IN ($place)",
                array_merge(array($rifa_id), $numeros_arr)
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
                    ← Volver a compradores
                </a>
            </p>

            <div style="background:#fff;border:1px solid #ccc;padding:15px;margin:15px 0;">
                <h2>Información del comprador</h2>
                <p><strong>Nombre:</strong> <?php echo esc_html($reserva->nombre); ?></p>
                <p><strong>Email:</strong> <?php echo esc_html($reserva->email); ?></p>
                <p><strong>Teléfono:</strong> <?php echo esc_html($reserva->telefono); ?></p>
                <p><strong>Total:</strong> $<?php echo esc_html(number_format($reserva->total, 0, ',', '.')); ?></p>
                <p><strong>Estado global:</strong> <?php echo esc_html($reserva->status); ?></p>
                <p><strong>Creado:</strong> <?php echo esc_html($reserva->created_at); ?></p>

                <?php
                $vendedores = $wpdb->get_results("SELECT id, nombre FROM {$this->tbl_vendedores} ORDER BY nombre ASC");
                ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                    style="margin-top:15px; border-top:1px solid #eee; padding-top:10px;">
                    <?php wp_nonce_field('dm_rifa_update_reserva'); ?>
                    <input type="hidden" name="action" value="dm_rifa_update_reserva">
                    <input type="hidden" name="reserva_id" value="<?php echo esc_attr($reserva_id); ?>">
                    <input type="hidden" name="rifa_id" value="<?php echo esc_attr($rifa_id); ?>">
                    <input type="hidden" name="solo_vendedor" value="1">

                    <label><strong>Vendedor Responsable:</strong><br>
                        <select name="vendedor_id">
                            <option value="">-- Sin asignar --</option>
                            <?php foreach ($vendedores as $v): ?>
                                <option value="<?php echo intval($v->id); ?>" <?php selected(intval($reserva->vendedor_id), intval($v->id)); ?>>
                                    <?php echo esc_html($v->nombre); ?>
                                </option>
                            <?php endforeach; ?>
                        </select></label>
                    <button type="submit" class="button">Guardar Vendedor</button>
                </form>

                <?php if ($rifa->background_id): ?>
                    <p style="margin-top:20px;">
                        <a href="<?php echo esc_url(admin_url('admin-post.php?action=dm_rifa_print_ticket&reserva_id=' . $reserva_id)); ?>"
                            class="button button-primary" target="_blank">
                            <span class="dashicons dashicons-printer" style="margin-top:4px;"></span> Imprimir Boleta (PDF/JPG)
                        </a>
                        <a href="<?php echo $this->get_whatsapp_ticket_url($reserva, $rifa); ?>" class="button" target="_blank"
                            style="background:#25D366; color:#fff; border-color:#25D366;">
                            <span class="dashicons dashicons-whatsapp" style="margin-top:4px;"></span> Enviar por WhatsApp
                        </a>
                    </p>
                <?php else: ?>
                    <p class="description" style="color:red;">Sube una imagen de fondo en la edición de la rifa para habilitar la
                        impresión de boletas.</p>
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
                    <button class="button button-primary" type="submit" name="cambiar_estado" value="pagado">Marcar
                        seleccionados como Pagado</button>
                    <button class="button" type="submit" name="cambiar_estado" value="disponible">Liberar seleccionados
                        (Disponible)</button>
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
                        <?php foreach ($numeros_arr as $num):
                            $num = trim($num);
                            if ($num === '')
                                continue;
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
            $wpdb->update($this->tbl_reservas, array('vendedor_id' => intval($_POST['vendedor_id'])), array('id' => $reserva_id));
            wp_redirect(admin_url('admin.php?page=dm-rifa-compradores&rifa_id=' . $rifa_id . '&view=' . $reserva_id));
            exit;
        }

        $estado = sanitize_text_field($_POST['cambiar_estado'] ?? '');
        $numeros = array_map('sanitize_text_field', $_POST['numeros'] ?? array());

        if (!in_array($estado, array('disponible', 'reservado', 'pagado')) || empty($numeros)) {
            wp_redirect(admin_url('admin.php?page=dm-rifa-compradores&rifa_id=' . $rifa_id . '&view=' . $reserva_id));
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

        wp_redirect(admin_url('admin.php?page=dm-rifa-compradores&rifa_id=' . $rifa_id . '&view=' . $reserva_id . '&updated=1'));
        exit;
    }

    public function admin_post_export_csv()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Permisos insuficientes');
        }
        check_admin_referer('dm_rifa_export_csv');
        global $wpdb;
        $rifa_id = intval($_GET['rifa_id'] ?? 0);
        $rifa = $this->fetch_rifa($rifa_id);
        if (!$rifa) {
            wp_die('Rifa no encontrada');
        }

        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->tbl_reservas} WHERE rifa_id = %d ORDER BY id DESC", $rifa_id), ARRAY_A);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=compradores_rifa_' . $rifa_id . '.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, array('ID', 'Nombre', 'Email', 'Telefono', 'Numeros', 'Precio_Unit', 'Total', 'Status', 'Creado', 'Vence'));
        foreach ($rows as $r) {
            fputcsv($out, array($r['id'], $r['nombre'], $r['email'], $r['telefono'], $r['numeros_csv'], $r['precio_unit'], $r['total'], $r['status'], $r['created_at'], $r['expires_at']));
        }
        fclose($out);
        exit;
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
    public function sc_rifa_confirm($atts)
    {
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

        $nums = esc_html($res->numeros_csv);
        $total = number_format(intval($res->total), 0, ',', '.');
        $wa = preg_replace('/[^0-9]/', '', (string) $rifa->wa_e164);
        $msg = rawurlencode("Hola, soy {$res->nombre}. Acabo de pagar mis boletas: {$nums}. Total: $" . $total . ". Adjunto comprobante de Nequi.");
        $wa_url = "https://wa.me/{$wa}?text={$msg}";

        ob_start(); ?>
        <div class="dm-rifa-confirm">
            <h2>¡Tus números han sido reservados!</h2>
            <p><strong>Nombre:</strong> <?php echo esc_html($res->nombre); ?><br>
                <strong>Teléfono:</strong> <?php echo esc_html($res->telefono); ?><br>
                <strong>Email:</strong> <?php echo esc_html($res->email); ?>
            </p>
            <p><strong>Números:</strong> <?php echo $nums; ?><br>
                <strong>Precio unitario:</strong> $<?php echo number_format(intval($res->precio_unit), 0, ',', '.'); ?><br>
                <strong>Total:</strong> $<?php echo $total; ?>
            </p>
            <p>Envía el comprobante de Nequi lo antes posible para asegurar tu participación.</p>
            <button type="button" class="button button-primary"
                onclick="window.location.href='<?php echo esc_url($wa_url); ?>'">Enviar comprobante por WhatsApp</button>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ---------------------- AJAX: Reservar ---------------------- */
    public function ajax_reservar()
    {
        check_ajax_referer('dm_rifa_nonce', 'nonce');
        global $wpdb;

        $rifa_id = intval($_POST['rifa_id'] ?? 0);
        $nlist = $_POST['numeros'] ?? array();
        $nombre = sanitize_text_field($_POST['nombre'] ?? '');
        $email = sanitize_text_field($_POST['email'] ?? '');
        $tel = sanitize_text_field($_POST['telefono'] ?? '');

        if (!$rifa_id || empty($nlist) || !$nombre || !$tel) {
            wp_send_json_error(array('message' => 'Datos incompletos'), 400);
        }
        $rifa = $this->fetch_rifa($rifa_id);
        if (!$rifa) {
            wp_send_json_error(array('message' => 'Rifa no encontrada'), 404);
        }

        $nums = array();
        foreach ($nlist as $k => $v) {
            $v = strtoupper(trim($v));
            $v = str_pad(preg_replace('/\D/', '', $v), 3, '0', STR_PAD_LEFT);
            if ($v !== '')
                $nums[] = $v;
        }
        $nums = array_values(array_unique($nums));
        if (empty($nums)) {
            wp_send_json_error(array('message' => 'No se enviaron números válidos'), 400);
        }

        $place = implode(',', array_fill(0, count($nums), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare("SELECT numero, estado FROM {$this->tbl_numeros} WHERE rifa_id = %d AND numero IN ($place)", array_merge(array($rifa_id), $nums)));
        $no_disp = array();
        $found = array();
        foreach ($rows as $r) {
            $found[$r->numero] = $r->estado;
        }
        foreach ($nums as $n) {
            if (!isset($found[$n]) || $found[$n] !== 'disponible') {
                $no_disp[] = $n;
            }
        }
        if (!empty($no_disp)) {
            wp_send_json_error(array('message' => 'No disponibles: ' . implode(', ', $no_disp)), 409);
        }

        $precio_unit = intval($rifa->precio);
        $total = $precio_unit * count($nums);
        $expires = date('Y-m-d H:i:s', time() + 24 * 3600);
        $token = function_exists('random_bytes')
            ? bin2hex(random_bytes(16))
            : wp_generate_password(32, false);

        $wpdb->insert($this->tbl_reservas, array(
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
        ));
        $reserva_id = intval($wpdb->insert_id);
        if ($reserva_id <= 0) {
            wp_send_json_error(array('message' => 'No se pudo crear la reserva'), 500);
        }

        $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$this->tbl_numeros} WHERE rifa_id = %d AND numero IN ($place)", array_merge(array($rifa_id), $nums)));
        if ($ids) {
            $place2 = implode(',', array_fill(0, count($ids), '%d'));
            $wpdb->query($wpdb->prepare("UPDATE {$this->tbl_numeros} SET estado = 'reservado', reserva_id = %d, updated_at = NOW() WHERE id IN ($place2)", array_merge(array($reserva_id), $ids)));
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

        $count = $this->cron_cleanup_expired();

        $rifa_id = intval($_POST['rifa_id'] ?? 0);
        $redirect = admin_url('admin.php?page=dm-rifa-compradores');
        if ($rifa_id) {
            $redirect = add_query_arg('rifa_id', $rifa_id, $redirect);
        }
        $redirect = add_query_arg('cleanup_done', $count, $redirect);

        wp_redirect($redirect);
        exit;
    }

    /**
     * Motor de generación de boletas (GD)
     */
    public function admin_post_print_ticket()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Permisos insuficientes');
        }

        $reserva_id = intval($_GET['reserva_id'] ?? 0);
        $reserva = $this->fetch_reserva($reserva_id);
        if (!$reserva)
            wp_die('Reserva no encontrada');

        $rifa = $this->fetch_rifa($reserva->rifa_id);
        if (!$rifa || !$rifa->background_id)
            wp_die('La rifa no tiene imagen de fondo configurada.');

        $bg_path = get_attached_file($rifa->background_id);
        if (!$bg_path || !file_exists($bg_path))
            wp_die('Imagen de fondo no encontrada en el servidor.');

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

        if (!$im)
            wp_die('Error al cargar la imagen.');

        // Colores
        $black = imagecolorallocate($im, 0, 0, 0);

        // Intentar usar una fuente TTF si está disponible
        $font = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
        if (!file_exists($font)) {
            $font = '/System/Library/Fonts/Supplemental/Arial Bold.ttf'; // Mac
        }

        $nombre_completo = strtoupper($reserva->nombre);
        $numeros = $reserva->numeros_csv;
        $estado = strtoupper($reserva->status);

        if (file_exists($font)) {
            // imagettftext($image, $size, $angle, $x, $y, $color, $font, $text)
            imagettftext($im, 35, 0, 80, 1450, $black, $font, "NOMBRE:");
            imagettftext($im, 45, 0, 80, 1520, $black, $font, $nombre_completo);

            imagettftext($im, 35, 0, 80, 1620, $black, $font, "NÚMEROS:");
            imagettftext($im, 55, 0, 80, 1700, $black, $font, $numeros);

            imagettftext($im, 35, 0, 80, 1800, $black, $font, "ESTADO: " . $estado);
        } else {
            // Fallback a fuentes internas de GD (más feas)
            imagestring($im, 5, 80, 1450, "NOMBRE: " . $nombre_completo, $black);
            imagestring($im, 5, 80, 1550, "NUMEROS: " . $numeros, $black);
            imagestring($im, 5, 80, 1650, "ESTADO: " . $estado, $black);
        }

        // Salida
        header('Content-Type: image/jpeg');
        header('Content-Disposition: inline; filename="boleta-' . $reserva_id . '.jpg"');
        imagejpeg($im, null, 90);
        imagedestroy($im);
        exit;
    }
}

DM_Rifa_Unificado::instance();