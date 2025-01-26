<?php
namespace STWI;

/**
 * Handles individual product processing and image management
 */
class ProductProcessor {
    private $csv_data = array();
    private $image_errors = 0;
    private $max_image_errors = 3;
    private $timeout = 30;
    private $chunk_size = 10; // Process products in chunks
    private $processed_count = 0;
    private $error_count = 0;
    
    // Add memory management
    private function check_memory() {
        $memory_limit = ini_get('memory_limit');
        $memory_usage = memory_get_usage(true);
        $memory_limit_bytes = wp_convert_hr_to_bytes($memory_limit);
        
        // If using more than 80% of memory limit, trigger garbage collection
        if ($memory_usage > ($memory_limit_bytes * 0.8)) {
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
            wp_cache_flush();
        }
    }

    /**
     * Process categories with proper error handling and parent-child relationships
     */
    private function process_categories($category_string) {
        $categories = array_filter(array_map('trim', explode('>', $category_string)));
        $category_ids = array();
        $parent_id = 0;

        foreach ($categories as $category_name) {
            try {
                $term = get_term_by('name', $category_name, 'product_cat');
                
                if (!$term) {
                    $args = array(
                        'description' => '',
                        'parent' => $parent_id
                    );
                    
                    $result = wp_insert_term($category_name, 'product_cat', $args);
                    
                    if (is_wp_error($result)) {
                        throw new \Exception($result->get_error_message());
                    }
                    
                    $category_ids[] = $result['term_id'];
                    $parent_id = $result['term_id'];
                } else {
                    $category_ids[] = $term->term_id;
                    $parent_id = $term->term_id;
                }
            } catch (\Exception $e) {
                stwi_error_handler('Category processing error', array(
                    'category' => $category_name,
                    'error' => $e->getMessage()
                ));
            }
        }

        return array_unique($category_ids);
    }

    /**
     * Process product variations
     */
    private function process_variations($product, $data) {
        try {
            // Konversi produk sederhana menjadi variabel jika belum
            if ($product->is_type('simple')) {
                $product = new \WC_Product_Variable($product->get_id());
            }
    
            // Ambil atribut variasi dari data CSV
            $attributes = array();
            for ($i = 1; $i <= 3; $i++) { // Mendukung hingga 3 opsi variasi
                if (!empty($data["Option{$i} Name"]) && !empty($data["Option{$i} Value"])) {
                    $attribute_name = wc_sanitize_taxonomy_name($data["Option{$i} Name"]);
                    $attribute_values = array_map('trim', explode('|', $data["Option{$i} Value"]));
                    $attributes[$attribute_name] = array(
                        'name' => $attribute_name,
                        'value' => implode('|', $attribute_values),
                        'visible' => true,
                        'variation' => true
                    );
                }
            }
    
            // Set atribut ke produk
            $product->set_attributes($attributes);
            $product->save();
    
            // Buat variasi berdasarkan kombinasi atribut
            if (!empty($data['Variant SKU'])) {
                $variation = new \WC_Product_Variation();
                $variation->set_parent_id($product->get_id());
                $variation->set_sku($data['Variant SKU']);
                $variation->set_regular_price($data['Variant Price']);
                
                if (!empty($data['Variant Compare At Price'])) {
                    $variation->set_sale_price($data['Variant Price']);
                    $variation->set_regular_price($data['Variant Compare At Price']);
                }
    
                // Set atribut variasi
                $variation_attributes = array();
                foreach ($attributes as $attribute_name => $attribute) {
                    if (!empty($data["Option1 Value"])) {
                        $variation_attributes["attribute_" . $attribute_name] = $data["Option1 Value"];
                    }
                }
    
                $variation->set_attributes($variation_attributes);
                $variation->save();
            }
        } catch (\Exception $e) {
            stwi_error_handler('Variation processing error', array(
                'product_id' => isset($product) ? $product->get_id() : null,
                'error' => $e->getMessage()
            ));
        }
    }

    //Image Validation and Processing
    //V2
    private function process_product_images($product, $data) {
        try {
            $image_urls = array();
            $current_handle = $data['Handle'];
            
            // Kumpulkan semua URL gambar untuk produk ini
            foreach ($this->csv_data as $row) {
                if ($row['Handle'] === $current_handle && !empty($row['Image Src'])) {
                    $image_urls[] = array(
                        'url' => $row['Image Src'],
                        'position' => (int)$row['Image Position']
                    );
                }
            }
            
            // Urutkan gambar berdasarkan posisi
            usort($image_urls, function($a, $b) {
                return $a['position'] - $b['position'];
            });
            
            // Validasi dan proses gambar
            if (!empty($image_urls)) {
                // Set gambar utama
                $main_image = array_shift($image_urls);
                $attachment_id = $this->upload_product_image($main_image['url']);
                if ($attachment_id) {
                    $product->set_image_id($attachment_id);
                }
                
                // Set gambar galeri
                $gallery_ids = array();
                foreach ($image_urls as $image) {
                    $attachment_id = $this->upload_product_image($image['url']);
                    if ($attachment_id) {
                        $gallery_ids[] = $attachment_id;
                    }
                }
                if (!empty($gallery_ids)) {
                    $product->set_gallery_image_ids($gallery_ids);
                }
            }
            
            return true;
        } catch (\Exception $e) {
            stwi_error_handler('Image processing error', array(
                'product_id' => $product->get_id(),
                'error' => $e->getMessage()
            ));
            return false;
        }
    }
    
    
    private function validate_image_url($url) {
        if (empty($url)) return false;
        
        $response = wp_remote_head($url);
        if (is_wp_error($response)) return false;
        
        $response_code = wp_remote_retrieve_response_code($response);
        return $response_code === 200;
    }
    
    private function upload_product_image($url) {
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
    
        // Download gambar
        $tmp = download_url($url);
        if (is_wp_error($tmp)) {
            return false;
        }
    
        $file_array = array(
            'name' => basename($url),
            'tmp_name' => $tmp
        );
    
        // Upload ke media library
        $id = media_handle_sideload($file_array, 0);
        if (is_wp_error($id)) {
            @unlink($file_array['tmp_name']);
            return false;
        }
    
        return $id;
    }
    
    
    //end image validation and processing

    public function set_csv_data($data){
        $this->csv_data = $data;
    }
    

    public function process_product($data) {
        try {
            // Validate data
            $validation = $this->validate_product_data($data);
            if ($validation !== true) {
                stwi_error_handler('Product validation failed', $validation);
                return false;
            }
            
            // Create or update product
            $product = new \WC_Product_Simple();
            
            // Set basic product data
            $product->set_name($data['Title']);
            $product->set_status('publish'); // Default status
            
            // Process images first - ini akan set status ke draft jika ada gambar invalid
            $this->process_product_images($product, $data);
            
            // Lanjutkan dengan data produk lainnya
            if (!empty($data['Handle'])) {
                $product->set_slug($data['Handle']);
            }
            
            if (!empty($data['Body (HTML)'])) {
                $product->set_description($data['Body (HTML)']);
            }
            
            if (!empty($data['Variant SKU'])) {
                $product->set_sku($data['Variant SKU']);
            }
            
            // Set prices
            if (!empty($data['Variant Price'])) {
                $product->set_regular_price($data['Variant Price']);
                if (!empty($data['Variant Compare At Price'])) {
                    $product->set_sale_price($data['Variant Price']);
                    $product->set_regular_price($data['Variant Compare At Price']);
                }
            }
            
            // Process categories
            if (!empty($data['Type'])) {
                $category_ids = $this->process_categories($data['Type']);
                if (!empty($category_ids)) {
                    $product->set_category_ids($category_ids);
                }
            }
            
            // Handle variations if present
            if (!empty($data['Option1 Name'])) {
                $this->process_variations($product, $data);
            }
            
            // Save product
            $product_id = $product->save();
            if (!$product_id) {
                throw new \Exception('Failed to save product');
            }
            
            return true;
        } catch (\Exception $e) {
            stwi_error_handler('Product processing error', array(
                'data' => $data,
                'error' => $e->getMessage()
            ));
            return false;
        }
    }



    /**
     * Handle bulk processing with progress tracking
     */
    public function bulk_process($products) {
        $total = count($products);
        $chunks = array_chunk($products, $this->chunk_size);
        $progress = 0;
        
        foreach ($chunks as $chunk) {
            foreach ($chunk as $product_data) {
                $this->check_memory();
                
                if ($this->process_product($product_data)) {
                    $this->processed_count++;
                } else {
                    $this->error_count++;
                }
                
                $progress++;
                
                // Update progress in options table
                update_option('stwi_import_progress', array(
                    'total' => $total,
                    'processed' => $progress,
                    'success' => $this->processed_count,
                    'errors' => $this->error_count
                ));
            }
            
            // Small pause between chunks to prevent server overload
            sleep(1);
        }
        
        return array(
            'processed' => $this->processed_count,
            'errors' => $this->error_count
        );
    }

    /**
     * Validate product data before processing
     */
    private function validate_product_data($data) {
        $required_fields = array(
            'Handle', 
            'Title', 
            'Variant Price',
            'Image Src' // Tambahkan validasi untuk gambar
        );
        $errors = array();
        
        foreach ($required_fields as $field) {
            if (empty($data[$field])) {
                $errors[] = "Missing required field: {$field}";
            }
        }
        
        return empty($errors) ? true : $errors;
    }

    /**
     * Clean up temporary files and reset counters
     */
    public function cleanup() {
        // Clean up temp directory
        $upload_dir = wp_upload_dir();
        $temp_dir = $upload_dir['basedir'] . '/stwi-temp';
        
        if (is_dir($temp_dir)) {
            $files = glob($temp_dir . '/*');
            foreach ($files as $file) {
                if (is_file($file) && time() - filemtime($file) > 3600) {
                    @unlink($file);
                }
            }
        }
        
        // Reset counters
        $this->processed_count = 0;
        $this->error_count = 0;
        $this->image_errors = 0;
    }
}