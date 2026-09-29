# DM Rifa Unificado — Plugin WordPress

## Contexto

Plugin propio de **DM Studio SAS** para operar rifas: venta online (selector de números + reserva + confirmación por WhatsApp con pago Nequi/transferencia) y venta física a través de vendedores con números asignados, arqueos de caja e impresión de boletas.

- **Estado:** en producción en dm-studio.com. Desarrollo activo.
- **Versión en el código:** 2.0.0.
- **Repositorio:** https://github.com/DM-Studio2018/dm-rifa-unificado (rama `main`).
- **Moneda:** COP, formato `180.000`.

---

## Instrucciones para Claude en este proyecto

1. Responder en **español latinoamericano**, tono profesional y cercano.
2. Código **completo y funcional**. Editar el archivo real; no entregar fragmentos con "resto igual".
3. Cada entrega: subir versión en el encabezado **y** en `private $version`, añadir entrada al historial y hacer commit.
4. Compatibilidad con datos de producción: columnas nuevas solo mediante las funciones `ensure_*_columns()` (corren en `admin_init`), nunca eliminar columnas sin migración.
5. Seguridad WordPress: `current_user_can('manage_options')`, nonces en cada formulario/acción, `$wpdb->prepare()` en consultas con datos externos, escape en toda salida.
6. **Nunca** dejar scripts sueltos (`*.php` de mantenimiento, `.sql`, CSV, logs) dentro de la carpeta del plugin en producción: todo lo que está en `wp-content/plugins/` es accesible por URL. Las tareas de mantenimiento se hacen con WP-CLI en Local o como acciones del admin protegidas.
7. Trabajar solo en Local. El despliegue lo hace Daniel.
8. El archivo principal es grande (~4.800 líneas): leer por secciones y buscar por nombre de función.

---

## Entornos y flujo de trabajo

| Entorno | Dónde | Uso |
|---|---|---|
| **Local** | Local para Mac, sitio "DM Studio" · http://localhost:10013/ | Desarrollo y pruebas |
| **Producción** | dm-studio.com · `/domains/dm-studio.com/public_html/wp-content/plugins/dm-rifa-unificado` | Rifas reales. No se toca directamente |
| **GitHub** | DM-Studio2018/dm-rifa-unificado | Historial y respaldo |

**Rutas locales**
- Plugin (copia de trabajo, clon de GitHub): `/Users/danielmaldonado/Local Sites/claude/app/public/wp-content/plugins/dm-rifa-unificado`
- Raíz de WordPress: `/Users/danielmaldonado/Local Sites/claude/app/public`
- Admin: http://localhost:10013/wp-admin/ → menú **DM Rifas**
- La ruta tiene un espacio (`Local Sites`): usar comillas en la terminal.

**Copias históricas (solo lectura, no desarrollar ahí)**
- `Local Sites/claude/dm-rifa-local-antiguo` — versión de nov-2025.
- `Local Sites/claude/dm-rifa-produccion` — PHP descargado de producción el 29-sep-2026.
- OneDrive `Trabajos en trafico/Mirror/Rifa/Boletas 2025 España/dm-rifa-unificado` — copia de trabajo hasta feb-2026 (incluye scripts de mantenimiento e imágenes de prueba). No usar git dentro de OneDrive.

**Flujo**
1. Desarrollar y probar en Local (WP-CLI desde *Open site shell* del sitio "DM Studio").
2. Datos: importar a Local la base de producción para probar. **Nunca** subir la base de Local a producción.
3. Commit + tag (`v1.x.x`) + `git push`.
4. Desplegar con **Transmit → Sincronizar** (local → remoto), primero en modo **Simular**. La ruta local del servidor "DM-Studio" en Transmit debe apuntar a la carpeta del plugin en Local.
5. Transmit tiene una regla que omite `.git`, `.gitattributes`, `.gitignore`, `CLAUDE.md`, `.DS_Store`.
6. Recomendado: cambiar la conexión FTP a SFTP.

---

## Estructura

```
dm-rifa-unificado/
├── dm-rifa-unificado.php   # Clase DM_Rifa_Unificado (singleton), todo el plugin
├── assets/
│   ├── frontend.js         # Grilla, filtros, selección, reserva AJAX, refresco de estados
│   ├── admin.js            # Utilidades del admin
│   ├── admin.css           # Estilos del admin
│   └── style.css           # Estilos front + admin
├── CLAUDE.md               # Este documento (no se despliega)
└── .gitignore              # Excluye scripts de mantenimiento, datos y logs
```

En producción existe además la carpeta `Google_Sans_Flex/` (fuente para las boletas). Ver pendiente sobre su ubicación.

---

## Base de datos (prefijo `$wpdb->prefix`)

| Tabla | Contenido | Columnas relevantes |
|---|---|---|
| `dm_rifas` | Rifas | nombre, fecha, loteria, total_numeros, precio, wa_e164, gracias_page_id, boleta_id, url_rifa, meta_recaudo, **modo_venta** (`mixto` · `virtual`), activo |
| `dm_rifa_numeros` | Un registro por número | numero (`000`…), **estado** (`disponible` · `asignado` · `reservado` · `pagado`), reserva_id, **vendedor_id** |
| `dm_rifa_reservas` | Compras/reservas | nombre, email, telefono, numeros_csv, precio_unit, total, **status** (`reservado` · `pagado` · `expirado`), token, vendedor_id, **forma_pago**, impreso, comprobante_url |
| `dm_rifa_vendedores` | Vendedores físicos | nombre, email, telefono |
| `dm_rifa_boletas` | Plantillas de boleta | nombre, background_id, ticket_config (JSON de posiciones y tamaños) |
| `dm_rifa_arqueos` | Entregas de dinero de vendedores | vendedor_id, rifa_id, monto, fecha, observaciones |

**Migraciones:** `on_activate()` crea las tablas con `dbDelta`. Como el despliegue es por FTP (no se reactiva el plugin), las columnas nuevas se agregan con `ensure_rifas_columns()`, `ensure_reservas_columns()`, `ensure_numeros_columns()` y `ensure_arqueos_table()` en `admin_init`. Toda columna nueva debe ir también ahí.

**Estados:** los estados `pago parcial` y `parcialmente liberado` de la v1.2.0 fueron eliminados (normalizados a `reservado` en feb-2026). Aún quedan referencias visuales en `page_compradores` (~línea 2867).

---

## Funcionalidades

**Front (shortcodes)**
- `[rifa_selector id="X"]` — grilla de números, buscador, filtros, selección, formulario del comprador y reserva por AJAX (`dm_rifa_reservar`). Refresca estados con `dm_rifa_get_states`.
- `[rifa_confirm]` — confirmación por token (`?rifa=ID&t=TOKEN`) y botón de WhatsApp con el comprobante.

**Admin — menú "DM Rifas"**
| Página | Slug | Método |
|---|---|---|
| Rifas (crear, editar, activar) | `dm-rifa` | `page_rifas()` |
| Reservas y Ventas | `dm-rifa-compradores` | `page_compradores()` |
| Vendedores (alta, edición, asignación física de números, arqueos) | `dm-rifa-vendedores` | `page_vendedores()` |
| Dashboard | `dm-rifa-dashboard` | `page_dashboard()` |
| Diseñador de Boletas | `dm-rifa-boletas` | `page_boletas()` |
| Reportes (sin entrada en el menú) | — | `page_reportes()` |

**Acciones (`admin_post_*` / AJAX)**
`update_reserva`, `liberar_reserva`, `delete_reserva`, `export_csv`, `export_report`, `export_vendedor`, `print_ticket` (genera la boleta en imagen con GD + `imagettftext`), `manual_cleanup` (limpieza manual de reservas vencidas; el cron está desactivado a propósito), `restore_data`, `dm_boleta_preview`.

---

## Historial de versiones

- **1.0.1** (≤ oct-2025) — Selector, reservas con token, confirmación por WhatsApp, admin básico.
- **1.1.0** (nov-2025) — Gestión de números por comprador; tab "Gestionar Números" eliminado.
- **1.2.0** (nov-2025) — Editar/eliminar rifas, resumen de ventas, sincronizar estados.
- **feb-2026 (sin número de versión, en producción desde el 24-feb-2026)** — Vendedores y asignación física de números, forma de pago, arqueos, dashboard, diseñador e impresión de boletas, comprobantes, modo de venta por rifa, limpieza manual de vencidas, restauración de datos, refresco de estados en el front, corrección de numeros_csv por `reserva_id`.
  - Commit en GitHub del 6-feb-2026 ("Generador de boletas, Asignación de vendedores"); los cambios del 6 al 24-feb se consolidaron en git el 29-sep-2026.
- **2.0.0** (29-sep-2026) — Numera como 2.0.0 los cambios de feb-2026. Corrige la asignación física de números a vendedores (`page_vendedores`): se reparó el JS corrupto de `updateHiddenNumeros()` (además se ejecuta en el `submit` del formulario); el handler de `dm_asignar_numeros` usa `numeros` y `numeros_check[]` como respaldo, valida que sean dígitos, **no libera nada si no llega ninguna selección** (muestra aviso), libera solo los números que el vendedor dejó de tener (con `vendedor_id = NULL` y `updated_at`) y reporta los que ya no estaban disponibles. Nota forense: hasta 1.x/feb-2026 ese handler era el único que liberaba con `vendedor_id = 0`; en datos anteriores a 2.0.0, `estado='disponible' AND vendedor_id = 0` identifica números liberados por él. Revisados los demás bloques `<script>` del archivo y `assets/*.js`: sin corrupción.

---

## Pendientes

**🔴 Prioridad alta**
- [x] ~~Bug en asignación física de vendedores~~ — corregido en 2.0.0.
- [ ] Revisar en la base de producción (importada en Local) si hay vendedores que perdieron asignaciones por el bug: vendedores con reservas/ventas y cero números en estado `asignado`. Reasignar manualmente desde el admin.
- [x] ~~Subir la versión del plugin a `2.0.0`~~.
- [ ] Limitación conocida de 2.0.0: para dejar a un vendedor con **cero** números asignados hay que desmarcar todos, y eso ahora se bloquea por seguridad. Si hace falta, agregar un botón explícito "Liberar todos" con confirmación.

**Deuda técnica**
- [ ] `status` de reservas es VARCHAR(12): suficiente para los estados actuales, pero ampliarlo a VARCHAR(20) si se agregan otros.
- [ ] Limpiar referencias a `pago parcial` / `parcialmente liberado` en `page_compradores`.
- [ ] Fuente de boletas: el código busca en `assets/fonts/...`, pero la carpeta `Google_Sans_Flex/` está en la raíz del plugin. Verificar qué fuente se está usando realmente y mover la carpeta a `assets/fonts/`.
- [ ] Condición de carrera en `ajax_reservar()`: validar con `UPDATE ... WHERE estado='disponible'` y filas afectadas.
- [ ] Revisar consultas N+1 en listados.
- [ ] El archivo principal tiene zonas con indentación inconsistente (formateo parcial): normalizar en un commit aparte, sin cambios de lógica.
- [ ] Mover estilos inline del admin a `admin.css`.

**Ideas**
- [ ] Mostrar QR de Nequi en la confirmación (`nequi_qr_url`).
- [ ] Recordatorio de pago por WhatsApp desde el admin.
- [ ] Despliegue automático con GitHub Actions al crear un tag.
