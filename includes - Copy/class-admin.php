<?php
namespace STWI;

class Admin {
    private $importer;
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // AJAX handlers
        add_action('wp_ajax_stwi_upload_csv', array($this, 'handle_file_upload'));
        add_action('wp_ajax_stwi_process_batch', array($this, 'process_batch'));
        add_action('wp_ajax_stwi_get_progress', array($this, 'get_progress'));
        
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
        // Periksa apakah kita berada di halaman admin yang benar
        if ('woocommerce_page_shopify-importer' !== $hook) {
            return;
        }
        
        // Enqueue stylesheet untuk halaman admin
        wp_enqueue_style('stwi-admin', STWI_PLUGIN_URL . 'assets/admin.css', array(), STWI_VERSION);
        
        // Enqueue Dropzone dari CDN atau file lokal
        wp_enqueue_script('dropzone', 'https://unpkg.com/dropzone@5/dist/min/dropzone.min.js', array(), '5.9.3', true);
        
        // Enqueue skrip admin Anda, pastikan Dropzone sudah dimuat sebelum ini
        wp_enqueue_script('stwi-admin', STWI_PLUGIN_URL . 'assets/admin.js', array('jquery', 'dropzone'), STWI_VERSION, true);
        
        // Localize script untuk mengirimkan parameter ke JavaScript
        wp_localize_script('stwi-admin', 'stwi_params', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('stwi-import'),
            'max_file_size' => wp_max_upload_size(),
            'strings' => array(
                'dropzone_message' => __('Drop Shopify CSV file here or click to upload', 'shopify-to-woo-importer'),
                'processing' => __('Processing...', 'shopify-to-woo-importer'),
                'complete' => __('Import complete!', 'shopify-to-woo-importer'),
                'error' => __('Error occurred', 'shopify-to-woo-importer')
            )
        ));
    }
    
    public function handle_file_upload() {
        check_ajax_referer('stwi-import', 'nonce');
    
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied', 'shopify-to-woo-importer')));
        }
    
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
    
    
    
    public function process_batch() {
        check_ajax_referer('stwi-import', 'nonce');
    
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied', 'shopify-to-woo-importer')));
        }
    
        // Ambil import_id dari permintaan POST
        $import_id = isset($_POST['import_id']) ? sanitize_text_field($_POST['import_id']) : '';
        
        // Cek apakah import_id valid
        if (!$import_id) {
            wp_send_json_error(array('message' => __('Invalid import ID', 'shopify-to-woo-importer')));
        }
    
        // Proses batch menggunakan import_id
        $result = $this->importer->process_batch($import_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success($result);
    }
    
    
    public function get_progress() {
        check_ajax_referer('stwi-import', 'nonce');
        
        $import_id = isset($_POST['import_id']) ? sanitize_text_field($_POST['import_id']) : '';
        if (!$import_id) {
            wp_send_json_error(array('message' => __('Invalid import ID', 'shopify-to-woo-importer')));
        }
        
        $progress = $this->importer->get_progress($import_id);
        wp_send_json_success($progress);
    }
    
    public function render_admin_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Shopify Importer', 'shopify-to-woo-importer'); ?></h1>
            
            <div class="stwi-container">
                <div class="stwi-header">
                    <p><?php _e('Upload your Shopify products CSV file to import products into WooCommerce.', 'shopify-to-woo-importer'); ?></p>
                </div>
                
                <form id="stwi-upload-form" class="stwi-dropzone" method="post" enctype="multipart/form-data">
                    <?php wp_nonce_field('stwi-import', 'stwi_nonce'); ?>
                </form>
                
                <div class="stwi-progress" style="display: none;">
                    <div class="stwi-progress-bar"></div>
                </div>
                
                <div class="stwi-stats">
                    <div class="stwi-stat-box">
                        <h3><?php _e('Progress', 'shopify-to-woo-importer'); ?></h3>
                        <p><span id="stwi-processed">0</span> / <span id="stwi-total">0</span></p>
                    </div>
                    <div class="stwi-stat-box">
                        <h3><?php _e('Successful', 'shopify-to-woo-importer'); ?></h3>
                        <p id="stwi-success">0</p>
                    </div>
                    <div class="stwi-stat-box">
                        <h3><?php _e('Errors', 'shopify-to-woo-importer'); ?></h3>
                        <p id="stwi-errors">0</p>
                    </div>
                </div>
                
                <div class="stwi-status" style="display: none;"></div>
                <div class="stwi-log-viewer" style="display: none;"></div>
                
                <div class="stwi-controls">
                    <button id="stwi-cancel-import" class="button" style="display: none;">
                        <?php _e('Cancel Import', 'shopify-to-woo-importer'); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }
}