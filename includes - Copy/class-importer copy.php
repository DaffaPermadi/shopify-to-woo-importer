<?php
namespace STWI;

class Importer {
    private $processor;
    private $batch_size = 5; // Process 5 products at a time
    private $csv_data = array();
    
    public function __construct() {
        $this->processor = new ProductProcessor();
    }
    
    public function init_import($filepath) {
        if (!file_exists($filepath)) {
            return false;
        }
        
        // Read CSV file
        $handle = fopen($filepath, 'r');
        if (!$handle) {
            return false;
        }
        
        // Get headers
        $headers = fgetcsv($handle);
        if (!$headers) {
            fclose($handle);
            return false;
        }
        
        // Validate required columns
        $required_columns = array('Handle', 'Title', 'Variant Price');
        $missing_columns = array_diff($required_columns, $headers);
        
        if (!empty($missing_columns)) {
            fclose($handle);
            stwi_error_handler('Missing required columns', $missing_columns);
            return false;
        }
        
        // Count total rows
        $total_rows = 0;
        while (fgetcsv($handle)) {
            $total_rows++;
        }
        fclose($handle);
        
        // Generate unique import ID
        $import_id = uniqid('import_');
        
        // Store import data in WordPress options
        update_option("stwi_import_{$import_id}", array(
            'filepath' => $filepath,
            'total_rows' => $total_rows,
            'processed' => 0,
            'success' => 0,
            'errors' => 0,
            'status' => 'pending',
            'headers' => $headers,
            'started_at' => current_time('mysql')
        ));
        
        return $import_id;
    }
    
    public function process_batch($import_id) {
        // Ambil data impor berdasarkan ID
        $import_data = get_option("stwi_import_{$import_id}");
        
        // Cek apakah data impor valid
        if (!$import_data) {
            return new \WP_Error('invalid_import', __('Invalid import ID', 'shopify-to-woo-importer'));
        }
        
        // Jika status sudah completed, kembalikan hasil
        if ($import_data['status'] === 'completed') {
            return array(
                'completed' => true,
                'processed' => $import_data['processed'],
                'total' => $import_data['total_rows'],
                'success' => $import_data['success'],
                'errors' => $import_data['errors']
            );
        }
        
        // Buka file CSV untuk dibaca
        $handle = fopen($import_data['filepath'], 'r');
        if (!$handle) {
            return new \WP_Error('file_error', __('Failed to open import file', 'shopify-to-woo-importer'));
        }
        
        // Lewati baris header
        fgetcsv($handle);
        
        // Lewati baris yang sudah diproses
        for ($i = 0; $i < $import_data['processed']; $i++) {
            fgetcsv($handle);
        }
        
        // Proses batch
        $processed_in_batch = 0;
        $success_in_batch = 0;
        $errors_in_batch = 0;
        
        while ($processed_in_batch < $this->batch_size && ($row = fgetcsv($handle)) !== FALSE) {
            // Gabungkan data produk dengan header
            $product_data = array_combine($import_data['headers'], $row);
            
            // Proses produk dan hitung sukses atau kesalahan
            if ($this->processor->process_product($product_data)) {
                $success_in_batch++;
            } else {
                $errors_in_batch++;
            }
            
            $processed_in_batch++;
        }
        
        fclose($handle);
        
        // Perbarui kemajuan impor
        $import_data['processed'] += $processed_in_batch;
        $import_data['success'] += $success_in_batch;
        $import_data['errors'] += $errors_in_batch;
        
        // Cek apakah semua baris telah diproses
        if ($import_data['processed'] >= $import_data['total_rows']) {
            $import_data['status'] = 'completed';
        }
        
        // Simpan kembali data impor ke opsi WordPress
        update_option("stwi_import_{$import_id}", $import_data);
        
        return array(
            'completed' => $import_data['status'] === 'completed',
            'processed' => $import_data['processed'],
            'total' => $import_data['total_rows'],
            'success' => $import_data['success'],
            'errors' => $import_data['errors']
        );
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