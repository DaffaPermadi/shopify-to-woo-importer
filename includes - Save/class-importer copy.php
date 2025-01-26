<?php
namespace STWI;

class Importer {
    private $processor;
    private $csv_data = array();
    private $batch_size = 10;
    
    public function __construct() {
        $this->processor = new ProductProcessor();
    }
    
    public function init_import($filepath) {
        if (!file_exists($filepath)) {
            error_log('File tidak ditemukan: ' . $filepath);
            return false;
        }
        
        try {
            // Bersihkan produk invalid yang mungkin ada
            $this->cleanup_invalid_products();
            
            // Load dan validasi CSV
            $csv_data = $this->load_csv_data($filepath);
            if (empty($csv_data)) {
                throw new \Exception('Data CSV kosong');
            }
            
            // Set data ke processor
            $this->processor->set_csv_data($csv_data);
            
            // Generate unique import ID
            $import_id = uniqid('import_');
            
            // Simpan data import
            $import_data = array(
                'filepath' => $filepath,
                'total_rows' => count($csv_data),
                'processed' => 0,
                'success' => 0,
                'errors' => 0,
                'status' => 'pending',
                'started_at' => current_time('mysql'),
                'memory_start' => memory_get_usage(true)
            );
            
            update_option("stwi_import_{$import_id}", $import_data);
            return $import_id;
            
        } catch (\Exception $e) {
            error_log('Import initialization failed: ' . $e->getMessage());
            return false;
        }
    }
    
    private function load_csv_data($filepath) {
        if (!is_readable($filepath)) {
            throw new \Exception('File tidak dapat dibaca');
        }
        
        $handle = fopen($filepath, 'r');
        if (!$handle) {
            throw new \Exception('Gagal membuka file CSV');
        }
        
        $headers = fgetcsv($handle);
        if (!$headers) {
            fclose($handle);
            throw new \Exception('Gagal membaca header CSV');
        }
        
        $data = array();
        while (($row = fgetcsv($handle)) !== FALSE) {
            if (count($headers) === count($row)) {
                $data[] = array_combine($headers, $row);
            }
        }
        
        fclose($handle);
        return $data;
    }
    
    public function process_batch($import_id) {
        $import_data = get_option("stwi_import_{$import_id}");
        if (!$import_data) {
            return new \WP_Error('invalid_import', 'Import tidak valid');
        }
        
        // Reset processor untuk batch baru
        $this->processor->reset_processed_handles();
        
        // Load CSV data jika belum
        if (empty($this->csv_data)) {
            try {
                $this->csv_data = $this->load_csv_data($import_data['filepath']);
            } catch (\Exception $e) {
                return new \WP_Error('csv_load_error', $e->getMessage());
            }
        }
        
        // Ambil batch produk yang akan diproses
        $batch = array_slice(
            $this->csv_data,
            $import_data['processed'],
            $this->batch_size
        );
        
        if (empty($batch)) {
            $import_data['status'] = 'completed';
            update_option("stwi_import_{$import_id}", $import_data);
            return array(
                'completed' => true,
                'processed' => $import_data['processed'],
                'total' => $import_data['total_rows'],
                'success' => $import_data['success'],
                'errors' => $import_data['errors']
            );
        }
        
        // Proses batch
        $processed = 0;
        $success = 0;
        $errors = 0;
        
        foreach ($batch as $product_data) {
            if ($this->processor->process_product($product_data)) {
                $success++;
            } else {
                $errors++;
            }
            $processed++;
        }
        
        // Update progress
        $import_data['processed'] += $processed;
        $import_data['success'] += $success;
        $import_data['errors'] += $errors;
        
        if ($import_data['processed'] >= $import_data['total_rows']) {
            $import_data['status'] = 'completed';
        }
        
        update_option("stwi_import_{$import_id}", $import_data);
        
        return array(
            'completed' => $import_data['status'] === 'completed',
            'processed' => $import_data['processed'],
            'total' => $import_data['total_rows'],
            'success' => $import_data['success'],
            'errors' => $import_data['errors']
        );
    }
    
    private function cleanup_invalid_products() {
        global $wpdb;
        
        $invalid_products = $wpdb->get_results(
            "SELECT ID FROM {$wpdb->posts} 
            WHERE post_type = 'product' 
            AND post_title = 'products'
            ORDER BY ID ASC"
        );
        
        if (!empty($invalid_products)) {
            foreach ($invalid_products as $product) {
                wp_delete_post($product->ID, true);
            }
        }
    }
    
    public function get_progress($import_id) {
        $import_data = get_option("stwi_import_{$import_id}");
        if (!$import_data) {
            return false;
        }
        
        return array(
            'processed' => $import_data['processed'],
            'total' => $import_data['total_rows'],
            'success' => $import_data['success'],
            'errors' => $import_data['errors'],
            'completed' => $import_data['status'] === 'completed'
        );
    }
}
