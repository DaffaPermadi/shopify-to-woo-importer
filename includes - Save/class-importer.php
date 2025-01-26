<?php
namespace STWI;

class Importer {
    private $processor;
    private $batch_size = 5;
    private $csv_data = array();
    private $memory_limit;
    private $start_time;
    
    public function __construct() {
        $this->processor = new ProductProcessor();
    }

    private function load_csv_data($filepath) {
        $handle = fopen($filepath, 'r');
        if (!$handle) {
            throw new \Exception('Failed to open CSV file');
        }
        
        $headers = fgetcsv($handle);
        if (!$headers) {
            fclose($handle);
            throw new \Exception('Failed to read CSV headers');
        }
        
        $data = array();
        while (($row = fgetcsv($handle)) !== FALSE) {
            if (count($headers) === count($row)) {
                $data[] = array_combine($headers, $row);
            }
        }
        
        fclose($handle);
        $this->processor->set_csv_data($data);
        return $data;
    }
    
    public function init_import($filepath) {
        if (!file_exists($filepath)) {
            return false;
        }
        
        try {
            // Load dan validasi CSV
            $csv_data = $this->load_csv_data($filepath);
            if (empty($csv_data)) {
                throw new \Exception('No data found in CSV file');
            }
            
            // Set data ke processor
            $this->processor->set_csv_data($csv_data);
            
            // Generate ID impor
            $import_id = uniqid('import_');
            
            // Simpan data impor
            update_option("stwi_import_{$import_id}", array(
                'filepath' => $filepath,
                'total_rows' => count($csv_data),
                'processed' => 0,
                'success' => 0,
                'errors' => 0,
                'status' => 'pending',
                'started_at' => current_time('mysql'),
                'memory_start' => memory_get_usage(true)
            ));
            
            return $import_id;
            
        } catch (\Exception $e) {
            stwi_error_handler('Import initialization failed', array(
                'error' => $e->getMessage(),
                'file' => $filepath
            ));
            return false;
        }
    }
    
    
    public function process_batch($import_id) {
        $import_data = get_option("stwi_import_{$import_id}");
        if (!$import_data) {
            return new \WP_Error('invalid_import', 'Import tidak valid');
        }
        
        // Load CSV data jika belum
        if (empty($this->csv_data)) {
            $this->csv_data = $this->load_csv_data($import_data['filepath']);
        }
        
        // Ambil batch produk yang akan diproses
        $batch = array_slice(
            $this->csv_data, 
            $import_data['processed'], 
            $this->batch_size
        );
        
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