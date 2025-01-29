<?php
namespace STWI;

class Importer {
    private $processor;
    private $batch_size = 10;
    private $csv_data = array();
    private $memory_limit;
    private $start_time;

    public function __construct() {
        // Inisialisasi processor
        $this->processor = new ProductProcessor();

        // Daftarkan hook untuk batch processing via Action Scheduler
        add_action('process_import_batch', array($this, 'process_scheduled_batch'));

        //AJAX handler
        add_action('wp_ajax_stwi_delete_import', array($this, 'delete_import'));
        add_action('wp_ajax_stwi_start_import', array($this, 'start_import'));

        
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
        while (($row = fgetcsv($handle)) !== false) {
            if (count($headers) === count($row)) {
                $data[] = array_combine($headers, $row);
            }
        }

        fclose($handle);
        $this->processor->set_csv_data($data);
        return $data;
    }

    // public function init_import($filepath) {
    //     global $wpdb;
        
    //     try {
    //         $csv_data = $this->load_csv_data($filepath);
    //         if (empty($csv_data)) {
    //             throw new \Exception('No data found in CSV file');
    //         }

    //         $import_id = uniqid('import_');
    //         $table_name = $wpdb->prefix . 'stwi_imports';

    //         $wpdb->insert($table_name, array(
    //             'import_id' => $import_id,
    //             'filepath' => $filepath,
    //             'total_rows' => count($csv_data),
    //             'created_at' => current_time('mysql'),
    //             'updated_at' => current_time('mysql')
    //         ));

    //         $this->schedule_import($import_id);
    //         return $import_id;
    //     } catch (\Exception $e) {
    //         error_log('STWI Error: ' . $e->getMessage());
    //         return false;
    //     }
    // }
    public function init_import($filepath) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'stwi_imports';
        
        try {
            $csv_data = $this->load_csv_data($filepath);
            if (empty($csv_data)) {
                throw new \Exception('Data CSV kosong');
            }
    
            $import_id = uniqid('import_');
            $filename = basename($filepath);
            
            $wpdb->insert($table_name, array(
                'import_id' => $import_id,
                'filename' => $filename,
                'filepath' => $filepath,
                'total_rows' => count($csv_data),
                'status' => 'pending',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ));
    
            $this->schedule_import($import_id);
            return $import_id;
        } catch (\Exception $e) {
            error_log('STWI Error: ' . $e->getMessage());
            return false;
        }
    }
    
    

    // Metode untuk menjadwalkan batch
    public function schedule_import($import_id) {
        if (!function_exists('as_schedule_single_action')) {
            return false;
        }
    
        // Jadwalkan batch pertama
        as_schedule_single_action(
            time(), // Waktu eksekusi
            'process_import_batch', // Nama hook
            array('import_id' => $import_id), // Parameter
            'stwi_import' // Group
        );
    
        return true;
    }

    public function process_batch($import_id) {
        $import_data = get_option("stwi_import_{$import_id}");
        if (!$import_data) {
            return new \WP_Error('invalid_import', 'Import tidak valid');
        }

        // Muat CSV data jika belum tersedia di properti class
        if (empty($this->csv_data)) {
            $this->csv_data = $this->load_csv_data($import_data['filepath']);
        }

        // Ambil batch produk
        $batch = array_slice(
            $this->csv_data,
            $import_data['processed'],
            $this->batch_size
        );

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

        // Update progres
        $import_data['processed'] += $processed;
        $import_data['success'] += $success;
        $import_data['errors'] += $errors;

        // Tandai completed jika sudah selesai
        if ($import_data['processed'] >= $import_data['total_rows']) {
            $import_data['status'] = 'completed';
        }

        update_option("stwi_import_{$import_id}", $import_data);

        return array(
            'completed' => $import_data['status'] === 'completed',
            'processed' => $import_data['processed'],
            'total'     => $import_data['total_rows'],
            'success'   => $import_data['success'],
            'errors'    => $import_data['errors']
        );
    }

    // Callback yang dipanggil Action Scheduler
    public function process_scheduled_batch($import_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'stwi_imports';
        
        $import = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE import_id = %s",
            $import_id
        ));
        
        if (!$import || !file_exists($import->filepath)) {
            error_log("STWI Error: Import {$import_id} not found or file missing");
            return;
        }
        
        try {
            if (empty($this->csv_data)) {
                $this->csv_data = $this->load_csv_data($import->filepath);
            }
        
            $batch = array_slice(
                $this->csv_data,
                $import->processed,
                $this->batch_size
            );
        
            $processed = 0;
            $success = 0;
            $errors = 0;
        
            foreach ($batch as $product_data) {
                $result = $this->processor->process_product($product_data);
                
                if ($result === true) {
                    $success++;
                } else {
                    $errors++;
                    error_log("STWI Error processing product: " . print_r($product_data, true));
                }
                $processed++;
            }
        
            $new_processed = $import->processed + $processed;
            $new_success = $import->success + $success;
            $new_errors = $import->errors + $errors;
            $new_status = ($new_processed >= $import->total_rows) ? 'completed' : 'processing';
        
            $wpdb->update($table_name,
                array(
                    'processed' => $new_processed,
                    'success' => $new_success,
                    'errors' => $new_errors,
                    'status' => $new_status,
                    'updated_at' => current_time('mysql')
                ),
                array('import_id' => $import_id),
                array('%d', '%d', '%d', '%s', '%s'),
                array('%s')
            );
        
            if ($new_status !== 'completed') {
                as_schedule_single_action(
                    time() + 10,
                    'process_import_batch',
                    array('import_id' => $import_id),
                    'stwi_import'
                );
            } else {
                @unlink($import->filepath);
            }
        
        } catch (\Exception $e) {
            $wpdb->update($table_name,
                array(
                    'status' => 'failed',
                    'updated_at' => current_time('mysql')
                ),
                array('import_id' => $import_id)
            );
            error_log("STWI Critical Error: " . $e->getMessage());
        }
    }

    public function get_progress($import_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'stwi_imports';
        
        $import = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE import_id = %s",
            $import_id
        ));

        if (!$import) return false;

        return array(
            'processed' => $import->processed,
            'total' => $import->total_rows,
            'success' => $import->success,
            'errors' => $import->errors,
            'completed' => $import->status === 'completed'
        );
    }

    public function get_all_imports() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'stwi_imports';
        
        return $wpdb->get_results(
            "SELECT * FROM $table_name ORDER BY created_at DESC",
            ARRAY_A
        );
    }
    
    public function delete_import() {
        check_ajax_referer('stwi-nonce-action', 'nonce');
    
        if ( ! current_user_can('manage_woocommerce') ) {
            wp_send_json_error( ['message' => 'Permission denied'] );
        }
    
        // Ambil import_id dari $_POST
        if ( empty($_POST['import_id']) ) {
            wp_send_json_error(['message' => 'Invalid import ID']);
        }
    
        $import_id = sanitize_text_field($_POST['import_id']);
    
        global $wpdb;
        $table_name = $wpdb->prefix . 'stwi_imports';
    
        // Cek apakah ada record di DB
        $import = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table_name WHERE import_id = %s",
            $import_id
        ) );
    
        if ( ! $import ) {
            wp_send_json_error(['message' => 'Import data not found']);
        }
    
        // Hapus file fisik
        if ( file_exists( $import->filepath ) ) {
            @unlink( $import->filepath );
        }
    
        // Hapus data di database
        $wpdb->delete( $table_name, ['import_id' => $import_id], ['%s'] );
    
        wp_send_json_success(['message' => 'Import deleted successfully']);
    }
    
    
    public function start_import($import_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'stwi_imports';
        
        return $wpdb->update(
            $table_name,
            array(
                'status' => 'processing',
                'updated_at' => current_time('mysql')
            ),
            array('import_id' => $import_id),
            array('%s', '%s'),
            array('%s')
        );
    }

}
