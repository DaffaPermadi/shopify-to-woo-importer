<?php
namespace STWI;

class Admin {
    private $importer;
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // AJAX handlers
        add_action('wp_ajax_stwi_upload_csv', array($this, 'handle_file_upload'));
        // add_action('wp_ajax_stwi_process_batch', array($this, 'process_batch'));
        add_action('wp_ajax_stwi_get_progress', array($this, 'get_progress'));
        add_action('wp_ajax_stwi_get_all_imports', array($this, 'get_all_imports'));
        // add_action('wp_ajax_stwi_delete_import', array($this, 'delete_import'));
        // add_action('wp_ajax_stwi_start_import', array($this, 'start_import'));

        
        $this->importer = new Importer();
    }
    
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('Shopify Importer', 'shopify-to-woo-importer'),
            __('Shopify Importer', 'shopify-to-woo-importer'),
            'manage_woocommerce',
            'shopify-importer',
            array($this, 'render_admin_page')
        );
    }
    
    public function enqueue_scripts($hook) {
        if ('woocommerce_page_shopify-importer' !== $hook) {
            return;
        }
        wp_enqueue_style('stwi-admin', STWI_PLUGIN_URL . 'assets/admin.css', array(), STWI_VERSION);
        wp_enqueue_script('dropzone', 'https://unpkg.com/dropzone@5/dist/min/dropzone.min.js', array(), '5.9.3', true);
        
        wp_enqueue_script('stwi-admin', STWI_PLUGIN_URL . 'assets/admin.js', array('jquery', 'dropzone'), STWI_VERSION, true);
        
        wp_localize_script('stwi-admin', 'stwi_params', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('stwi-nonce-action'),
        'max_file_size' => wp_max_upload_size(),
        'strings' => array(
            'dropzone_message' => __('Drop Shopify CSV file here or click to upload', 'shopify-to-woo-importer'),
            'processing' => __('Processing...', 'shopify-to-woo-importer'),
            'complete' => __('Import complete!', 'shopify-to-woo-importer'),
            'error' => __('Error occurred', 'shopify-to-woo-importer')
            )
        ));
    }
    // public function enqueue_scripts($hook) {
    //     if ('woocommerce_page_shopify-importer' !== $hook) {
    //         error_log('enqueue_scripts tidak berjalan di halaman ini: ' . $hook);
    //         return;
    //     }
    //     error_log('enqueue_scripts berhasil dijalankan');
    
    //      wp_enqueue_script('stwi-admin', STWI_PLUGIN_URL . 'assets/admin.js', array('jquery', 'dropzone'), STWI_VERSION, true);
        
    //     $nonce = wp_create_nonce('stwi_nonce_action'); // Perbaiki nama nonce
    //     error_log('Nonce yang dibuat: ' . $nonce);
    
    //     wp_localize_script('stwi-admin', 'stwi_params', array(
    //         'ajax_url' => admin_url('admin-ajax.php'),
    //         'nonce'    => $nonce, 
    //     ));
    // }
    
    public function handle_file_upload() {
        check_ajax_referer('stwi-nonce-action', 'nonce');
    
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied', 'shopify-to-woo-importer')));
        }
        
        // Debug untuk melihat file yang diterima
        error_log('Files received: ' . print_r($_FILES, true));
    
        if (empty($_FILES['file'])) {
            wp_send_json_error(array('message' => __('No file uploaded', 'shopify-to-woo-importer')));
        }
    
        $file = $_FILES['file'];
    
        // Validasi tipe file
        $file_type = wp_check_filetype(basename($file['name']), array('csv' => 'text/csv'));
        if (!$file_type['type']) {
            wp_send_json_error(array('message' => __('Invalid file type. Please upload a CSV file.', 'shopify-to-woo-importer')));
        }
    
        // Buat direktori upload jika belum ada
        $upload_dir = wp_upload_dir();
        $import_dir = $upload_dir['basedir'] . '/shopify-imports';
        wp_mkdir_p($import_dir);
    
        // Buat nama file unik
        $filename = uniqid('shopify-import-') . '.csv';
        $filepath = $import_dir . '/' . $filename;
    
        // Pindahkan file yang diupload
        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            wp_send_json_error(array('message' => __('Failed to save uploaded file', 'shopify-to-woo-importer')));
        }
    
        // Inisialisasi sesi impor dan dapatkan import_id
        $import_id = $this->importer->init_import($filepath);
        
        if (!$import_id) {
            wp_send_json_error(array('message' => __('Failed to initialize import', 'shopify-to-woo-importer')));
        }
    
        // Kirimkan respons sukses dengan import_id
        wp_send_json_success(array(
            'import_id' => $import_id,
            'message' => __('File uploaded successfully', 'shopify-to-woo-importer')
        ));
    }
    
    // public function process_batch() {
    //     check_ajax_referer('stwi-nonce-action', 'nonce');
    
    //     if (!current_user_can('manage_woocommerce')) {
    //         wp_send_json_error(array('message' => __('Permission denied', 'shopify-to-woo-importer')));
    //     }
    
    //     // Ambil import_id dari permintaan POST
    //     $import_id = isset($_POST['import_id']) ? sanitize_text_field($_POST['import_id']) : '';
        
    //     // Cek apakah import_id valid
    //     if (!$import_id) {
    //         wp_send_json_error(array('message' => __('Invalid import ID', 'shopify-to-woo-importer')));
    //     }
    
    //     // Proses batch menggunakan import_id
    //     $result = $this->importer->process_batch($import_id);
        
    //     if (is_wp_error($result)) {
    //         wp_send_json_error(array('message' => $result->get_error_message()));
    //     }
        
    //     wp_send_json_success($result);
    // }
    
    
    public function get_progress() {
        check_ajax_referer('stwi-nonce-action', 'nonce');
        
        $import_id = isset($_POST['import_id']) ? sanitize_text_field($_POST['import_id']) : '';
        if (!$import_id) {
            wp_send_json_error(array('message' => __('Invalid import ID', 'shopify-to-woo-importer')));
        }
        
        $progress = $this->importer->get_progress($import_id);
        wp_send_json_success($progress);
    }

    /* public function render_admin_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Shopify ke WooCommerce Importer', 'shopify-to-woo-importer'); ?></h1>
            <div class="stwi-container">
                <!-- Panel Informasi -->
                <div class="stwi-info-panel">
                    <p><?php _e('Upload file CSV Shopify Anda untuk mengimpor produk ke WooCommerce.', 'shopify-to-woo-importer'); ?></p>
                    <div class="stwi-requirements">
                        <span class="dashicons dashicons-info"></span>
                        <small><?php _e('Format yang didukung: CSV, Ukuran maksimal: 14MB', 'shopify-to-woo-importer'); ?></small>
                    </div>
                </div>
    
                <!-- Area Upload -->
                <div class="stwi-upload-section">
                    <form id="stwi-upload-form" class="stwi-dropzone" method="post" enctype="multipart/form-data">
                        <?php wp_nonce_field('stwi-nonce-action', 'stwi_nonce'); ?>
                    </form>
                </div>
    
                <!-- Daftar File -->
                <div class="stwi-files-section">
                    <div class="stwi-section-header">
                        <h2><?php _e('File yang Diupload', 'shopify-to-woo-importer'); ?></h2>
                        <button id="stwi-process-all" class="button button-primary">
                            <span class="dashicons dashicons-controls-play"></span>
                            <?php _e('Proses Semua File', 'shopify-to-woo-importer'); ?>
                        </button>
                    </div>
    
                    <table class="widefat stwi-files-table">
                        <thead>
                            <tr>
                                <th><?php _e('Nama File', 'shopify-to-woo-importer'); ?></th>
                                <th class="column-progress"><?php _e('Progress', 'shopify-to-woo-importer'); ?></th>
                                <th><?php _e('Status', 'shopify-to-woo-importer'); ?></th>
                                <th class="column-actions"><?php _e('Aksi', 'shopify-to-woo-importer'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="stwi-file-list"></tbody>
                    </table>
    
                    <!-- Template untuk baris file -->
                    <script type="text/template" id="stwi-file-row-template">
                        <tr data-file-id="{%=fileId%}">
                            <td class="column-filename">
                                <strong>{%=fileName%}</strong>
                            </td>
                            <td class="column-progress">
                                <div class="stwi-progress">
                                    <div class="stwi-progress-bar" style="width: 0%"></div>
                                    <span class="stwi-progress-text">0%</span>
                                </div>
                            </td>
                            <td class="column-status">
                                <span class="stwi-status-badge stwi-status-pending">
                                    <?php _e('Menunggu', 'shopify-to-woo-importer'); ?>
                                </span>
                            </td>
                            <td class="column-actions">
                                <div class="stwi-file-controls">
                                    <button class="button stwi-start-import">
                                        <span class="dashicons dashicons-controls-play"></span>
                                        <?php _e('Mulai', 'shopify-to-woo-importer'); ?>
                                    </button>
                                    <button class="button stwi-pause-import" style="display:none">
                                        <span class="dashicons dashicons-controls-pause"></span>
                                        <?php _e('Jeda', 'shopify-to-woo-importer'); ?>
                                    </button>
                                    <button class="button stwi-resume-import" style="display:none">
                                        <span class="dashicons dashicons-controls-play"></span>
                                        <?php _e('Lanjut', 'shopify-to-woo-importer'); ?>
                                    </button>
                                    <button class="button stwi-delete-import">
                                        <span class="dashicons dashicons-trash"></span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </script>
                </div>
    
                <!-- Panel Statistik -->
                <div class="stwi-stats-panel">
                    <div class="stwi-stat-box">
                        <span class="dashicons dashicons-upload"></span>
                        <div class="stwi-stat-content">
                            <h3><?php _e('Total File', 'shopify-to-woo-importer'); ?></h3>
                            <span id="stwi-total-files">0</span>
                        </div>
                    </div>
                    <div class="stwi-stat-box">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <div class="stwi-stat-content">
                            <h3><?php _e('Berhasil', 'shopify-to-woo-importer'); ?></h3>
                            <span id="stwi-success-count">0</span>
                        </div>
                    </div>
                    <div class="stwi-stat-box">
                        <span class="dashicons dashicons-warning"></span>
                        <div class="stwi-stat-content">
                            <h3><?php _e('Error', 'shopify-to-woo-importer'); ?></h3>
                            <span id="stwi-error-count">0</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }*/
    public function render_admin_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Shopify ke WooCommerce Importer', 'shopify-to-woo-importer'); ?></h1>
            <div class="stwi-container">
                <!-- Panel Informasi -->
                <div class="stwi-info-panel">
                    <p><?php _e('Upload file CSV Shopify Anda untuk mengimpor produk ke WooCommerce.', 'shopify-to-woo-importer'); ?></p>
                    <div class="stwi-requirements">
                        <span class="dashicons dashicons-info"></span>
                        <small><?php _e('Format yang didukung: CSV, Ukuran maksimal: 14MB', 'shopify-to-woo-importer'); ?></small>
                    </div>
                </div>
        
                <!-- Area Upload -->
                <div class="stwi-upload-section">
                    <form id="stwi-upload-form" class="stwi-dropzone" method="post" enctype="multipart/form-data">
                        <?php wp_nonce_field('stwi-nonce-action', 'stwi_nonce'); ?>
                    </form>
                </div>
        
                <!-- Daftar File -->
                <div class="stwi-files-section">
                    <div class="stwi-section-header">
                        <h2><?php _e('File yang Diupload', 'shopify-to-woo-importer'); ?></h2>
                        <button id="stwi-process-all" class="button button-primary">
                            <span class="dashicons dashicons-controls-play"></span>
                            <?php _e('Proses Semua File', 'shopify-to-woo-importer'); ?>
                        </button>
                    </div>
        
                    <table class="widefat stwi-files-table">
                        <thead>
                            <tr>
                                <th><?php _e('Nama File', 'shopify-to-woo-importer'); ?></th>
                                <th class="column-progress"><?php _e('Progress', 'shopify-to-woo-importer'); ?></th>
                                <th><?php _e('Status', 'shopify-to-woo-importer'); ?></th>
                                <th class="column-actions"><?php _e('Aksi', 'shopify-to-woo-importer'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="stwi-file-list"></tbody>
                    </table>
        
                    <!-- Template untuk baris file -->
                    <script type="text/template" id="stwi-file-row-template">
                        <tr data-file-id="{%=fileId%}">
                            <td class="column-filename">
                                <strong>{%=fileName%}</strong>
                            </td>
                            <td class="column-progress">
                                <div class="stwi-progress">
                                    <div class="stwi-progress-bar" style="width: 0%"></div>
                                    <span class="stwi-progress-text">0%</span>
                                </div>
                            </td>
                            <td class="column-status">
                                <span class="stwi-status-badge stwi-status-pending">
                                    <?php _e('Menunggu', 'shopify-to-woo-importer'); ?>
                                </span>
                            </td>
                            <td class="column-actions">
                                <div class="stwi-file-controls">
                                    <button class="button stwi-start-import">
                                        <span class="dashicons dashicons-controls-play"></span>
                                        <?php _e('Mulai', 'shopify-to-woo-importer'); ?>
                                    </button>
                                    <button class="button stwi-pause-import" style="display:none">
                                        <span class="dashicons dashicons-controls-pause"></span>
                                        <?php _e('Jeda', 'shopify-to-woo-importer'); ?>
                                    </button>
                                    <button class="button stwi-resume-import" style="display:none">
                                        <span class="dashicons dashicons-controls-play"></span>
                                        <?php _e('Lanjut', 'shopify-to-woo-importer'); ?>
                                    </button>
                                    <button class="button stwi-delete-import">
                                        <span class="dashicons dashicons-trash"></span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </script>
                </div>
        
                <!-- Panel Statistik -->
                <div class="stwi-stats-panel">
                    <div class="stwi-stat-box">
                        <span class="dashicons dashicons-upload"></span>
                        <div class="stwi-stat-content">
                            <h3><?php _e('Total File', 'shopify-to-woo-importer'); ?></h3>
                            <span id="stwi-total-files">0</span>
                        </div>
                    </div>
                    <div class="stwi-stat-box">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <div class="stwi-stat-content">
                            <h3><?php _e('Berhasil', 'shopify-to-woo-importer'); ?></h3>
                            <span id="stwi-success-count">0</span>
                        </div>
                    </div>
                    <div class="stwi-stat-box">
                        <span class="dashicons dashicons-warning"></span>
                        <div class="stwi-stat-content">
                            <h3><?php _e('Error', 'shopify-to-woo-importer'); ?></h3>
                            <span id="stwi-error-count">0</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function get_import_status() {
        check_ajax_referer('stwi-nonce-action', 'nonce');
    
        $import_id = isset($_POST['import_id']) ? sanitize_text_field($_POST['import_id']) : '';
        if (!$import_id) {
            wp_send_json_error(array('message' => __('Invalid import ID', 'shopify-to-woo-importer')));
        }
    
        $import_data = get_option("stwi_import_{$import_id}");
        if (!$import_data) {
            wp_send_json_error(array('message' => __('Import data not found', 'shopify-to-woo-importer')));
        }
    
        wp_send_json_success($import_data);
    }

    public function get_all_imports() {
        check_ajax_referer('stwi-nonce-action', 'nonce');
        $imports = $this->importer->get_all_imports();
        wp_send_json_success($imports);
    }

    // public function delete_import() {
    //     // Validasi nonce
    //     if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'stwi-nonce-action')) {
    //         wp_send_json_error(array('message' => __('Nonce validation failed', 'shopify-to-woo-importer')));
    //         wp_die();
    //     }
    
    //     // Pastikan import_id ada
    //     $import_id = isset($_POST['import_id']) ? sanitize_text_field($_POST['import_id']) : '';
    //     if (!$import_id) {
    //         wp_send_json_error(array('message' => __('Invalid import ID', 'shopify-to-woo-importer')));
    //         wp_die();
    //     }
    
    //     // Hapus import
    //     $this->importer->delete_import($import_id);
    //     wp_send_json_success(array('message' => __('Import deleted successfully', 'shopify-to-woo-importer')));
    //     wp_die();
    // }
    // public function delete_import() {
    //     error_log("===== DEBUG DELETE IMPORT =====");
    //     error_log("Raw POST Data: " . print_r($_POST, true));
    
    //     if (!isset($_POST['nonce'])) {
    //         error_log("❌ ERROR: Nonce not set!");
    //         wp_send_json_error(array('message' => 'Nonce missing!'), 403);
    //         exit;
    //     }
    
    //     error_log("Nonce received: " . $_POST['nonce']);
    
    //     // Debug nonce verification
    //     if (!wp_verify_nonce($_POST['nonce'], 'stwi-nonce-action')) {
    //         error_log("❌ ERROR: Nonce validation failed!");
    //         wp_send_json_error(array('message' => 'Nonce validation failed!'), 403);
    //         exit;
    //     } else {
    //         error_log("✅ SUCCESS: Nonce is valid.");
    //     }
    
    //     // Debug user permissions
    //     $current_user = wp_get_current_user();
    //     error_log("User role: " . implode(', ', $current_user->roles));
    
    //     if (!current_user_can('manage_options')) {
    //         error_log("❌ ERROR: User does not have permission!");
    //         wp_send_json_error(array('message' => 'Insufficient permissions!'), 403);
    //         exit;
    //     } else {
    //         error_log("✅ SUCCESS: User has permission.");
    //     }
    
    //     // Ambil Import ID
    //     $import_id = isset($_POST['import_id']) ? sanitize_text_field($_POST['import_id']) : '';
    //     error_log("Import ID received: " . $import_id);
    
    //     if (!$import_id) {
    //         error_log("❌ ERROR: Invalid import ID!");
    //         wp_send_json_error(array('message' => 'Invalid import ID!'), 400);
    //         exit;
    //     }
    
    //     error_log("🗑️ Deleting import: " . $import_id);
    //     $this->importer->delete_import($import_id);
    //     error_log("✅ SUCCESS: Import deleted!");
    
    //     wp_send_json_success(array('message' => 'Import deleted successfully'));
    //     exit;
    // }
    
    
    
    
    

}